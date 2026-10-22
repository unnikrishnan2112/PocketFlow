<?php
require_once __DIR__ . '/auth.php';
if (pfUserId()) { header('Location: index.php'); exit; }
$mode = ($_GET['mode'] ?? '') === 'register' ? 'register' : 'login';
$error = (string)($_SESSION['auth_error'] ?? '');
$oldEmail = (string)($_SESSION['auth_email'] ?? '');
unset($_SESSION['auth_error'], $_SESSION['auth_email']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#f6f7fb">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $mode === 'register' ? 'Create account' : 'Sign in' ?> · PocketFlow</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/style.css" rel="stylesheet">
  <link href="assets/auth.css" rel="stylesheet">
</head>
<body class="auth-body">
  <main class="auth-shell">
    <div class="auth-brand"><span class="auth-logo"><i class="bi bi-layers-fill"></i></span><strong>Pocket<span>Flow</span></strong></div>
    <div class="auth-art" aria-hidden="true">
      <div class="auth-art-symbol"><i class="bi bi-wallet2"></i></div>
      <div class="auth-art-label">Every income source. One clear view.</div>
      <div class="auth-art-chip"><i class="bi bi-shield-check"></i> Your money stays yours</div>
    </div>
    <div class="auth-panel">
      <div class="auth-heading"><span class="eyebrow">YOUR PRIVATE MONEY SPACE</span>
        <h1><?= $mode === 'register' ? 'Create your account' : 'Welcome back' ?></h1>
        <p><?= $mode === 'register' ? 'Start managing your income sources independently.' : 'Sign in to your personal money manager.' ?></p>
      </div>
      <div class="auth-switch" aria-label="Choose account form">
        <a href="login.php" class="<?= $mode === 'login' ? 'active' : '' ?>">Sign in</a>
        <a href="login.php?mode=register" class="<?= $mode === 'register' ? 'active' : '' ?>">Create account</a>
      </div>
      <?php if ($error !== ''): ?><p role="alert" class="auth-error"><i class="bi bi-exclamation-circle"></i> <?= pfEscape($error) ?></p><?php endif; ?>
      <form class="auth-form" method="post" action="account.php" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= pfEscape(pfCsrf()) ?>">
        <input type="hidden" name="action" value="<?= $mode === 'register' ? 'register' : 'login' ?>">
        <?php if ($mode === 'register'): ?>
        <label for="account-name">Full name</label>
        <div class="auth-input"><i class="bi bi-person"></i><input id="account-name" type="text" name="name" maxlength="80" autocomplete="name" placeholder="Your name" required></div>
        <?php endif; ?>
        <label for="account-email">Email address</label>
        <div class="auth-input"><i class="bi bi-envelope"></i><input id="account-email" type="email" name="email" maxlength="190" autocomplete="email" inputmode="email" value="<?= pfEscape($oldEmail) ?>" placeholder="you@example.com" required></div>
        <label for="account-password">Password</label>
        <div class="auth-input"><i class="bi bi-lock"></i><input id="account-password" type="password" name="password" minlength="10" <?= $mode === 'register' ? 'maxlength="72" autocomplete="new-password"' : 'autocomplete="current-password"' ?> placeholder="<?= $mode === 'register' ? 'At least 10 characters' : 'Enter your password' ?>" required></div>
        <?php if ($mode === 'register'): ?>
        <label for="account-confirm">Confirm password</label>
        <div class="auth-input"><i class="bi bi-shield-lock"></i><input id="account-confirm" type="password" name="confirm_password" minlength="10" maxlength="72" autocomplete="new-password" placeholder="Repeat password" required></div>
        <?php endif; ?>
        <button class="auth-submit" type="submit"><?= $mode === 'register' ? 'Create account' : 'Sign in' ?> <i class="bi bi-arrow-right"></i></button>
      </form>
      <p class="auth-footnote"><i class="bi bi-lock"></i> Private, separate accounts. Expenses are only charged to your own income sources.</p>
    </div>
  </main>
</body>
</html>
