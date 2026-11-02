<?php
require_once 'includes/auth.php';
$errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $errors = register_user($_POST['name']??'', trim($_POST['email']??''), $_POST['phone']??'', $_POST['password']??'');
    if (!$errors) { flash('Account created! Please log in.'); redirect('login.php'); }
}
$layout='auth'; $active='register'; $title='Register'; include 'includes/header.php'; ?>
<section class="auth">
  <div>
    <p class="eyebrow">Join us</p>
    <h1>Create your<br><em>Cake &amp; Bakery</em> account</h1>
    <p class="lead">Save your details for faster checkout, get SMS order updates and track every order.</p>
    <img class="photo" src="<?= img_url('login-cake.png') ?>" alt="Strawberry cake">
  </div>
  <form method="post" class="card auth-card">
    <h2>Register</h2>
    <p class="sub">It only takes a minute</p>
    <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>
    <?= csrf_field() ?>
    <div class="row"><label class="f">Full name</label><input class="field" name="name" placeholder="Amina Wanjiru" required value="<?= e($_POST['name'] ?? '') ?>"></div>
    <div class="row"><label class="f">Email</label><input class="field" type="email" name="email" placeholder="example@gmail.com" required value="<?= e($_POST['email'] ?? '') ?>"></div>
    <div class="row"><label class="f">Phone (M-Pesa)</label><input class="field" name="phone" placeholder="0712 345 678" required value="<?= e($_POST['phone'] ?? '') ?>"></div>
    <div class="row"><label class="f">Password (min 8 characters)</label><input class="field" type="password" name="password" minlength="8" required></div>
    <button class="btn btn-block">Register</button>
    <p class="alt">Already have an account? <a href="<?= url('login.php') ?>">Login here</a></p>
  </form>
</section>
<?php include 'includes/footer.php';
