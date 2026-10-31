<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/notifications.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $admin = q('SELECT id, name, email FROM admins WHERE email = ? FOR UPDATE', [$email])->fetch();
            $token = null;

            if ($admin) {
                q('DELETE FROM password_reset_tokens WHERE expires_at <= NOW()');
                $activeToken = q(
                    'SELECT admin_id FROM password_reset_tokens WHERE admin_id = ? AND expires_at > NOW() FOR UPDATE',
                    [$admin['id']]
                )->fetch();

                if (!$activeToken) {
                    $token = bin2hex(random_bytes(32));
                    q(
                        'INSERT INTO password_reset_tokens (admin_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))',
                        [$admin['id'], hash('sha256', $token)]
                    );
                }
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        if ($admin && $token !== null) {
            $origin = rtrim((string) cfg('app_url', ''), '/');
            $resetUrl = $origin . url('admin/reset_password.php?token=' . rawurlencode($token));
            if (!send_admin_password_reset_email($admin, $resetUrl)) {
                q('DELETE FROM password_reset_tokens WHERE admin_id = ? AND token_hash = ?', [
                    $admin['id'],
                    hash('sha256', $token),
                ]);
            }
        }
    }

    $message = 'If an administrator account uses that email address, reset instructions will be sent.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset password | <?= e(cfg('app_short')) ?> Admin</title>
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
    <h2>Forgot password?</h2>
    <p class="muted small mt-0">Enter the email address on your administrator account.</p>
    <?= flash_render() ?>
    <?php if ($message): ?><div class="alert alert-info" role="status"><?= e($message) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <div class="field mb-2">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" autocomplete="username" required autofocus>
    </div>
    <button class="btn btn-block" type="submit">Send reset link</button>
    <p class="muted small text-center"><a href="<?= e(url('admin/login.php')) ?>">Back to sign in</a></p>
  </form>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
