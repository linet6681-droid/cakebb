<?php
require_once 'includes/auth.php';
$layout='auth'; $active='contact'; $title='Contact us'; include 'includes/header.php'; ?>
<section class="auth">
  <div><p class="eyebrow">We'd love to hear from you</p><h1>Contact <em>Us</em></h1>
  <p class="lead">Custom cake enquiries, bulk bread orders or delivery questions? Reach us any day 7am – 7pm.</p></div>
  <div class="card auth-card"><h2>Cake &amp; Bakery Shop</h2>
    <p class="sub">Phone / WhatsApp: 0700 000 000<br>Email: orders@cakebakery.local<br>Location: Machakos Town</p>
    <a class="btn btn-block" href="<?= url('index.php') ?>">Browse the shop</a></div>
</section>
<?php include 'includes/footer.php';
