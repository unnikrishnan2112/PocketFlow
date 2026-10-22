<?php
/** Session and authentication helpers. Include at the beginning of every entry point. */
require_once __DIR__ . '/config.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('PFSESSID');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, no-store, max-age=0');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function pfCsrf(): string { return (string)$_SESSION['csrf']; }
function pfVerifyCsrf(string $token): bool {
    return $token !== '' && hash_equals(pfCsrf(), $token);
}
function pfEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function pfUserId(): int { return max(0, (int)($_SESSION['user_id'] ?? 0)); }
function pfRequireUser(): int {
    $id = pfUserId();
    if (!$id) {
        header('Location: login.php');
        exit;
    }
    return $id;
}
function pfSignIn(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_name'] = (string)$user['name'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    unset($_SESSION['auth_error'], $_SESSION['failed_logins']);
}
