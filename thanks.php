<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($_GET['ref'] ?? '')) ?? '';
$wa  = 'https://wa.me/' . preg_replace('/\D/', '', (string) cfg('whatsapp_number'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Application received | <?= e(cfg('app_name')) ?></title>
<meta name="robots" content="noindex">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="public-body">
<header class="pub-header">
  <div class="inner">
    <a href="<?= e(cfg('website')) ?>"><img src="<?= e(logo_url(true)) ?>" alt="<?= e(cfg('app_name')) ?>"
       onerror="this.outerHTML='<strong style=&quot;font-size:1.2rem&quot;>Digital Shift Academy Uganda</strong>'"></a>
  </div>
</header>
<div class="pub-wrap">
  <div class="card thanks">
    <div class="tick">&#10003;</div>
    <h1>Thank you, your application has been received!</h1>
    <?php if ($ref !== ''): ?>
      <div class="muted">Your reference number</div>
      <div class="ref-code"><?= e($ref) ?></div>
    <?php endif; ?>
    <p class="muted">Our admissions team will review your application and contact you on the phone number and email you provided.
      Please keep your reference number and quote it when you contact us.</p>
    <p>
      <a class="btn btn-accent" href="<?= e($wa) ?>" target="_blank" rel="noopener">Chat with us on WhatsApp</a>
      <a class="btn btn-light" href="<?= e(cfg('website')) ?>">Back to website</a>
    </p>
  </div>
</div>
<div class="pub-footer">&copy; <?= date('Y') ?> <?= e(cfg('app_name')) ?> &middot; <?= e(cfg('tagline')) ?></div>
</body>
</html>
