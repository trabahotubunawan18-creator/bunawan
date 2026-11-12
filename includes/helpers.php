<?php
// Shared display helpers and the public-job query used by the home and Find Jobs pages.

// Premium is per job post: P49 per month for each post the employer picks. The admin activates it by hand.
const PREMIUM_PRICE = 49;
// When the work happens. 'any' means the employer did not say, so no tag is shown.
const WORK_SCHEDULES = ['any' => 'Any schedule', 'weekdays' => 'Weekdays only', 'weekends' => 'Weekends only', 'flexible' => 'Flexible hours'];
const PAYMENT_METHODS = ['gcash' => 'GCash', 'maribank' => 'MariBank'];
// SQL test: "this job post (alias j) has an active Premium"
const PREMIUM_JOB_COND = "EXISTS(SELECT 1 FROM premium_subscription_jobs psj JOIN premium_subscriptions ps ON ps.id = psj.subscription_id
               WHERE psj.job_id = j.id AND ps.status = 'active' AND ps.ends_on >= CURRENT_DATE)";

const PUBLIC_JOB_SQL = "
 SELECT j.id, j.title, j.employment_type, j.salary_min, j.salary_max, j.salary_period,
        j.deadline, j.created_at, j.student_friendly, j.work_schedule, j.resume_requirement,
        ep.employer_name, ep.verification_status, ep.logo_path,
        j.barangay_id, b.name AS barangay, c.name AS category,
        CASE WHEN " . PREMIUM_JOB_COND . " THEN 1 ELSE 0 END AS is_premium
 FROM job_posts j
 JOIN employer_profiles ep ON ep.user_id = j.employer_id
 JOIN barangays b ON b.id = j.barangay_id
 JOIN job_categories c ON c.id = j.category_id
 WHERE j.status = 'approved' AND j.deadline >= CURRENT_DATE
   AND ep.verification_status IN ('verified_business','verified_local')";

