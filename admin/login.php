<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

if (admin()) {
    redirect(url('admin/index.php'));
}
if ((int) q('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) {
    redirect(url('admin/setup.php'));
}

$ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$maxTries = (int) cfg('login_max_attempts', 5);
$window   = (int) cfg('login_window_min', 15);
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    q('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    $tries = (int) q('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL ' . $window . ' MINUTE)', [$ip])->fetchColumn();

    if ($tries >= $maxTries) {
        $error = "Too many failed attempts. Please wait {$window} minutes and try again.";
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass  = (string) ($_POST['password'] ?? '');
        $row   = q('SELECT * FROM admins WHERE email = ?', [$email])->fetch();

        // Always run a hash check so response time doesn't reveal whether the email exists.
        $hash = $row['password_hash'] ?? password_hash('dummy-password', PASSWORD_DEFAULT);
        $ok   = password_verify($pass, $hash) && $row;

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['admin'] = ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email'], 'role' => $row['role']];
            q('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [$row['id']]);
            q('DELETE FROM login_attempts WHERE ip_address = ?', [$ip]);
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
            }
            redirect(url('admin/index.php'));
        }
        q('INSERT INTO login_attempts (ip_address) VALUES (?)', [$ip]);
        $error = 'Incorrect email or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in | <?= e(cfg('app_short')) ?> Admin</title>
<meta name="robots" content="noindex,nofollow">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-top"><img src="<?= e(logo_url(false)) ?>" alt="<?= e(cfg('app_name')) ?>"
      onerror="this.outerHTML='<strong style=&quot;color:#1556d6;font-size:1.2rem&quot;>DSAU</strong>'"></div>
  <form class="login-form" method="post" novalidate>
    <h2>Admissions portal</h2>
    <p class="muted small mt-0">Sign in to manage applications, courses and payments.</p>
    <?= flash_render() ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <div class="field mb-2">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" autocomplete="username" value="<?= e(old('email')) ?>" required autofocus>
    </div>
    <div class="field mb-2">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn-block" type="submit">Sign in</button>
    <p class="muted small text-center"><a href="<?= e(url('admin/forgot_password.php')) ?>">Forgot your password?</a></p>
  </form>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
