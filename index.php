<?php
declare(strict_types=1);

// Only the panel, API and the two public assets can be served by the dev router.
if (PHP_SAPI === 'cli-server') {
    $uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
    if ($uri === '/api.php') { require __DIR__ . '/api.php'; return true; }
    if (in_array($uri, ['/assets/app.js', '/assets/styles.css'], true)) return false;
    if (!in_array($uri, ['/', '/index.php'], true)) { http_response_code(404); exit; }
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/lib.php';
require_login();
$cfg = cfg();

if (!empty($GLOBALS['need_login'])):
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ورود · <?= h($cfg['panel_title']) ?></title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="login-body">
<div class="login-card glass">
  <div class="login-logo">◆</div>
  <h1>Control Center</h1>
  <p class="muted">ورود به پنل مدیریت یوزربات</p>
  <?php if (!empty($GLOBALS['login_error'])): ?>
    <p class="err"><?= h($GLOBALS['login_error']) ?></p>
  <?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="password" name="panel_password" placeholder="رمز پنل" autofocus required>
    <button class="btn primary block" type="submit">ورود</button>
  </form>
</div>
</body>
</html>
<?php
exit;
endif;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($cfg['panel_title']) ?></title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<div id="app">
  <aside class="side">
    <div class="brand">
      <span class="brand-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6z"/></svg></span>
      <?= h($cfg['panel_title']) ?>
    </div>
    <div class="side-sub">مدیریت چند اکانت یوزربات</div>
    <nav class="nav" id="nav">
      <div class="nav-label">منو</div>
      <button data-view="dashboard" class="active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
        داشبورد
      </button>
      <button data-view="add">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>
        افزودن اکانت
      </button>
      <button data-view="about">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
        درباره
      </button>
    </nav>
    <div class="side-foot">
      <div class="muted">API ID: <?= h((string) $cfg['api_id']) ?></div>
      <button class="btn ghost icon-btn" id="themeToggle" title="تغییر تم">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"/></svg>
      </button>
      <button class="btn danger icon-btn" id="logoutBtn" title="خروج">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17l5-5-5-5M20 12H9M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/></svg>
      </button>
    </div>
  </aside>

  <main class="main">
    <div id="view"></div>
  </main>
</div>

<div id="modalRoot"></div>
<div id="toast" class="toast"></div>

<script>
window.__BOOT = {
  csrf: <?= json_encode(csrf_token()) ?>,
  apiId: <?= json_encode((string) $cfg['api_id']) ?>,
  title: <?= json_encode($cfg['panel_title']) ?>
};
</script>
<script src="assets/app.js"></script>
</body>
</html>