function salary_text(array $j): string {
    if ($j['salary_period'] === 'negotiable' || (!$j['salary_min'] && !$j['salary_max'])) return 'Negotiable';
    $min = (float)$j['salary_min']; $max = (float)$j['salary_max'];
    $txt = '₱' . number_format($min);
    if ($max > $min) $txt .= ' - ₱' . number_format($max);
    return $txt . ' / ' . $j['salary_period'];
}
function type_label(string $t): string {
    return ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'temporary' => 'Temporary', 'internship' => 'Internship'][$t] ?? $t;
}
function time_ago(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 3600) return 'Posted just now';
    if ($d < 86400) return 'Posted today';
    $days = (int)floor($d / 86400);
    return $days === 1 ? 'Posted yesterday' : "Posted $days days ago";
}
function verified_label(string $s): string {
    return $s === 'verified_business' ? 'Verified Business' : 'Verified Local Employer';
}
// Category icon file names (you provide these in assets/img/)
function cat_icon(string $name): string {
    $map = [
        'Retail' => 'cat-retail.svg', 'Food and Service' => 'cat-food.svg',
        'Farming and Agriculture' => 'cat-farming.svg', 'Construction' => 'cat-construction.svg',
        'Education' => 'cat-education.svg', 'Driver and Delivery' => 'cat-driver.svg',
        'Household Help' => 'cat-household.svg', 'Office and Admin' => 'cat-office.svg',
        'Health' => 'cat-health.svg',
    ];
    return $map[$name] ?? 'cat-others.svg';
}
function setting(string $key, string $default = ''): string {
    $s = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? $default : (string)$v;
}
// Where employers send Premium payments. Set by the admin in Admin > Settings > Payment accounts.
function pay_account(string $method): array {
    if (!isset(PAYMENT_METHODS[$method])) return ['number' => '', 'name' => '', 'qr' => ''];
    $qr = setting('pay_' . $method . '_qr');
    return [
        'number' => setting('pay_' . $method . '_number'),
        'name'   => setting('pay_' . $method . '_name'),
        'qr'     => preg_match('#^qr/[a-f0-9]{32}\.(jpg|png)$#', $qr) ? $qr : '',
    ];
}
// Short code the employer writes in the payment message, e.g. PREM-00012
function pay_code(int $subId): string { return 'PREM-' . str_pad((string)$subId, 5, '0', STR_PAD_LEFT); }
// Small inline icons (no image files needed)
function icon(string $name, int $size = 16): string {
    $paths = [
        'pin'      => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'briefcase'=> '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>',
        'tag'      => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'check'    => '<path d="M5 13l4 4L19 7"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 4.5-3.2 7.8-8 9-4.8-1.2-8-4.5-8-9V6z"/><path d="M8.5 12l2.5 2.5L15.5 10"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'map'      => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'home'     => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'bookmark' => '<path d="M6 3h12v18l-6-4-6 4z"/>',
        'send'     => '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'file'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'bell'     => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10 21a2 2 0 0 0 4 0"/>',
        'cog'      => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'menu'     => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2 21c0-3.6 3-5.5 7-5.5s7 1.9 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M22 21c0-3-2-4.8-5-5.3"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 8h2M13 8h2M9 12h2M13 12h2M10 21v-4h4v4"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'lock'     => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'eye'      => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'star'     => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'flag'     => '<path d="M5 21V4h11l-2 4 2 4H5"/>',
        'chart'    => '<path d="M3 3v18h18"/><path d="M8 16v-5M13 16V7M18 16v-3"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'bolt'     => '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    ];
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}
function initials(string $name): string {
    $words = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($words, 0, 2) as $w) $out .= mb_strtoupper(mb_substr($w, 0, 1));
    return $out ?: '?';
}
function render_job_card(array $j, bool $featured = false, ?array $save = null): void { ?>
  <article class="card job<?= $featured ? ' job-featured' : '' ?>">
    <div class="job-head">
      <div class="avatar" aria-hidden="true">
        <?php if (!empty($j['logo_path'])): ?><img src="<?= url('uploads/' . $j['logo_path']) ?>" alt=""><?php else: ?><?= e(initials($j['employer_name'])) ?><?php endif; ?>
      </div>
      <div class="job-id">
        <h3><a href="<?= url('job.php?id=' . (int)$j['id']) ?>"><?= e($j['title']) ?></a></h3>
        <p class="employer"><?= e($j['employer_name']) ?></p>
      </div>
    </div>
    <div class="badges">
      <?php if ($j['is_premium']): ?><span class="badge badge-gold">&#9733; Featured</span><?php endif; ?>
      <span class="badge badge-ver"><?= icon('shield', 14) ?> <?= e(verified_label($j['verification_status'])) ?></span>
      <?php if (!empty($j['match']) && $j['match']['pct'] > 0): ?><span class="badge badge-match <?= e(skill_badge_class($j['match']['pct'])) ?>" title="<?= count($j['match']['matched']) ?> of <?= (int)$j['match']['need'] ?> skills match"><?= icon('bolt', 13) ?> <?= (int)$j['match']['pct'] ?>% skills match</span><?php endif; ?>
    </div>
    <ul class="facts">
      <li><?= icon('pin') ?> <?= e($j['barangay']) ?></li>
      <li><?= icon('briefcase') ?> <?= e(type_label($j['employment_type'])) ?></li>
      <li><?= icon('tag') ?> <?= e($j['category']) ?></li>
    </ul>
    <div class="tags">
      <?php if ($j['student_friendly']): ?><span class="tag tag-student">Student-friendly</span><?php endif; ?>
      <?php if (($j['work_schedule'] ?? 'any') !== 'any'): ?><span class="tag tag-weekend"><?= e(WORK_SCHEDULES[$j['work_schedule']] ?? '') ?></span><?php endif; ?>
      <?php if ($j['resume_requirement'] === 'none'): ?><span class="tag tag-noresume">No resume needed</span><?php endif; ?>
      <?php $hit = array_flip($j['match']['matched'] ?? []); foreach (array_slice($j['skill_labels'] ?? [], 0, 3) as $sl): ?><span class="tag tag-skill<?= isset($hit[$sl]) ? ' is-hit' : '' ?>"><?= e($sl) ?></span><?php endforeach; ?>
      <?php if (count($j['skill_labels'] ?? []) > 3): ?><span class="tag tag-skill">+<?= count($j['skill_labels']) - 3 ?> more</span><?php endif; ?>
    </div>
    <div class="job-foot<?= mb_strlen(salary_text($j)) > 17 ? ' is-long' : '' ?>">
      <span class="salary" title="<?= e(salary_text($j)) ?>"><?= e(salary_text($j)) ?></span>
      <span class="deadline" title="Apply by <?= e(date('M j, Y', strtotime($j['deadline']))) ?>"><?= icon('calendar', 14) ?> <span class="dl-label">Apply by</span> <?= e(date('M j, Y', strtotime($j['deadline']))) ?></span>
    </div>
    <div class="job-actions">
      <span class="posted"><?= e(time_ago($j['created_at'])) ?></span>
      <span class="job-btns">
        <?php if ($save !== null): ?>
          <form method="post" action="<?= e($save['action']) ?>" class="inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle_save"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>">
            <button class="btn btn-outline btn-sm" type="submit" title="<?= $save['saved'] ? 'Remove from saved jobs' : 'Save this job' ?>"><?= icon('bookmark', 15) ?> <?= $save['saved'] ? 'Saved' : 'Save' ?></button>
          </form>
        <?php endif; ?>
        <a class="btn btn-primary btn-sm" href="<?= url('job.php?id=' . (int)$j['id']) ?>">View and Apply</a>
      </span>
    </div>
  </article>
<?php }

function nav_link(string $file, string $label): string {
    $cur = basename($_SERVER['SCRIPT_NAME']);
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    if ($dir !== rtrim(BASE_URL, '/')) $cur = '';
    $on = ($cur === $file) || ($file === 'jobs.php' && $cur === 'job.php');
    return '<a href="' . url($file) . '"' . ($on ? ' class="active" aria-current="page"' : '') . '>' . e($label) . '</a>';
}
function post_job_url(): string {
    return (is_logged_in() && ($_SESSION['role'] ?? '') === 'employer') ? 'employer/post-job.php' : 'register.php?role=employer';
}

// Application status label and CSS class
function app_status(string $s): array {
    $m = ['pending' => ['Pending', 'st-pending'], 'reviewed' => ['Reviewed', 'st-reviewed'], 'shortlisted' => ['Shortlisted', 'st-short'],
          'accepted' => ['Accepted', 'st-ok'], 'rejected' => ['Rejected', 'st-bad'], 'withdrawn' => ['Withdrawn', 'st-off']];
    return $m[$s] ?? [ucfirst($s), 'st-off'];
}
function days_left(string $date): int { return (int)floor((strtotime($date) - strtotime('today')) / 86400); }

