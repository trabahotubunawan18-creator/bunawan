<?php
// Red-flag checker and posting limits for job posts.
require_once __DIR__ . '/helpers.php';

foreach (['FREE_ACTIVE_LIMIT' => 3, 'PREMIUM_ACTIVE_LIMIT' => 10, 'DAILY_POST_LIMIT' => 5, 'MAX_DEADLINE_DAYS' => 60,
          'REPORT_HIDE_AT' => 3, 'FLAG_STRIKES' => 3, 'HIGH_MONTHLY_SALARY' => 40000] as $k => $v) if (!defined($k)) define($k, $v);

const FLAG_MESSAGES = [
    'fees'      => 'Mentions a fee or payment that applicants must make.',
    'payment'   => 'Mentions a GCash, Maya, bank, or account number.',
    'overseas'  => 'Mentions overseas or abroad work. Jobs here must be in Bunawan.',
    'offsite'   => 'Asks applicants to move to Telegram, WhatsApp, Viber, or a shortened link.',
    'sensitive' => 'Asks for sensitive details such as an ATM card, PIN, OTP, or ID.',
    'toogood'   => 'Promises easy or guaranteed income.',
    'salary'    => 'The salary looks unusually high for a local job.',
    'vague'     => 'The description is very short or looks like spam (all capitals or many exclamation marks).',
    'keyword'   => 'Contains a word that our team watches for.',
    'reported'  => 'Reported by several job seekers and hidden until an admin reviews it.',
];

function monthly_equivalent(?float $amount, string $period): float {
    $amount = (float)$amount;
    return match ($period) { 'day' => $amount * 26, 'week' => $amount * 4.33, 'hour' => $amount * 8 * 26, 'month' => $amount, default => 0.0 };
}

// Returns [code => ['msg' => string, 'matches' => [string, ...]]]. An empty array means the post looks clean.
function redflag_check(array $j): array {
    $text = implode("\n", [$j['title'] ?? '', $j['description'] ?? '', $j['responsibilities'] ?? '', $j['qualifications'] ?? '', $j['work_address'] ?? '']);
    $rules = [
        'fees' => '~\b(?:registration|placement|training|processing|application|membership|reservation|starter\s*kit)\s+fees?\b|\b(?:down\s*payment|pay\s*first|bayad\s*muna|pay\s+to\s+apply|security\s+deposit|deposit)\b|\bsend\s+(?:money|payment|load)\b~iu',
        'payment' => '~\b(?:gcash|g-cash|paymaya|maya\s+(?:account|number|wallet)|paypal|bank\s+account|account\s+(?:number|no\.?)|remittance|western\s+union|coins\.ph)\b|\b(?!09|63)\d{12,16}\b~iu',
        'overseas' => '~\b(?:abroad|overseas|ofw|visa|dubai|singapore|saudi|qatar|kuwait|taiwan|hong\s*kong|japan|korea|canada|australia|europe|cruise\s+ship)\b~iu',
        'offsite' => '~(?:t\.me/|telegram|whatsapp|wa\.me|viber|bit\.ly|tinyurl|linktr\.ee|message\s+me\s+(?:on|via)|dm\s+me)~iu',
        'sensitive' => '~\b(?:atm\s+card|pin\s+code|otp|one[-\s]?time\s+password|send\s+(?:your\s+)?(?:id|selfie|bank))\b~iu',
        'toogood' => '~\b(?:guaranteed\s+(?:income|salary)|easy\s+money|get\s+rich|earn\s+big|passive\s+income|work\s+from\s+home)\b~iu',
    ];
    $flags = [];
    foreach ($rules as $code => $rx) {
        if (preg_match_all($rx, $text, $m) && $m[0]) {
            $flags[$code] = ['msg' => FLAG_MESSAGES[$code], 'matches' => array_values(array_unique(array_map('mb_strtolower', $m[0])))];
        }
    }
    // Words the admin added in Settings (comma separated)
    $seen = [];
    foreach ($flags as $f) foreach ($f['matches'] as $mm) $seen[] = $mm;
    $extra = [];
    foreach (array_filter(array_map('trim', explode(',', mb_strtolower(setting('redflag_keywords', ''))))) as $kw) {
        if ($kw === '' || mb_stripos($text, $kw) === false) continue;
        $dup = false;
        foreach ($seen as $s) if (mb_stripos($s, $kw) !== false || mb_stripos($kw, $s) !== false) $dup = true;
        if (!$dup) $extra[] = $kw;
    }
    if ($extra) $flags['keyword'] = ['msg' => FLAG_MESSAGES['keyword'], 'matches' => $extra];

    if (($j['salary_period'] ?? 'negotiable') !== 'negotiable') {
        $top = max((float)($j['salary_max'] ?? 0), (float)($j['salary_min'] ?? 0));
        $mo = monthly_equivalent($top, $j['salary_period']);
        if ($mo > HIGH_MONTHLY_SALARY) $flags['salary'] = ['msg' => FLAG_MESSAGES['salary'], 'matches' => ['about ₱' . number_format($mo) . ' per month']];
    }
    $vague = [];
    $desc = trim($j['description'] ?? '');
    if (mb_strlen($desc) < 80) $vague[] = 'short description';
    $letters = preg_match_all('/\p{L}/u', $text);
    $upper = preg_match_all('/\p{Lu}/u', $text);
    if ($letters >= 30 && $upper / $letters > 0.6) $vague[] = 'mostly capital letters';
    if (substr_count($text, '!') >= 4) $vague[] = 'many exclamation marks';
    if ($vague) $flags['vague'] = ['msg' => FLAG_MESSAGES['vague'], 'matches' => $vague];
    return $flags;
}

function flag_codes_to_text(?string $codes): array {
    $out = [];
    foreach (array_filter(explode(',', (string)$codes)) as $c) if (isset(FLAG_MESSAGES[$c])) $out[$c] = FLAG_MESSAGES[$c];
    return $out;
}

// Limits and counters for one employer
function employer_post_limits(PDO $pdo, int $uid): array {
    $q = $pdo->prepare("SELECT COUNT(*) FROM premium_subscription_jobs psj JOIN premium_subscriptions ps ON ps.id = psj.subscription_id WHERE ps.employer_id = ? AND ps.status = 'active' AND ps.ends_on >= CURRENT_DATE");
    $q->execute([$uid]); $premium = (int)$q->fetchColumn() > 0;
    $q = $pdo->prepare("SELECT COUNT(*) FROM job_posts WHERE employer_id = ? AND status IN ('approved','pending') AND deadline >= CURRENT_DATE");
    $q->execute([$uid]); $active = (int)$q->fetchColumn();
    $q = $pdo->prepare('SELECT COUNT(*) FROM job_posts WHERE employer_id = ? AND created_at >= CURRENT_DATE');
    $q->execute([$uid]); $today = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM job_posts WHERE employer_id = ? AND red_flags IS NOT NULL AND red_flags <> '' AND red_flags NOT LIKE '%reported%'
        AND status IN ('pending','rejected') AND created_at >= (NOW() - INTERVAL '30 days')");
    $q->execute([$uid]); $strikes = (int)$q->fetchColumn();
    return ['premium' => $premium, 'limit' => FREE_ACTIVE_LIMIT, 'active' => $active, 'today' => $today, 'strikes' => $strikes];
}

function notify_admins(PDO $pdo, string $msg, string $link): void {
    $pdo->prepare("INSERT INTO notifications (user_id, message, link) SELECT id, CAST(? AS varchar), CAST(? AS varchar) FROM users WHERE role = 'admin' AND status = 'active'")->execute([$msg, $link]);
}