<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

// One-time setup: only works while there are no administrators.
if ((int) q('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
    redirect(url('admin/login.php'));
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name  = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password2'] ?? '');

    if ($name === '')                                   $errors['name'] = 'Enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))     $errors['email'] = 'Enter a valid email address.';
    if (strlen($pass) < 10)                             $errors['password'] = 'Use at least 10 characters.';
    if ($pass !== $pass2)                               $errors['password2'] = 'Passwords do not match.';

    if (!$errors) {
        q('INSERT INTO admins (name, email, password_hash, role) VALUES (?,?,?,?)',
          [$name, $email, password_hash($pass, PASSWORD_DEFAULT), 'admin']);
        flash('success', 'Administrator created. You can sign in now.');
        redirect(url('admin/login.php'));
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>First-time setup | <?= e(cfg('app_short')) ?> Admin</title>
<meta name="robots" content="noindex,nofollow">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="login-body">
<div class="login-card">
  <div class="login-top"><img src="<?= e(logo_url(true)) ?>" alt="<?= e(cfg('app_name')) ?>"
       onerror="this.outerHTML='<strong style=&quot;color:#fff;font-size:1.2rem&quot;>DSAU</strong>'"></div>
  <form class="login-form" method="post" novalidate>
    <h2>Create the first administrator</h2>
    <p class="muted small mt-0">This page disappears as soon as an administrator exists.</p>
    <?= csrf_field() ?>
    <div class="field mb-2 <?= isset($errors['name']) ? 'has-error' : '' ?>">
      <label for="name">Full name</label>
      <input type="text" id="name" name="name" value="<?= e(old('name')) ?>" required autofocus>
      <?= isset($errors['name']) ? '<div class="error-text">' . e($errors['name']) . '</div>' : '' ?>
    </div>
    <div class="field mb-2 <?= isset($errors['email']) ? 'has-error' : '' ?>">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" required>
      <?= isset($errors['email']) ? '<div class="error-text">' . e($errors['email']) . '</div>' : '' ?>
    </div>
    <div class="field mb-2 <?= isset($errors['password']) ? 'has-error' : '' ?>">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="new-password" required>
      <?= isset($errors['password']) ? '<div class="error-text">' . e($errors['password']) . '</div>' : '' ?>
    </div>
    <div class="field mb-2 <?= isset($errors['password2']) ? 'has-error' : '' ?>">
      <label for="password2">Confirm password</label>
      <input type="password" id="password2" name="password2" autocomplete="new-password" required>
      <?= isset($errors['password2']) ? '<div class="error-text">' . e($errors['password2']) . '</div>' : '' ?>
    </div>
    <button class="btn btn-block" type="submit">Create administrator</button>
  </form>
</div>
</body>
</html>