// Job post status label and CSS class
function job_status(string $s): array {
    $m = ['pending' => ['Pending approval', 'st-pending'], 'approved' => ['Active', 'st-ok'], 'rejected' => ['Rejected', 'st-bad'],
          'closed' => ['Closed', 'st-off'], 'expired' => ['Expired', 'st-off'], 'removed' => ['Removed', 'st-off']];
    return $m[$s] ?? [ucfirst($s), 'st-off'];
}
// Employer verification status: label, sidebar class, whether the employer may post jobs
function verif_info(string $s): array {
    $m = ['pending' => ['Pending review', 'vs-wait', false], 'more_info' => ['More info needed', 'vs-wait', false],
          'verified_business' => ['Verified Business', 'vs-ok', true], 'verified_local' => ['Verified Local Employer', 'vs-ok', true],
          'rejected' => ['Not approved', 'vs-bad', false], 'suspended' => ['Suspended', 'vs-bad', false]];
    return $m[$s] ?? $m['pending'];
}

// Simple pagination links. $base is a full URL path, with or without a query string.
function pager(int $total, int $page, int $per, string $base): string {
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) return '';
    $sep = strpos($base, '?') !== false ? '&' : '?';
    $link = fn($n, $label) => '<a href="' . e($base . $sep . 'page=' . $n) . '">' . $label . '</a>';
    $out = '<nav class="pager" aria-label="Pages">';
    if ($page > 1) $out .= $link($page - 1, '&larr; Prev');
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) $out .= $i === $page ? '<span class="cur">' . $i . '</span>' : $link($i, (string)$i);
    if ($page < $pages) $out .= $link($page + 1, 'Next &rarr;');
    return $out . '</nav>';
}


// =====================================================================================================
// Skills and skill-based matching
// A "skill key" is the normalized, stemmed form of a skill ("Cashiering", "cashier", "cashiers" -> "cashi").
// Fixed skills, their aliases (English, Tagalog, Cebuano) and typed "Other" skills all reduce to keys,
// so matching a seeker to a job is an exact key comparison. No admin approval is needed.
// =====================================================================================================
const SKILL_MAX_SEEKER = 15;      // skills one seeker can keep
const SKILL_MAX_JOB = 10;         // skills one job can require
const SKILL_MAX_CUSTOM = 3;       // typed "Other" skills allowed per save
const SKILL_AUTO_PROMOTE = 3;     // a typed skill joins the fixed list once this many different seekers typed it
const SKILL_BAD_TEXT = '/https?:|www\.|\.(?:com|net|org|ph)\b|@|\d{6,}/i';

// Starter list: name => aliases. Keys are generated from these with skill_key(), so they always agree with the matcher.
const SKILL_SEED = [
    'Cashiering'              => ['cashier', 'cash handling', 'kahera', 'kahero'],
    'Sales and selling'       => ['salesperson', 'sales lady', 'sales clerk', 'tindera', 'tindero', 'pagbebenta', 'baligya'],
    'Customer service'        => ['customer care', 'customer assistance', 'front desk', 'receptionist'],
    'Inventory and stocking'  => ['inventory', 'stock clerk', 'stock handling', 'stocking', 'bagger'],
    'Cooking'                 => ['cook', 'kusinero', 'kusinera', 'pagluluto', 'pagluto', 'kitchen helper'],
    'Baking'                  => ['baker', 'bakery', 'panadero', 'pastry'],
    'Food service'            => ['waiter', 'waitress', 'server', 'service crew', 'barista', 'serving'],
    'Driving'                 => ['driver', 'drayber', 'pagmamaneho', 'pagmaneho', 'motorcycle driving', 'tricycle driving', 'drivers license'],
    'Delivery'                => ['delivery rider', 'rider', 'courier', 'deliver'],
    'Farming'                 => ['farmer', 'magsasaka', 'pagsasaka', 'mag uuma', 'umahan', 'pagpanguma', 'farm helper', 'farm work'],
    'Harvesting'              => ['harvest', 'pag ani', 'pagpangani', 'ani', 'coconut harvesting', 'copra'],
    'Livestock and poultry'   => ['livestock', 'poultry', 'piggery', 'hog raising', 'pag aalaga ng hayop', 'animal raising'],
    'Fishing'                 => ['fisherman', 'mangingisda', 'pangisda', 'pangingisda'],
    'Carpentry'               => ['carpenter', 'karpintero', 'panday', 'woodwork'],
    'Masonry'                 => ['mason', 'pagmamason', 'concrete work', 'hollow blocks', 'tile setting'],
    'Welding'                 => ['welder', 'steel works'],
    'Painting'                => ['painter', 'pintor', 'pagpipinta'],
    'Electrical work'         => ['electrician', 'wiring', 'electrical wiring'],
    'Plumbing'                => ['plumber', 'tubero', 'pipe fitting'],
    'Mechanic and repair'     => ['mechanic', 'mekaniko', 'motorcycle repair', 'tricycle repair', 'auto repair', 'small engine repair'],
    'Heavy equipment operation' => ['backhoe', 'forklift', 'tractor operator', 'equipment operator', 'operator'],
    'Tailoring and sewing'    => ['tailor', 'sewing', 'mananahi', 'modista', 'pagtahi', 'dressmaking'],
    'Cleaning and housekeeping' => ['cleaner', 'janitor', 'janitress', 'paglilinis', 'paglimpyo', 'housekeeping', 'housekeeper', 'maid'],
    'Laundry'                 => ['paglalaba', 'labandera', 'labada', 'laundry'],
    'Childcare'               => ['babysitter', 'babysitting', 'yaya', 'nanny', 'pag aalaga ng bata'],
    'Elderly and patient care' => ['caregiver', 'nursing aide', 'pag aalaga ng matanda', 'patient care'],
    'Health and first aid'    => ['first aid', 'nurse', 'barangay health worker', 'bhw', 'midwife'],
    'Teaching and tutoring'   => ['teacher', 'tutor', 'pagtuturo', 'pagtudlo', 'tutoring'],
    'Basic computer'          => ['computer literate', 'ms office', 'microsoft office', 'ms word', 'ms excel', 'computer'],
    'Typing and data entry'   => ['typing', 'encoder', 'encoding', 'data entry'],
    'Office clerical work'    => ['clerk', 'clerical', 'secretary', 'filing', 'admin assistant', 'office work'],
    'Bookkeeping'             => ['bookkeeper', 'accounting', 'accounts'],
    'Security guard'          => ['guard', 'security', 'bantay', 'watchman'],
    'Hair and beauty'         => ['haircut', 'barber', 'hairdresser', 'salon', 'manicure', 'pedicure'],
    'Online selling and social media' => ['social media', 'facebook marketing', 'online selling', 'live selling'],
    'Communication'           => ['communication skills', 'good communication'],
];

