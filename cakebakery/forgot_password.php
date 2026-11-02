<?php
require_once 'includes/auth.php';
$layout='auth'; $active='login'; $title='Forgot password'; include 'includes/header.php'; ?>
<section class="auth">
  <div><p class="eyebrow">No worries</p><h1>Forgot your <em>password?</em></h1>
  <p class="lead">Contact the shop on the number below and an administrator will reset your password after confirming your identity.</p></div>
  <div class="card auth-card"><h2>Reset help</h2><p class="sub">Call or WhatsApp: 0700 000 000</p>
    <a class="btn btn-block" href="<?= url('login.php') ?>">Back to login</a></div>
</section>
<?php include 'includes/footer.php';
