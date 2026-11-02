<?php
require_once 'includes/auth.php';
$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $err = login_user(trim($_POST['email']??''), $_POST['password']??'', !empty($_POST['remember']));
    if (!$err) redirect(current_user()['role']==='admin' ? 'admin/index.php' : 'index.php');
}
$layout='auth'; $active='login'; $title='Login'; include 'includes/header.php'; ?>
<section class="auth">
  <div>
    <p class="eyebrow">Welcome back</p>
    <h1>Sign in to your<br><span class="nw"><em>Cake &amp; Bakery</em> account</span></h1>
    <p class="lead">Order fresh cakes, cupcakes and bread online, pay with M-Pesa and track your order.</p>
    <img class="photo" src="<?= img_url('login-cake.png') ?>" alt="Strawberry cake">
  </div>
  <form method="post" class="card auth-card" autocomplete="on">
    <h2>Login</h2>
    <p class="sub">Customers and administrators use the same form</p>
    <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
    <?= csrf_field() ?>
    <div class="row"><label class="f" for="email">Email</label>
      <input class="field" id="email" type="email" name="email" placeholder="example@gmail.com" required value="<?= e($_POST['email'] ?? '') ?>"></div>
    <div class="row"><label class="f" for="pw">Password</label>
      <input class="field" id="pw" type="password" name="password" placeholder="••••••••••" required></div>
    <div class="meta"><label><input type="checkbox" name="remember" value="1"> Remember me</label><a href="<?= url('forgot_password.php') ?>">Forgot password?</a></div>
    <button class="btn btn-block">Login</button>
    <p class="alt">New customer? <a href="<?= url('register.php') ?>">Register here</a></p>
  </form>
</section>
<?php include 'includes/footer.php';