function skill_stem_word(string $w): string {
    if (mb_strlen($w) > 5 && str_ends_with($w, 'ing')) $w = mb_substr($w, 0, -3);
    if (mb_strlen($w) > 4 && str_ends_with($w, 'ers')) $w = mb_substr($w, 0, -3);
    elseif (mb_strlen($w) > 4 && str_ends_with($w, 'er')) $w = mb_substr($w, 0, -2);
    elseif (mb_strlen($w) > 3 && str_ends_with($w, 's') && !str_ends_with($w, 'ss')) $w = mb_substr($w, 0, -1);
    if (mb_strlen($w) > 4 && str_ends_with($w, 'e')) $w = mb_substr($w, 0, -1);
    return $w;
}
// Lowercase, strip punctuation and filler words, stem each word. "" means nothing usable.
function skill_key(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $words = array_values(array_filter(explode(' ', trim($s)), fn($w) => $w !== ''));
    $stop = ['and', 'of', 'the', 'in', 'basic', 'skills', 'skill', 'knowledge', 'experience', 'good', 'able'];
    $kept = array_values(array_filter($words, fn($w) => !in_array($w, $stop, true)));
    if (!$kept) $kept = $words;
    return mb_substr(implode(' ', array_map('skill_stem_word', $kept)), 0, 80);
}
function skill_label(string $s): string {
    $s = mb_substr(trim(preg_replace('/\s+/u', ' ', $s)), 0, 60);
    return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}
function skill_similarity(string $a, string $b): int {
    $m = max(strlen($a), strlen($b));
    if ($m === 0 || $m > 255) return 0;
    return (int)round((1 - levenshtein($a, $b) / $m) * 100);
}
function skill_text_ok(string $t): bool {
    $n = mb_strlen($t);
    return $n >= 2 && $n <= 60 && preg_match('/\p{L}/u', $t) && !preg_match(SKILL_BAD_TEXT, $t);
}

// Returns false until migrations/001_skills.sql has been run, so pages keep working instead of crashing.
function skills_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) { try { $pdo->query('SELECT 1 FROM skills LIMIT 1'); $ok = true; } catch (PDOException $e) { $ok = false; } }
    return $ok;
}
function skills_seed(PDO $pdo): void {
    $insS = $pdo->prepare('INSERT INTO skills (name, skill_key) VALUES (?, ?) ON CONFLICT DO NOTHING');
    $getS = $pdo->prepare('SELECT id FROM skills WHERE skill_key = ?');
    $insA = $pdo->prepare('INSERT INTO skill_aliases (skill_id, alias_key) VALUES (?, ?) ON CONFLICT DO NOTHING');
    foreach (SKILL_SEED as $name => $aliases) {
        $key = skill_key($name);
        $insS->execute([$name, $key]);
        $getS->execute([$key]);
        $id = (int)$getS->fetchColumn();
        if (!$id) continue;
        foreach ($aliases as $a) { $ak = skill_key($a); if ($ak !== '' && $ak !== $key) $insA->execute([$id, $ak]); }
    }
}
// Active fixed skills as [key => name]. Seeds the starter list the first time it is empty.
function skills_catalog(PDO $pdo, bool $fresh = false): array {
    static $c = null;
    if ($c !== null && !$fresh) return $c;
    $c = [];
    if (!skills_ready($pdo)) return $c;
    if ((int)$pdo->query('SELECT COUNT(*) FROM skills')->fetchColumn() === 0) skills_seed($pdo);
    foreach ($pdo->query('SELECT name, skill_key FROM skills WHERE is_active = 1 ORDER BY name') as $r) $c[(string)$r['skill_key']] = $r['name'];
    return $c;
}
// Synonyms as [alias_key => skill_key] for active skills.
function skills_aliases(PDO $pdo): array {
    static $a = null;
    if ($a !== null) return $a;
    $a = [];
    if (!skills_ready($pdo)) return $a;
    foreach ($pdo->query('SELECT a.alias_key, s.skill_key FROM skill_aliases a JOIN skills s ON s.id = a.skill_id WHERE s.is_active = 1') as $r) $a[(string)$r['alias_key']] = (string)$r['skill_key'];
    return $a;
}

