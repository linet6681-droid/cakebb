<?php
require_once 'includes/auth.php'; http_response_code(404);
$layout='simple'; $active=''; $title='Page not found'; include 'includes/header.php'; ?>
<div class="card empty-cart"><h2>Page not found</h2><p class="muted">That page doesn't exist.</p><a class="btn" href="<?= url('index.php') ?>">Back to the shop</a></div>
<?php include 'includes/footer.php';
