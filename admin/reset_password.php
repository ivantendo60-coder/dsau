<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$token = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['token'] ?? '')
    : (string) ($_GET['token'] ?? '');
$validTokenFormat = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
$error = '';
$tokenValid = $validTokenFormat && (bool) q(
    'SELECT admin_id FROM password_reset_tokens WHERE token_hash = ? AND expires_at > NOW()',
    [hash('sha256', $token)]
)->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');

    if (strlen($password) < 10) {
        $error = 'The new password must be at least 10 characters.';
    } elseif ($password !== $confirm) {
        $error = 'The new passwords do not match.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $reset = $validTokenFormat ? q(
                'SELECT admin_id FROM password_reset_tokens WHERE token_hash = ? AND expires_at > NOW() FOR UPDATE',
                [hash('sha256', $token)]
            )->fetch() : false;

            if ($reset) {
                q('UPDATE admins SET password_hash = ? WHERE id = ?', [
                    password_hash($password, PASSWORD_DEFAULT),
                    $reset['admin_id'],
                ]);
                q('DELETE FROM password_reset_tokens WHERE admin_id = ?', [$reset['admin_id']]);
                q('DELETE FROM login_attempts WHERE ip_address = ?', [$_SERVER['REMOTE_ADDR'] ?? 'unknown']);
                $pdo->commit();
                flash('success', 'Your password has been reset. Sign in with your new password.');
                redirect(url('admin/login.php'));
            }
            $pdo->commit();
            $tokenValid = false;
            $error = 'This reset link is invalid or expired. Request a new one.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Choose a new password | <?= e(cfg('app_short')) ?> Admin</title>
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="login-body">
<div class="login-card">
  <div class="login-top"><img src="<?= e(logo_url(false)) ?>" alt="<?= e(cfg('app_name')) ?>"
    onerror="this.outerHTML='<strong style=&quot;color:#1556d6;font-size:1.2rem&quot;>DSAU</strong>'"></div>
  <form class="login-form" method="post">
    <h2>Choose a new password</h2>
    <?= flash_render() ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($tokenValid): ?>
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="field mb-2">
        <label for="password">New password</label>
        <input type="password" id="password" name="password" autocomplete="new-password" minlength="10" required autofocus>
        <div class="hint">At least 10 characters.</div>
      </div>
      <div class="field mb-2">
        <label for="confirm">Confirm new password</label>
        <input type="password" id="confirm" name="confirm" autocomplete="new-password" required>
      </div>
      <button class="btn btn-block" type="submit">Reset password</button>
    <?php else: ?>
      <p class="muted small">This reset link is invalid or expired. Request a new one to continue.</p>
      <a class="btn btn-block" href="<?= e(url('admin/forgot_password.php')) ?>">Request a new link</a>
    <?php endif; ?>
  </form>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