// Turn typed text into a skill. Order: exact fixed skill, synonym, close spelling, otherwise a custom skill.
// Returns ['key', 'label', 'custom'] or null when the text has nothing usable.
function skill_resolve(PDO $pdo, string $text): ?array {
    $key = skill_key($text);
    if ($key === '') return null;
    $cat = skills_catalog($pdo);
    if (isset($cat[$key])) return ['key' => $key, 'label' => $cat[$key], 'custom' => false];
    $al = skills_aliases($pdo);
    if (isset($al[$key]) && isset($cat[$al[$key]])) return ['key' => $al[$key], 'label' => $cat[$al[$key]], 'custom' => false];
    $best = null; $pct = 0;
    if (strlen($key) >= 5) {
        foreach ($cat as $k => $name) { $p = skill_similarity($key, (string)$k); if ($p > $pct) { $pct = $p; $best = (string)$k; } }
        foreach ($al as $ak => $k) { if (!isset($cat[$k])) continue; $p = skill_similarity($key, (string)$ak); if ($p > $pct) { $pct = $p; $best = (string)$k; } }
    }
    if ($best !== null && $pct >= 85) return ['key' => $best, 'label' => $cat[$best], 'custom' => false];
    return ['key' => $key, 'label' => skill_label($text), 'custom' => true];
}
// Split old comma-separated text into skills (used for profiles saved before the skill picker existed).
function skills_from_text(PDO $pdo, string $csv, int $max = SKILL_MAX_SEEKER): array {
    $out = [];
    foreach (preg_split('/[,;\n]+/', $csv) as $t) {
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if ($t === '' || !skill_text_ok($t)) continue;
        $r = skill_resolve($pdo, $t);
        if ($r && !isset($out[$r['key']])) $out[$r['key']] = $r;
        if (count($out) >= $max) break;
    }
    return array_values($out);
}
// Read the skill picker (fields skills[] and custom_skills[]) from $_POST. Adds a message to $errors if something is wrong.
function skills_from_post(PDO $pdo, array &$errors, int $maxTotal, int $maxCustom = SKILL_MAX_CUSTOM): array {
    $out = []; $customN = 0;
    $cat = skills_catalog($pdo);
    foreach ((array)($_POST['skills'] ?? []) as $k) {
        if (is_string($k) && isset($cat[$k])) $out[$k] = ['key' => $k, 'label' => $cat[$k], 'custom' => false];
    }
    foreach (array_slice((array)($_POST['custom_skills'] ?? []), 0, 10) as $t) {
        if (!is_string($t)) continue;
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if ($t === '') continue;
        if (!skill_text_ok($t)) { $errors[] = 'One of your own skills is not valid. Use 2 to 60 characters, with no links or phone numbers.'; continue; }
        $r = skill_resolve($pdo, $t);
        if (!$r || isset($out[$r['key']])) continue;
        if ($r['custom']) $customN++;
        $out[$r['key']] = $r;
    }
    if ($customN > $maxCustom) $errors[] = 'You can add up to ' . $maxCustom . ' skills of your own. Pick from the list when you can.';
    if (count($out) > $maxTotal) $errors[] = 'Choose up to ' . $maxTotal . ' skills.';
    return array_values($out);
}
// Replace the saved skills of one seeker, job or application.
function skills_save(PDO $pdo, string $table, int $id, array $skills): void {
    $cols = ['seeker_skills' => 'user_id', 'job_skills' => 'job_id', 'application_skills' => 'application_id'];
    if (!isset($cols[$table])) return;
    $col = $cols[$table];
    $pdo->prepare("DELETE FROM $table WHERE $col = ?")->execute([$id]);
    $ins = $pdo->prepare("INSERT INTO $table ($col, skill_key, label, is_custom) VALUES (?,?,?,?) ON CONFLICT DO NOTHING");
    foreach ($skills as $s) $ins->execute([$id, $s['key'], mb_substr($s['label'], 0, 60), !empty($s['custom']) ? 1 : 0]);
}
// A typed skill that enough different seekers typed becomes a fixed skill, with no admin step.
function skill_promote(PDO $pdo, string $key, string $label): void {
    $pdo->prepare('INSERT INTO skills (name, skill_key) VALUES (?, ?) ON CONFLICT DO NOTHING')->execute([$label, $key]);
    foreach (['seeker_skills', 'job_skills', 'application_skills'] as $t) $pdo->prepare("UPDATE $t SET is_custom = 0 WHERE skill_key = ?")->execute([$key]);
    skills_catalog($pdo, true);
}
function skills_auto_promote(PDO $pdo, array $skills): void {
    $c = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM seeker_skills WHERE skill_key = ? AND is_custom = 1');
    foreach ($skills as $s) {
        if (empty($s['custom'])) continue;
        $c->execute([$s['key']]);
        if ((int)$c->fetchColumn() >= SKILL_AUTO_PROMOTE) skill_promote($pdo, $s['key'], $s['label']);
    }
}
// Move everything typed under $fromKey onto the fixed skill $toKey, and remember it as a synonym.
function skill_merge(PDO $pdo, string $fromKey, string $toKey): void {
    $cat = skills_catalog($pdo, true);
    if (!isset($cat[$toKey]) || $fromKey === $toKey) return;
    foreach (['seeker_skills', 'job_skills', 'application_skills'] as $t) {
        // UPDATE IGNORE does not exist in PostgreSQL: skip the rows that already have the target skill
        $c = ['seeker_skills' => 'user_id', 'job_skills' => 'job_id', 'application_skills' => 'application_id'][$t];
        $pdo->prepare("UPDATE $t SET skill_key = ?, label = ?, is_custom = 0 WHERE skill_key = ?
            AND NOT EXISTS (SELECT 1 FROM $t x WHERE x.$c = $t.$c AND x.skill_key = ?)")->execute([$toKey, $cat[$toKey], $fromKey, $toKey]);
        $pdo->prepare("DELETE FROM $t WHERE skill_key = ?")->execute([$fromKey]);
    }
    $id = $pdo->prepare('SELECT id FROM skills WHERE skill_key = ?'); $id->execute([$toKey]);
    $sid = (int)$id->fetchColumn();
    if ($sid) $pdo->prepare('INSERT INTO skill_aliases (skill_id, alias_key) VALUES (?, ?) ON CONFLICT DO NOTHING')->execute([$sid, $fromKey]);
}

// After the admin adds a skill or synonym, anything people already typed that resolves to it is moved onto it.
function skill_merge_typed(PDO $pdo, string $newKey): void {
    skills_catalog($pdo, true);
    $al = $pdo->query('SELECT a.alias_key, s.skill_key FROM skill_aliases a JOIN skills s ON s.id = a.skill_id')->fetchAll();
    $target = null;
    foreach ($al as $r) if ((string)$r['alias_key'] === $newKey) $target = (string)$r['skill_key'];
    if ($target === null) $target = $newKey;           // $newKey is itself a fixed skill: just clear its custom flag
    if ($target === $newKey) { foreach (['seeker_skills', 'job_skills', 'application_skills'] as $t) $pdo->prepare("UPDATE $t SET is_custom = 0 WHERE skill_key = ?")->execute([$newKey]); return; }
    skill_merge($pdo, $newKey, $target);
}

// ---------- Reading skills ----------
// A seeker's skills as a list of ['key', 'label', 'custom']. Profiles saved before the picker fall back to their old text.
function seeker_skill_list(PDO $pdo, int $uid): array {
    if (!skills_ready($pdo)) return [];
    $q = $pdo->prepare('SELECT skill_key, label, is_custom FROM seeker_skills WHERE user_id = ? ORDER BY is_custom, label');
    $q->execute([$uid]);
    $rows = array_map(fn($r) => ['key' => (string)$r['skill_key'], 'label' => $r['label'], 'custom' => (bool)$r['is_custom']], $q->fetchAll());
    if ($rows) return $rows;
    $l = $pdo->prepare('SELECT skills FROM seeker_profiles WHERE user_id = ?');
    $l->execute([$uid]);
    return skills_from_text($pdo, (string)$l->fetchColumn());
}
function skill_list_map(array $list): array { $m = []; foreach ($list as $s) $m[(string)$s['key']] = $s['label']; return $m; }
function seeker_skill_keys(PDO $pdo, int $uid): array { return skill_list_map(seeker_skill_list($pdo, $uid)); }
function job_skill_list(PDO $pdo, int $jobId): array {
    if (!skills_ready($pdo)) return [];
    $q = $pdo->prepare('SELECT skill_key, label, is_custom FROM job_skills WHERE job_id = ? ORDER BY is_custom, label');
    $q->execute([$jobId]);
    return array_map(fn($r) => ['key' => (string)$r['skill_key'], 'label' => $r['label'], 'custom' => (bool)$r['is_custom']], $q->fetchAll());
}
// [job_id => [key => label]] for many jobs at once.
function job_skill_map(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids || !skills_ready($pdo)) return [];
    $q = $pdo->prepare('SELECT job_id, skill_key, label FROM job_skills WHERE job_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY label');
    $q->execute($ids);
    $m = [];
    foreach ($q as $r) $m[(int)$r['job_id']][(string)$r['skill_key']] = $r['label'];
    return $m;
}

