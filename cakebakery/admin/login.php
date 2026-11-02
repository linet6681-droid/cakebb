<?php
require_once __DIR__.'/../includes/auth.php';
$err = null;
if (current_user() && session_user_is_valid() && current_user()['role'] === 'admin') redirect('admin/index.php');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $err = login_user(trim($_POST['email']??''), $_POST['password']??'', false, true);
    if (!$err) redirect('admin/index.php');
}
no_store_headers();
$layout='auth'; $active='admin-login'; $title='Admin login'; include __DIR__.'/../includes/header.php'; ?>
<section class="auth">
  <div>
    <p class="eyebrow">Staff only</p>
    <h1>Cake &amp; Bakery<br><span class="nw"><em>Admin</em> panel</span></h1>
    <p class="lead">Manage orders, products, customers, payments and delivery destinations.</p>
    <img class="photo" src="<?= img_url('login-cake.png') ?>" alt="Strawberry cake">
  </div>
  <form method="post" class="card auth-card" autocomplete="on">
    <h2>Admin login</h2>
    <p class="sub">Administrator accounts only</p>
    <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
    <?= csrf_field() ?>
    <div class="row"><label class="f" for="email">Email</label>
      <input class="field" id="email" type="email" name="email" placeholder="admin@example.com" required value="<?= e($_POST['email'] ?? '') ?>"></div>
    <div class="row"><label class="f" for="pw">Password</label>
      <input class="field" id="pw" type="password" name="password" placeholder="••••••••••" required></div>
    <button class="btn btn-block">Login</button>
    <p class="alt">Customer? <a href="<?= url('login.php') ?>">Use the customer login</a></p>
  </form>
</section>
<?php include __DIR__.'/../includes/footer.php';
