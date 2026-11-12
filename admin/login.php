<?php
// Separate admin entrance. It is not linked anywhere on the public site.
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/helpers.php';
if (is_logged_in() && ($_SESSION['role'] ?? '') === 'admin') redirect('admin/index.php');

$errors = []; $email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) $errors[] = 'Your session expired. Please try again.';
    elseif (rate_count('admin_login', 15) >= 6) $errors[] = 'Too many attempts. Please wait 15 minutes.';
    else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass = $_POST['password'] ?? '';
        $s = db()->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
        $s->execute([$email]);
        $u = $s->fetch();
        if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
            $errors[] = 'This account is temporarily locked. Try again later.';
        } elseif (!$u || !password_verify($pass, $u['password_hash'])) {
            rate_hit('admin_login');
            if ($u) {
                $f = $u['failed_logins'] + 1;
                db()->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')
                    ->execute([$f >= 5 ? 0 : $f, $f >= 5 ? date('Y-m-d H:i:s', time() + 900) : null, $u['id']]);
            }
            $errors[] = 'Incorrect email or password.';
        } elseif ($u['status'] !== 'active') {
            $errors[] = 'This account is not active.';
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id']; $_SESSION['role'] = 'admin'; $_SESSION['name'] = $u['full_name']; $_SESSION['admin_last'] = time();
            db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login = NOW() WHERE id = ?')->execute([$u['id']]);
            db()->prepare('INSERT INTO admin_activity_logs (admin_id, action, ip_address) VALUES (?, ?, ?)')->execute([$u['id'], 'admin_login', client_ip()]);
            redirect('admin/index.php');
        }
    }
}
$flash = get_flash();
$cssVer = filemtime(__DIR__ . '/../assets/css/style.css');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Admin sign in | Trabaho Tu Bunawan</title>
  <link rel="icon" href="<?= url('assets/img/favicon.ico') ?>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Poppins:wght@600;700&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=<?= $cssVer ?>">


