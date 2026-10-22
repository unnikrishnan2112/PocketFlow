<?php
/** Form-based account operations. No GET account changes. */
require_once __DIR__ . '/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
if (!pfVerifyCsrf((string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Security token expired. Go back and refresh the page.'); }
$action = (string)($_POST['action'] ?? '');

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => $params['samesite']]);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}
if (!in_array($action, ['login','register'], true)) { http_response_code(400); exit('Invalid account action'); }
if (pfUserId()) { header('Location: index.php'); exit; }

$mode = $action === 'register' ? 'register' : 'login';
function failAccount(string $message, string $mode, string $email = ''): never {
    $_SESSION['auth_error'] = $message;
    $_SESSION['auth_email'] = $email;
    header('Location: login.php'.($mode === 'register' ? '?mode=register' : ''), true, 303);
    exit;
}
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$password = (string)($_POST['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) failAccount('Enter a valid email address.', $mode);

try {
    $pdo = db();
    if ($action === 'register') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) failAccount('Name must be between 1 and 80 characters.', $mode, $email);
        // PASSWORD_DEFAULT may use bcrypt; 72-byte cap prevents silent truncation on bcrypt.
        if (strlen($password) < 10 || strlen($password) > 72) failAccount('Password must be 10–72 bytes long.', $mode, $email);
        if (!hash_equals($password, (string)($_POST['confirm_password'] ?? ''))) failAccount('Passwords do not match.', $mode, $email);
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $exists->execute([$email]);
        if ($exists->fetch()) failAccount('An account with this email already exists.', $mode, $email);
        $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash) VALUES (?,?,?)');
        try {
            $stmt->execute([$name,$email,password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') failAccount('An account with this email already exists.', $mode, $email);
            throw $e;
        }
        pfSignIn(['id' => $pdo->lastInsertId(), 'name' => $name]);
    } else {
        $now = time();
        $attempts = array_filter((array)($_SESSION['failed_logins'] ?? []), fn($t) => (int)$t > $now - 900);
        if (count($attempts) >= 5) failAccount('Too many attempts. Try again in 15 minutes.', $mode, $email);
        $stmt = $pdo->prepare('SELECT id,name,password_hash FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            $attempts[] = $now;
            $_SESSION['failed_logins'] = $attempts;
            failAccount('Incorrect email or password.', $mode, $email);
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $stmt = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT),$user['id']]);
        }
        pfSignIn($user);
    }
    header('Location: index.php', true, 303);
    exit;
} catch (PDOException $e) {
    error_log('PocketFlow account DB error: '.$e->getMessage());
    failAccount('Cannot connect to the database. Check config.php and import database.sql (or the upgrade SQL).', $mode, $email);
} catch (Throwable $e) {
    error_log('PocketFlow account error: '.$e->getMessage());
    failAccount('Could not complete your request. Please try again.', $mode, $email);
}
