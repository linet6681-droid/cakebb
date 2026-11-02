<?php
require_once __DIR__ . '/../config/db.php';

// ---- bootstrap: safe errors, secure session cookie, security headers ----
ini_set('display_errors', '0');                       // never show stack traces to visitors
ini_set('log_errors', '1'); ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
if (PHP_SAPI !== 'cli') {
    set_exception_handler(function (Throwable $t) {
        error_log($t->getMessage().' @ '.$t->getFile().':'.$t->getLine());
        // Tables missing (1146) = database exists but was never set up -> installer.
        if ($t instanceof PDOException && ($t->errorInfo[1] ?? 0) == 1146 && is_file(__DIR__.'/../install.php') && !headers_sent()) { header('Location: '.BASE_URL.'/install.php'); exit; }
        http_response_code(500);
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title><body style="font-family:sans-serif;text-align:center;padding:12vh 20px;background:#fff0f6"><h1>Oops, something went wrong</h1><p>Please try again in a moment.</p><p><a href="'.BASE_URL.'/index.php" style="color:#e8407e">Back to the shop</a></p></body>';
        exit;
    });
    header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
    if (!defined('NO_SESSION') && session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly'=>true, 'samesite'=>'Lax', 'secure'=>!empty($_SERVER['HTTPS'])]);
        session_start();
    }
    // Signed-in pages must never be kept by the browser: after logout the Back button then cannot show them again.
    if (!empty($_SESSION['user'])) { header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache'); header('Expires: 0'); }
} elseif (session_status() === PHP_SESSION_NONE) { @session_start(); }

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n): string { return 'Ksh ' . number_format((float)$n, 0); }
function num($n): string { return number_format((float)$n, 0); }
function url(string $p = ''): string { return BASE_URL . '/' . ltrim($p, '/'); }
function redirect(string $path): void { header('Location: ' . url($path)); exit; }
function flash(?string $msg = null) {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return; }
    $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m;
}
/** A real <form> (POST + CSRF token) so a stranger's link or image cannot log you out, and the session is destroyed on the server. */
function logout_form(string $label = 'Logout', string $class = 'btn-outline', string $to = 'customer'): string {
    return '<form method="post" action="'.e(url('logout.php')).'" class="logout-form">'.csrf_field().'<input type="hidden" name="to" value="'.e($to).'"><button class="'.e($class).'" type="submit">'.e($label).'</button></form>';
}
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.csrf_token().'">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Invalid form token. Go back and refresh the page.'); }
}
/** Normalise Kenyan numbers to 2547XXXXXXXX; returns null if invalid. */
function normalize_phone(string $p): ?string {
    $p = preg_replace('/\D/', '', $p);
    if (preg_match('/^0([17]\d{8})$/', $p, $m)) return '254'.$m[1];
    if (preg_match('/^254([17]\d{8})$/', $p, $m)) return '254'.$m[1];
    return null;
}
/** Delivery destinations + fees (the fee is ALWAYS read from here on the server, never from the browser). */
function delivery_zones(bool $onlyActive = true): array {
    return db()->query('SELECT * FROM delivery_zones '.($onlyActive ? 'WHERE is_active=1 ' : '').'ORDER BY fee, name')->fetchAll();
}
/** 254712345678 -> 0712 345 678 */
function display_phone(string $p): string { return preg_match('/^254([17]\d{2})(\d{3})(\d{3})$/', $p, $m) ? '0'.$m[1].' '.$m[2].' '.$m[3] : $p; }
function img_url(string $f): string { return url('assets/img/'.$f); }
/** "Vanilla Cupcakes (6)" – name as shown in the catalogue and product table. */
function product_title(array $p): string { return $p['name'] . (!empty($p['pack_size']) ? ' ('.$p['pack_size'].')' : ''); }

const STATUS_LABELS = ['new'=>'New','in_progress'=>'Baking','ready'=>'Ready','delivered'=>'Delivered','cancelled'=>'Cancelled'];
const PAY_LABELS    = ['paid'=>'Paid','pending'=>'Pending','failed'=>'Failed'];
function status_badge(string $s): string { return '<span class="pill st-'.e($s).'">'.e(STATUS_LABELS[$s] ?? $s).'</span>'; }
function pay_badge(?string $s): string { $s = $s ?: 'pending'; return '<span class="pill pay-'.e($s).'">'.e(PAY_LABELS[$s] ?? $s).'</span>'; }

function logo_html(string $href): string {
    return '<a class="logo" href="'.e($href).'">CAKE &amp; BAKERY</a>';
}