<style>
/* Admin login - green form on a warm yellow background, with yellow accents. Self-contained. */
.admin-login{position:relative;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
  background:
    radial-gradient(700px 420px at 88% 6%,rgba(255,200,40,.45),transparent 70%),
    radial-gradient(ellipse at 50% 45%,rgba(60,40,0,.06) 0,rgba(60,40,0,.50) 100%),
    linear-gradient(160deg,rgba(70,46,0,.42),rgba(242,183,5,.22)),
    url('<?= url('assets/img/hero-bunawan.jpg') ?>') center/cover no-repeat fixed,#3A2A06}
.al-wrap{width:100%;display:flex;flex-direction:column;align-items:center;gap:18px}
.al-card{position:relative;width:100%;max-width:430px;padding:34px 34px 28px;border-radius:22px;border:0;overflow:hidden;
  background:#fff;box-shadow:0 30px 70px rgba(50,32,0,.45),0 0 0 3px rgba(242,183,5,.55);animation:alUp .5s cubic-bezier(.2,.7,.2,1) both}
.al-card:before{content:"";position:absolute;left:0;right:0;top:0;height:6px;background:linear-gradient(90deg,#1B7F45,#2FB463 55%,#F2B705)}
@keyframes alUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.al-card{animation:none}}

/* Brand block: logo, then name, then badge (centered) */
.al-brand{display:flex;flex-direction:column;align-items:center;text-align:center;margin:6px 0 20px}
.al-logo-img{display:block;height:96px;width:auto;max-width:150px;object-fit:contain;mix-blend-mode:multiply;margin-bottom:8px}
.al-name{margin:0;font:700 24px/1.2 'Poppins',var(--font-display),sans-serif;color:#0F3D2E;letter-spacing:-.01em}
.al-name b{color:#1B7F45}
.al-badge{display:inline-block;margin-top:8px;padding:3px 12px;border-radius:99px;background:#FDF0BA;border:1px solid #F2B705;color:#5A4300;font:700 10.5px 'Inter',sans-serif;letter-spacing:.1em;text-transform:uppercase}
.al-divider{height:2px;margin:0 auto 6px;width:64px;border:0;border-radius:99px;background:linear-gradient(90deg,#1B7F45,#F2B705)}
.al-sub{margin:12px 0 6px;text-align:center;font-size:14px;line-height:1.5;color:#52665A}

/* Form: green */
.al-form label{display:block;margin:14px 0 6px;font-weight:600;font-size:13.5px;color:#12281E}
.al-field{position:relative}
.al-ico{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#7C8E83;pointer-events:none;transition:color .15s}
.al-field input{display:block;width:100%;height:50px;padding:0 14px 0 44px;background:#F4F8F5;border:1px solid #C9D6CD;border-radius:10px;font:15px 'Inter',sans-serif;color:#12281E;transition:border-color .15s,box-shadow .15s,background .15s}
.al-field input::placeholder{color:#8EA095}
.al-field input:hover{border-color:#A9BCAF}
.al-field input:focus{background:#fff;border-color:#1B7F45;box-shadow:0 0 0 3px rgba(27,127,69,.22);outline:0}
.al-field:focus-within .al-ico{color:#1B7F45}
#a-pass{padding-right:56px}

/* Show / hide password */
.al-eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:38px;height:38px;padding:0;border:0;border-radius:8px;background:#E4F3E9;color:#0F3D2E;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s,color .15s}
.al-eye:hover{background:#D3EBDC}
.al-eye:focus-visible{outline:2px solid #F2B705;outline-offset:1px}
.al-eye svg{flex:none}

.al-caps{margin:8px 0 0;padding:6px 12px;border-radius:8px;background:#FFF1BF;border:1px solid #E0A800;color:#5A4300;font-size:12.5px;font-weight:600}
.al-caps[hidden]{display:none}

.al-submit{display:flex;align-items:center;justify-content:center;width:100%;margin-top:22px;min-height:52px;border:0;border-bottom:3px solid #F2B705;border-radius:10px;cursor:pointer;font:700 15.5px 'Inter',sans-serif;color:#fff;
  background:linear-gradient(180deg,#1E8A4B,#166B3A);box-shadow:0 6px 16px rgba(27,127,69,.35);transition:transform .15s,background .15s}
.al-submit:hover{background:linear-gradient(180deg,#1B7F45,#0F3D2E);transform:translateY(-1px)}
.al-submit:focus-visible{outline:3px solid rgba(242,183,5,.6);outline-offset:2px}
.al-submit.is-busy{opacity:.8;pointer-events:none}

.al-note{display:flex;align-items:center;justify-content:center;gap:7px;margin:20px 0 0;padding-top:16px;border-top:1px dashed #C9D6CD;font-size:13px;color:#52665A}
.al-note svg{color:#E0A800}
.al-copy{margin:0;font-size:12.5px;color:rgba(255,255,255,.92);text-shadow:0 1px 6px rgba(40,26,0,.75);text-align:center}
.admin-login .alert{margin:12px 0 0}
@media (max-width:480px){.al-card{padding:28px 22px 24px;border-radius:18px}.al-name{font-size:22px}}
</style>
</head>
<body class="admin-login">
  <main class="al-wrap">
    <section class="al-card card" aria-labelledby="al-title">
      <div class="al-brand">
        <img class="al-logo-img" src="<?= url('assets/img/logo-icon.jpg') ?>" alt="Trabaho Tu Bunawan logo" onerror="this.style.display='none'">
        <h1 class="al-name" id="al-title">Trabaho Tu <b>Bunawan</b></h1>
        <span class="al-badge">Administration</span>
      </div>
      <hr class="al-divider">
      <p class="al-sub">Sign in to manage employers, job posts, and reports.</p>

      <?php if ($flash): ?><div class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>
      <?php if ($errors): ?><div class="alert alert-error" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

      <form method="post" class="form al-form" id="al-form" novalidate>
        <?= csrf_field() ?>
        <label for="a-email">Email</label>
        <div class="al-field">
          <svg class="al-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
          <input id="a-email" name="email" type="email" autocomplete="username" placeholder="admin@example.com" value="<?= e($email) ?>" required autofocus>
        </div>

        <label for="a-pass">Password</label>
        <div class="al-field">
          <svg class="al-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
          <input id="a-pass" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
          <button type="button" class="al-eye" id="al-eye" aria-label="Show password" aria-pressed="false" title="Show password">
            <svg id="eye-on" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg id="eye-off" style="display:none" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.9 17.9A10.4 10.4 0 0 1 12 19c-6 0-10-7-10-7a18 18 0 0 1 4.1-4.9M9.9 5.2A9.7 9.7 0 0 1 12 5c6 0 10 7 10 7a18 18 0 0 1-2.2 3.1"/><path d="M14.1 14.1a3 3 0 0 1-4.2-4.2M2 2l20 20"/></svg>
          </button>
        </div>
        <p class="al-caps" id="al-caps" role="status" hidden>Caps Lock is on</p>

        <button class="al-submit" type="submit" id="al-submit"><span>Sign in</span></button>
      </form>

      <p class="al-note"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.2 7.8-8 9-4.8-1.2-8-4.5-8-9V6z"/><path d="M8.5 12l2.5 2.5L15.5 10"/></svg> Authorized administrators only</p>
    </section>
    <p class="al-copy">&copy; <?= date('Y') ?> Trabaho Tu Bunawan &middot; Local Skills. Local Jobs. Better Futures.</p>
  </main>
<script src="<?= url('assets/js/confirm.js') ?>?v=<?= filemtime(__DIR__ . '/../assets/js/confirm.js') ?>"></script>
<script>
(function(){
  var pass=document.getElementById('a-pass'),eye=document.getElementById('al-eye'),caps=document.getElementById('al-caps'),
      form=document.getElementById('al-form'),btn=document.getElementById('al-submit'),
      on=document.getElementById('eye-on'),off=document.getElementById('eye-off');
  eye.addEventListener('click',function(){
    var show=pass.type==='password';
    pass.type=show?'text':'password';
    on.style.display=show?'none':'';
    off.style.display=show?'':'none';
    eye.setAttribute('aria-pressed',show?'true':'false');
    eye.setAttribute('aria-label',show?'Hide password':'Show password');
    eye.title=show?'Hide password':'Show password';
    pass.focus();
  });
  function capsCheck(e){ if(e.getModifierState){ caps.hidden=!e.getModifierState('CapsLock'); } }
  pass.addEventListener('keydown',capsCheck);pass.addEventListener('keyup',capsCheck);
  pass.addEventListener('blur',function(){caps.hidden=true;});
  form.addEventListener('submit',function(){ btn.classList.add('is-busy'); btn.querySelector('span').textContent='Signing in\u2026'; });
})();
</script>
</body>
</html>
