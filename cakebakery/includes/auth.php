<?php
require_once __DIR__ . '/helpers.php';

function current_user(): ?array { return $_SESSION['user'] ?? null; }

/** Forget the session completely: data, cookie and server-side file. */
function destroy_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies') && PHP_SAPI !== 'cli' && !headers_sent()) {
        $c = session_get_cookie_params();
        setcookie(session_name(), '', ['expires'=>time()-42000, 'path'=>$c['path'], 'domain'=>$c['domain'], 'secure'=>$c['secure'], 'httponly'=>true, 'samesite'=>'Lax']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}
/** Re-checks the signed-in person in the DATABASE on every protected request (deleted or demoted accounts lose access at once). */
function session_user_is_valid(): bool {
    $u = current_user(); if (!$u) return false;
    $st = db()->prepare('SELECT role, deleted_at FROM users WHERE user_id=?'); $st->execute([$u['id']]);
    $row = $st->fetch();
    if (!$row || $row['deleted_at']) return false;
    $_SESSION['user']['role'] = $row['role'];
    return true;
}
function no_store_headers(): void { if (!headers_sent()) { header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache'); header('Expires: 0'); } }

function require_login(): void {
    no_store_headers();
    if (!current_user() || !session_user_is_valid()) {
        if (current_user()) { destroy_session(); session_start(); }
        flash('Please log in first.'); redirect('login.php');
    }
}
function require_admin(): void {
    no_store_headers();
    if (!current_user() || !session_user_is_valid()) {
        if (current_user()) { destroy_session(); session_start(); }
        flash('Please log in as an administrator.'); redirect('admin/login.php');
    }
    if (current_user()['role'] !== 'admin') {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not allowed</title><body style="font-family:sans-serif;text-align:center;padding:12vh 20px;background:#fff0f6"><h1>Administrators only</h1><p>Your account does not have permission to open this page.</p><p><a href="'.e(url('index.php')).'">Back to the shop</a></p></body>';
        exit;
    }
}

function register_user(string $name, string $email, string $phone, string $password): array {
    $errors = [];
    $phone = normalize_phone($phone);
    if (strlen(trim($name)) < 2) $errors[] = 'Enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
    if (!$phone) $errors[] = 'Enter a valid Kenyan phone number.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($errors) return $errors;
    $st = db()->prepare('SELECT 1 FROM users WHERE email=?'); $st->execute([$email]);
    if ($st->fetch()) return ['Email already registered.'];
    db()->prepare('INSERT INTO users(full_name,email,phone,password_hash) VALUES(?,?,?,?)')
        ->execute([trim($name), $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
    return [];
}

/** Fig 2.3.1 flowchart: validate, lock after 3 failures, route by role. $adminOnly = the administrator login page. */
function login_user(string $email, string $password, bool $remember = false, bool $adminOnly = false): ?string {
    $st = db()->prepare('SELECT * FROM users WHERE email=?'); $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || $u['deleted_at']) return 'Invalid email or password.';
    if ($u['locked_until'] && strtotime($u['locked_until']) > time()) return 'Account locked. Try again later.';
    if (!password_verify($password, $u['password_hash'])) {
        $n = $u['failed_attempts'] + 1;
        if ($n >= MAX_FAILED_LOGINS) {
            db()->prepare('UPDATE users SET failed_attempts=0, locked_until=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE user_id=?')->execute([LOCK_MINUTES, $u['user_id']]);
            return 'Too many failed attempts. Account locked for '.LOCK_MINUTES.' minutes.';
        }
        db()->prepare('UPDATE users SET failed_attempts=? WHERE user_id=?')->execute([$n, $u['user_id']]);
        return 'Invalid email or password.';
    }
    if ($adminOnly && $u['role'] !== 'admin') return 'Invalid email or password.';   // customers get no hint that this is the admin door
    db()->prepare('UPDATE users SET failed_attempts=0, locked_until=NULL WHERE user_id=?')->execute([$u['user_id']]);
    if (!headers_sent()) session_regenerate_id(true);
    $keep = $_SESSION['cart'] ?? [];
    $_SESSION['user'] = ['id'=>$u['user_id'],'name'=>$u['full_name'],'phone'=>$u['phone'],'role'=>$u['role']];
    $_SESSION['cart'] = $keep;
    if ($remember) setcookie(session_name(), session_id(), ['expires'=>time()+30*86400,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
    return null;
}
