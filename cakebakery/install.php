<?php
/**
 * One-time installer: creates the database, all tables, sample products and YOUR admin account.
 * Open http://localhost/cakebakery/install.php   (no phpMyAdmin import needed).
 * It locks itself once an admin exists. Delete this file afterwards.
 */
require_once __DIR__ . '/config/config.php';
ini_set('display_errors', '0');
if (session_status() === PHP_SESSION_NONE) session_start();

function split_sql(string $sql): array {
    $sql = str_replace(['CREATE DATABASE IF NOT EXISTS cakeb', 'USE cakeb;'], ['CREATE DATABASE IF NOT EXISTS `'.DB_NAME.'`', 'USE `'.DB_NAME.'`;'], $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);                 // whole-line comments
    $out = [];
    foreach (preg_split('/;\s*[\r\n]+/', $sql) as $stmt) { $stmt = trim($stmt, " \t\r\n;"); if ($stmt !== '') $out[] = $stmt; }
    return $out;
}

$locked = false; $errors = []; $done = false;
try {   // already installed?  (database + admin present)
    $chk = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($chk->query("SHOW TABLES LIKE 'users'")->fetchColumn() && (int)$chk->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() > 0) $locked = true;
} catch (PDOException $e) { /* no database yet: expected on first run */ }

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['inst'] ?? 'x', $_POST['t'] ?? '')) { $errors[] = 'Form expired. Refresh the page and try again.'; }
    $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $pass = $_POST['password'] ?? '';
    if (strlen($name) < 2) $errors[] = 'Enter the admin name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid admin email.';
    if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
    if (!$errors) {
        try {
            $pdo = new PDO('mysql:host='.DB_HOST.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach (split_sql(file_get_contents(__DIR__.'/sql/schema.sql')) as $stmt) $pdo->exec($stmt);
            $pdo->exec('USE `'.DB_NAME.'`');
            $pdo->prepare("INSERT INTO users(full_name,email,phone,password_hash,role) VALUES(?,?,?,?, 'admin')")
                ->execute([$name, $email, '254700000000', password_hash($pass, PASSWORD_DEFAULT)]);
            $done = true;
        } catch (PDOException $e) {
            $m = $e->getMessage();
            $errors[] = (stripos($m, 'refused') !== false || stripos($m, 'No such host') !== false || ($e->errorInfo[1] ?? 0) == 2002)
                ? 'Cannot reach MySQL. Open the XAMPP Control Panel and START MySQL, then try again.'
                : ((($e->errorInfo[1] ?? 0) == 1045) ? 'MySQL refused the login. Check DB_USER and DB_PASS in config/config.php (XAMPP default: root with an empty password).'
                : 'Setup failed: '.$m);
        }
    }
}
$_SESSION['inst'] = $_SESSION['inst'] ?? bin2hex(random_bytes(16));
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install | Cake &amp; Bakery</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css"></head>
<body class="layout-auth"><header class="topbar"><a class="logo" href="#"><span class="logo-icon"><svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M12 21s-7.5-4.6-9.6-9.2C1 8.6 2.8 5 6.3 5c2 0 3.5 1 4.2 2.3h3C14.2 6 15.7 5 17.7 5c3.5 0 5.3 3.6 3.9 6.8C19.5 16.4 12 21 12 21z"/></svg></span>CAKE &amp; BAKERY</a></header>
<main class="wrap"><section class="auth">
  <div><p class="eyebrow">First-time setup</p><h1>Set up your <span class="nw"><em>Cake &amp; Bakery</em> shop</span></h1>
    <p class="lead">This creates the database <b><?= h(DB_NAME) ?></b>, all tables and sample products, and your administrator account. You do not need phpMyAdmin.</p></div>
  <div class="card auth-card">
  <?php if ($done): ?>
    <h2>All set!</h2><p class="sub">Database created and admin account saved.</p>
    <a class="btn btn-block" href="<?= BASE_URL ?>/login.php">Go to login</a>
    <p class="alt">For safety, delete <b>install.php</b> from the project folder.</p>
  <?php elseif ($locked): ?>
    <h2>Already installed</h2><p class="sub">An admin account already exists, so the installer is locked.</p>
    <a class="btn btn-block" href="<?= BASE_URL ?>/login.php">Go to login</a><p class="alt">Please delete <b>install.php</b>.</p>
  <?php else: ?>
    <h2>Create admin</h2><p class="sub">You will log in with these details</p>
    <?php foreach ($errors as $er): ?><p class="err"><?= h($er) ?></p><?php endforeach; ?>
    <form method="post"><input type="hidden" name="t" value="<?= h($_SESSION['inst']) ?>">
      <div class="row"><label class="f">Admin name</label><input class="field" name="name" required value="<?= h($_POST['name'] ?? '') ?>"></div>
      <div class="row"><label class="f">Admin email</label><input class="field" type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>"></div>
      <div class="row"><label class="f">Admin password (min 8 characters)</label><input class="field" type="password" name="password" minlength="8" required></div>
      <button class="btn btn-block">Install</button>
    </form>
    <p class="alt" style="font-size:.85rem">Warning: this resets the <b><?= h(DB_NAME) ?></b> database.</p>
  <?php endif; ?>
  </div></section></main></body></html>
