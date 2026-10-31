<?php
declare(strict_types=1);

/**
 * Admin layout: side navigation + top bar.
 * Usage: admin_header('Title', 'nav-key');  ...page...;  admin_footer();
 */

function nav_icon(string $name): string
{
    $icons = [
        'dashboard'    => '<path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>',
        'applications' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'courses'      => '<path d="M2 7l10-4 10 4-10 4L2 7z"/><path d="M6 9.5V15c0 1.5 3 3 6 3s6-1.5 6-3V9.5"/>',
        'payments'     => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'intakes'      => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/>',
        'settings'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'external'     => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';
}

function admin_header(string $title, string $active = ''): void
{
    require_admin();
    $courseId = dashboard_course_id();
    $pendingSql = "SELECT COUNT(*) FROM applications WHERE status = 'Pending'";
    $pendingParams = [];
    if ($courseId > 0) {
        $pendingSql .= ' AND course_id = ?';
        $pendingParams[] = $courseId;
    }
    $pending = (int) q($pendingSql, $pendingParams)->fetchColumn();
    $courses = q('SELECT id, title FROM courses ORDER BY sort_order, title')->fetchAll();
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $courseFilterAction = basename($scriptName) === 'application_view.php'
        ? url('admin/applications.php')
        : $scriptName;
    $me      = admin();
    $items   = [
        'dashboard'    => ['Dashboard', 'admin/index.php'],
        'applications' => ['Applications', 'admin/applications.php'],
        'courses'      => ['Courses', 'admin/courses.php'],
        'payments'     => ['Payments', 'admin/payments.php'],
        'intakes'      => ['Intakes', 'admin/intakes.php'],
        'settings'     => ['Settings', 'admin/settings.php'],
    ];
    $name = e(cfg('app_name'));
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | <?= e(cfg('app_short')) ?> Admin</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script>
  (function () {
    var theme = localStorage.getItem('dsau-admin-theme');
    if (['dsau', 'ocean', 'forest', 'violet', 'dark', 'blue', 'orange', 'golden', 'blue-orange', 'blue-orange-white'].indexOf(theme) !== -1) {
      document.documentElement.setAttribute('data-admin-theme', theme);
    }
  })();
</script>
</head>
<body class="admin-theme">
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <img src="<?= e(logo_url(false)) ?>" alt="<?= $name ?>"
         onerror="this.outerHTML='<div class=&quot;brand-fallback&quot;><span>DS</span>AU</div>'">
    <div class="sidebar-sub">Admissions Portal</div>
  </div>
  <ul class="nav">
    <li class="nav-label">Menu</li>
    <?php foreach ($items as $key => [$label, $path]): ?>
      <li>
        <a href="<?= e(url($path)) ?>" class="<?= $active === $key ? 'active' : '' ?>">
          <?= nav_icon($key) ?><span><?= e($label) ?></span>
          <?php if ($key === 'applications' && $pending > 0): ?><span class="count"><?= $pending ?></span><?php endif; ?>
        </a>
      </li>
    <?php endforeach; ?>
    <li class="nav-label">Quick links</li>
    <li><a href="<?= e(url('index.php')) ?>" target="_blank" rel="noopener"><?= nav_icon('external') ?><span>Admission form</span></a></li>
    <li><a href="<?= e(cfg('website')) ?>" target="_blank" rel="noopener"><?= nav_icon('external') ?><span>DSAU website</span></a></li>
  </ul>
  <div class="sidebar-foot">
    <div class="who"><?= e($me['name']) ?></div>
    <div class="small"><?= e(ucfirst($me['role'])) ?> &middot; <a href="<?= e(url('admin/logout.php')) ?>">Sign out</a></div>
  </div>
</aside>
<div class="overlay"></div>
<div class="main">
  <header class="topbar">
    <button class="menu-btn" type="button" aria-label="Open menu"><span></span></button>
    <h1><?= e($title) ?></h1>
    <div class="spacer"></div>
    <form method="get" action="<?= e($courseFilterAction) ?>" class="course-filter no-print">
      <?php foreach ($_GET as $key => $value): ?>
        <?php if ($key !== 'course' && $key !== 'page' && $key !== 'id' && is_scalar($value)): ?>
          <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
        <?php endif; ?>
      <?php endforeach; ?>
      <label for="dashboard-course">Course</label>
      <select id="dashboard-course" name="course" onchange="this.form.submit()" aria-label="Filter dashboard by course">
        <option value="0" <?= $courseId === 0 ? 'selected' : '' ?>>All courses</option>
        <?php foreach ($courses as $course): ?>
          <option value="<?= (int) $course['id'] ?>" <?= $courseId === (int) $course['id'] ? 'selected' : '' ?>><?= e($course['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <div class="theme-picker">
      <label for="admin-theme-select">Theme</label>
      <select id="admin-theme-select" aria-label="Choose dashboard color theme">
        <option value="dsau">DSAU</option>
        <option value="ocean">Ocean</option>
        <option value="forest">Forest</option>
        <option value="violet">Violet</option>
        <option value="dark">Dark</option>
        <option value="blue">Blue</option>
        <option value="orange">Orange</option>
        <option value="golden">Golden</option>
        <option value="blue-orange">Blue + Orange</option>
        <option value="blue-orange-white">Blue + Orange on white</option>
      </select>
    </div>
    <span class="muted small hide-sm"><?= e(date('l, j F Y')) ?></span>
  </header>
  <main class="content">
    <?= flash_render() ?>
<?php
}

function admin_footer(): void
{
    ?>
  </main>
  <footer class="footer">&copy; <?= date('Y') ?> <?= e(cfg('app_name')) ?> &middot; <?= e(cfg('tagline')) ?></footer>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
<?php
}
