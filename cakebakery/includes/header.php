<?php
// Usage: set $layout ('shop'|'simple'|'auth'|'admin'), $active, $section, $title BEFORE including.
require_once __DIR__ . '/auth.php';
$u = current_user();
$layout  = $layout  ?? 'shop';
$active  = $active  ?? '';
$section = $section ?? '';
$cartCount = count($_SESSION['cart'] ?? []);
$f = $_GET['f'] ?? 'all';
function nav_a(string $href, string $label, bool $on): string { return '<a href="'.e($href).'"'.($on?' class="on"':'').'>'.$label.'</a>'; }
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title ?? 'Cake & Bakery') ?> | Cake &amp; Bakery</title>
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head><body class="layout-<?= e($layout) ?>">
<?php if ($layout === 'admin'): ?>
<header class="topbar admin-top">
  <?= logo_html(url('admin/index.php')) ?>
   <nav class="topnav"><a href="<?= url('admin/index.php') ?>">Admin Panel</a><a class="on" href="<?= e(strtok($_SERVER['REQUEST_URI'] ?? '#', '?')) ?>"><?= e($section) ?></a></nav>
  <div class="top-right"><span class="who" title="Signed in as administrator"><?= e($u['name'] ?? 'Admin') ?></span>
    <a class="btn-outline" href="<?= url('index.php') ?>">View shop</a><?= logout_form('Logout', 'btn-outline logout-btn', 'admin') ?></div>
</header>
<div class="admin-shell">
  <aside class="sidebar">
    <?php foreach (['index'=>'Dashboard','products'=>'Products','orders'=>'Orders','customers'=>'Customers','payments'=>'Payments','delivery'=>'Delivery','reports'=>'Reports'] as $k=>$l): ?>
      <a href="<?= url('admin/'.$k.'.php') ?>" class="<?= $active===$k?'on':'' ?>"><?= $l ?></a>
    <?php endforeach; ?>
    <hr><?= logout_form('Logout', 'logout-side', 'admin') ?>
  </aside>
  <section class="admin-main">
<?php else: ?>
<header class="topbar">
  <?= logo_html(url('index.php')) ?>
  <?php if ($layout === 'shop'): ?>
    <nav class="topnav">
      <?= nav_a(url('index.php'), 'Shop', $active==='shop' && $f==='all') ?>
      <?= nav_a(url('index.php?f=cakes'), 'Cakes', $f==='cakes') ?>
      <?= nav_a(url('index.php?f=cupcakes'), 'Cupcakes', $f==='cupcakes') ?>
      <?= nav_a(url('index.php?f=bread'), 'Bread', $f==='bread') ?>
      <?= nav_a(url('index.php?f=offers'), 'Offers', $f==='offers') ?>
    </nav>
    <div class="top-right">
      <form class="search" action="<?= url('index.php') ?>" method="get"><span><i class='bx bx-search'></i></span><input name="q" placeholder="Search cakes, cupcakes, bread…" value="<?= e($_GET['q'] ?? '') ?>"></form>
      <a class="btn-outline" href="<?= url('cart.php') ?>">Cart (<?= $cartCount ?>)</a>
      <?php if ($u): ?><a class="usericon" title="My orders (<?= e($u['name']) ?>)" href="<?= url('my_orders.php') ?>"><i class='bx bx-user'></i></a><?= logout_form('Logout', 'btn-outline logout-btn') ?>
      <?php else: ?><a class="btn-outline" href="<?= url('login.php') ?>">Login</a><?php endif; ?>
    </div>
  <?php elseif ($layout === 'simple'): ?>
    <nav class="topnav">
      <?= nav_a(url('index.php'), 'Shop', false) ?>
      <?= nav_a(url('cart.php'), 'Cart &amp; Checkout', $active==='cart') ?>
      <?php if ($u): ?><?= nav_a(url('my_orders.php'), 'My Orders', $active==='orders') ?><?php endif; ?>
    </nav>
    <div class="top-right"><a class="btn-outline" href="<?= url('cart.php') ?>">Cart (<?= $cartCount ?>)</a>
      <?php if ($u): ?><a class="usericon" title="<?= e($u['name']) ?>" href="<?= url('my_orders.php') ?>">👤</a><?= logout_form('Logout', 'btn-outline logout-btn') ?><?php endif; ?></div>
  <?php else: /* auth */ ?>
    <nav class="topnav">
      <?= nav_a(url('index.php?f=cakes'), 'Cakes', false) ?><?= nav_a(url('index.php?f=cupcakes'), 'Cupcakes', false) ?>
      <?= nav_a(url('index.php?f=bread'), 'Bread', false) ?><?= nav_a(url('contact.php'), 'Contact Us', false) ?>
    </nav>
    <div class="top-right"><?php if ($active==='admin-login'): ?><a class="btn-outline" href="<?= url('login.php') ?>">Customer login</a><?php elseif ($active==='register'): ?><a class="btn-outline" href="<?= url('login.php') ?>">Login</a><?php else: ?><a class="btn-outline" href="<?= url('register.php') ?>">Register</a><?php endif; ?></div>
  <?php endif; ?>
</header>
<main class="wrap">
<?php endif; ?>
<?php if ($m = flash()): ?><div class="toast" role="status"><?= e($m) ?></div><?php endif; ?>