// ---------- Matching ----------
// $haveKeys: list of skill keys the person has. $need: [key => label] the job asks for.
function skill_match(array $haveKeys, array $need): array {
    $have = array_flip(array_map('strval', $haveKeys));
    $matched = []; $missing = [];
    foreach ($need as $k => $label) { if (isset($have[(string)$k])) $matched[] = $label; else $missing[] = $label; }
    $n = count($need);
    return ['pct' => $n ? (int)round(count($matched) / $n * 100) : null, 'matched' => $matched, 'missing' => $missing, 'need' => $n];
}
// Adds ['skill_labels'] and, when $mine ([key => label]) is given, ['match'] to every job row.
function attach_skill_info(PDO $pdo, array &$jobs, ?array $mine = null): void {
    if (!$jobs) return;
    $map = job_skill_map($pdo, array_column($jobs, 'id'));
    foreach ($jobs as &$j) {
        $need = $map[(int)$j['id']] ?? [];
        $j['skill_labels'] = array_values($need);
        $j['match'] = ($need && $mine) ? skill_match(array_keys($mine), $need) : null;
    }
    unset($j);
}
// Best skill match first, then the seeker's own barangay, then Featured, then newest. Jobs with no skills listed count as 0%.
function skill_sort_jobs(array $jobs, ?int $barangayId = null): array {
    usort($jobs, function ($a, $b) use ($barangayId) {
        $pa = (int)($a['match']['pct'] ?? 0); $pb = (int)($b['match']['pct'] ?? 0);
        if ($pa !== $pb) return $pb <=> $pa;
        if ($barangayId) {
            $ha = (int)((int)($a['barangay_id'] ?? 0) === $barangayId); $hb = (int)((int)($b['barangay_id'] ?? 0) === $barangayId);
            if ($ha !== $hb) return $hb <=> $ha;
        }
        if ((int)$a['is_premium'] !== (int)$b['is_premium']) return (int)$b['is_premium'] <=> (int)$a['is_premium'];
        return strcmp((string)$b['created_at'], (string)$a['created_at']);
    });
    return $jobs;
}
// Skills that approved, public jobs currently ask for, with counts. Used by the "Skills" filter.
function skills_in_use(PDO $pdo): array {
    if (!skills_ready($pdo)) return [];
    return $pdo->query("SELECT js.skill_key AS k, MIN(js.label) AS label, COUNT(*) AS n
        FROM job_skills js JOIN job_posts j ON j.id = js.job_id JOIN employer_profiles ep ON ep.user_id = j.employer_id
        WHERE j.status = 'approved' AND j.deadline >= CURRENT_DATE AND ep.verification_status IN ('verified_business','verified_local')
        GROUP BY js.skill_key ORDER BY label")->fetchAll();
}
function skill_badge_class(int $pct): string { return $pct >= 75 ? 'm-hi' : ($pct >= 40 ? 'm-mid' : 'm-lo'); }

// ---------- Skill picker (dropdown multi-select + "Other" box) ----------
// $selected: list of ['key', 'label', 'custom']. Posts skills[] (fixed keys) and custom_skills[] (typed text).
// Closed, it is one button plus small chips for what was chosen, so it takes very little space.
function render_skill_picker(PDO $pdo, string $id, array $selected, int $maxCustom = SKILL_MAX_CUSTOM, int $maxTotal = SKILL_MAX_SEEKER, array $prefer = []): void {
    static $loaded = false;
    $cat = skills_catalog($pdo);
    $on = []; $custom = [];
    foreach ($selected as $s) { if (isset($cat[$s['key']])) $on[$s['key']] = true; else $custom[] = $s['label']; }
    $js = []; foreach ($cat as $k => $n) $js[] = ['key' => (string)$k, 'name' => $n];
    // Listed first: the ones already chosen, then any the page prefers (for example the skills a job needs)
    $order = [];
    foreach (array_keys($on) as $k) $order[(string)$k] = true;
    foreach ($prefer as $k) if (isset($cat[(string)$k])) $order[(string)$k] = true;
    foreach ($cat as $k => $n) $order[(string)$k] = true;
    $order = array_keys($order);
    if (!$loaded): $loaded = true; ?>
  <style>
    .skp{position:relative;margin:6px 0 0}
    .skp-trigger{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;height:44px;padding:0 14px;border:1px solid var(--line-strong,#C9D6CD);border-radius:var(--r-md,10px);background:#fff;font:15px var(--font-ui,inherit);color:var(--ink,#12281E);cursor:pointer;text-align:left}
    .skp-trigger:hover{border-color:#A9BCAF}
    .skp-trigger:focus-visible,.skp.is-open .skp-trigger{border-color:var(--green,#1B7F45);box-shadow:0 0 0 3px rgba(27,127,69,.15);outline:0}
    .skp-trigger .ico{flex:none;color:var(--muted,#52665A);transition:transform .15s}
    .skp.is-open .skp-trigger .ico{transform:rotate(180deg)}
    .skp-trig-text{color:var(--muted,#52665A)}
    .skp.has-sel .skp-trig-text{color:var(--ink,#12281E);font-weight:600}
    .skp-selected{display:none;flex-wrap:wrap;gap:6px;margin-top:8px}
    .skp.has-sel .skp-selected{display:flex}
    .skp-sel-fixed,.skp-custom{display:contents}
    .skp-fchip,.skp-cchip{display:inline-flex;align-items:center;gap:6px;padding:4px 6px 4px 12px;border-radius:99px;font-size:13px;font-weight:500}
    .skp-fchip{background:var(--mint,#E4F3E9);border:1px solid var(--green,#1B7F45);color:var(--forest,#0F3D2E)}
    .skp-cchip{background:#FBF0C4;border:1px solid #F6E3A0;color:#5A4300}
    .skp-x{border:0;background:rgba(0,0,0,.09);color:inherit;width:20px;height:20px;border-radius:50%;font-size:14px;line-height:1;cursor:pointer;padding:0}
    .skp-x:hover{background:rgba(0,0,0,.2)}
    .skp-panel{margin-top:8px;border:1px solid var(--line-strong,#C9D6CD);border-radius:var(--r-md,10px);background:#fff;padding:12px;box-shadow:0 6px 18px rgba(15,61,46,.08)}
    .skp-panel[hidden]{display:none}
    .skp-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:2px 12px;max-height:210px;overflow:auto;margin-top:8px}
    .skp .skp-item{display:flex;align-items:center;gap:8px;margin:0!important;padding:7px 6px;border-radius:8px;font-size:14px!important;font-weight:500!important;color:var(--ink,#12281E);cursor:pointer}
    .skp .skp-item:hover{background:var(--sage,#F4F8F5)}
    .skp .skp-item input{width:16px;height:16px;flex:none;margin:0;padding:0;accent-color:var(--green,#1B7F45)}
    .skp-none{margin:8px 0 0}
    .skp-other{margin-top:12px;padding-top:6px;border-top:1px dashed var(--line-strong,#C9D6CD)}
    .skp-add{display:flex;gap:8px;align-items:center}
    .skp-add input{flex:1;min-width:0}
    .skp-suggest{margin:8px 0 0;font-size:13px;color:var(--muted,#52665A);display:flex;flex-wrap:wrap;gap:6px;align-items:center}
    .skp-suggest[hidden]{display:none}
    .skp-sug{border:1px solid var(--green,#1B7F45);background:var(--mint,#E4F3E9);color:var(--forest,#0F3D2E);border-radius:99px;padding:3px 11px;font:600 12.5px var(--font-ui,inherit);cursor:pointer}
    .skp-msg{min-height:18px;margin:6px 0 0;color:var(--green,#1B7F45)}
    .skp-hint{margin:6px 0 0;color:var(--muted,#52665A)}
    .skp-foot{display:flex;justify-content:flex-end;margin-top:10px}
    @media(max-width:600px){.skp-list{grid-template-columns:1fr}}
  </style>
  <script src="<?= url('assets/js/skills.js') ?>" defer></script>
  <?php endif; ?>
  <div class="skp<?= ($on || $custom) ? ' has-sel' : '' ?>" id="<?= e($id) ?>" data-max-custom="<?= (int)$maxCustom ?>" data-max-total="<?= (int)$maxTotal ?>" data-catalog="<?= e(json_encode($js, JSON_UNESCAPED_UNICODE)) ?>">
    <button type="button" class="skp-trigger" aria-expanded="false" aria-controls="<?= e($id) ?>-panel">
      <span class="skp-trig-text"><?= ($on || $custom) ? (count($on) + count($custom)) . ' selected' : 'Choose skills' ?></span>
      <svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <div class="skp-selected" aria-live="polite">
      <span class="skp-sel-fixed"></span>
      <span class="skp-custom">
        <?php foreach ($custom as $c): ?>
          <span class="skp-cchip"><?= e($c) ?><input type="hidden" name="custom_skills[]" value="<?= e($c) ?>"><button type="button" class="skp-x" aria-label="Remove <?= e($c) ?>">&times;</button></span>
        <?php endforeach; ?>
      </span>
    </div>
    <div class="skp-panel" id="<?= e($id) ?>-panel" hidden>
      <input type="search" class="skp-find" placeholder="Search skills" aria-label="Search skills" autocomplete="off">
      <div class="skp-list" role="group" aria-label="Skills">
        <?php foreach ($order as $k): $k = (string)$k; ?>
          <label class="skp-item"><input type="checkbox" name="skills[]" value="<?= e($k) ?>" <?= isset($on[$k]) ? 'checked' : '' ?>><span><?= e($cat[$k]) ?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="skp-none small" hidden>No skill found. Add it below as your own.</p>
      <div class="skp-other">
        <label for="<?= e($id) ?>-other">Not in the list? Type your own skill</label>
        <div class="skp-add">
          <input type="text" id="<?= e($id) ?>-other" maxlength="60" placeholder="Example: coconut harvesting" autocomplete="off">
          <button type="button" class="btn btn-outline btn-sm skp-addbtn">Add</button>
        </div>
        <div class="skp-suggest" hidden></div>
        <p class="skp-msg small" role="status" aria-live="polite"></p>
        <p class="small skp-hint">We match what you type to our skill list automatically. Up to <?= (int)$maxCustom ?> of your own.</p>
      </div>
      <div class="skp-foot"><button type="button" class="btn btn-primary btn-sm skp-close">Done</button></div>
    </div>
  </div>
<?php }

// Password rule used everywhere a password is created or changed (sign-up, change, reset).
// Returns an error message, or null when the password is acceptable.
function password_problem(string $pw): ?string {
    if (strlen($pw) < 8)                    return 'Password must be at least 8 characters long.';
    if (!preg_match('/[A-Z]/', $pw))        return 'Password must include an uppercase letter (A-Z).';
    if (!preg_match('/[a-z]/', $pw))        return 'Password must include a lowercase letter (a-z).';
    if (!preg_match('/\d/', $pw))           return 'Password must include a number (0-9).';
    if (!preg_match('/[^A-Za-z0-9]/', $pw)) return 'Password must include a symbol, like ! @ # $ % & *.';

    // Reject very common passwords, even when disguised (P@ssw0rd!, Qwerty123#, Admin2024!).
    $common = ['password','passw0rd','pass','qwerty','qwertyuiop','asdfgh','asdfghjkl','zxcvbn','zxcvbnm',
               'admin','administrator','welcome','letmein','iloveyou','princess','dragon','monkey','sunshine',
               'football','basketball','superman','batman','login','master','hello','freedom','whatever',
               'abc','abcdef','secret','changeme','default','trustno','bunawan','trabaho','philippines',
               'pilipinas','jobseeker','employer','mindanao','davao','manila','lovelove','mahalkita'];
    $lower = strtolower($pw);
    // Undo common look-alike swaps, then keep letters only: "P@ssw0rd123!" becomes "password".
    $core = preg_replace('/[^a-z]/', '', strtr($lower, ['@' => 'a', '$' => 's', '0' => 'o', '3' => 'e', '!' => 'i']));
    if (in_array($core, $common, true)) return 'That password is too common. Choose something harder to guess.';
    if (preg_match('/(.)\1{3,}/', $pw))   return 'Avoid repeating the same character many times.';

    return null;
}
