<?php
require_once 'includes/helpers.php';
require_once 'includes/auth.php';
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT p.*, c.name AS category FROM products p JOIN categories c USING(category_id) WHERE p.product_id=? AND p.is_active=1');
$st->execute([$id]); $p = $st->fetch();
if (!$p) { http_response_code(404); include '404.php'; exit; }
$out = $p['stock_qty'] <= 0;
$desc = trim((string)$p['description']);
$facts = array_filter([
    'Category' => $p['category'],
    'Size' => $p['size_label'] ?: null,
    'Pack' => $p['pack_size'] ? $p['pack_size'].' pieces per box' : null,
    'Occasion' => $p['occasion'] ? ucfirst($p['occasion']) : null,
]);
$layout='shop'; $active='shop'; $title=$p['name']; include 'includes/header.php'; ?>
<p class="crumbs"><a href="<?= url('index.php') ?>">Shop</a> › <a href="<?= url('index.php?f='.strtolower($p['category'])) ?>"><?= e($p['category']) ?></a> › <?= e($p['name']) ?></p>
<article class="pdetail">
  <div class="pd-img"><img src="<?= img_url($p['image']) ?>" alt="<?= e($p['name']) ?>"></div>
  <div class="pd-info">
    <?php if ($out): ?><span class="tag out" style="position:static;display:inline-block">Out of stock</span>
    <?php elseif ($p['badge']): ?><span class="tag" style="position:static;display:inline-block"><?= $p['badge']==='new' ? 'New' : 'Best seller' ?></span><?php endif; ?>
    <h1><?= e(product_title($p)) ?></h1>
    <div class="pd-price"><?= money($p['price']) ?></div>
    <h2>About this product</h2>
    <p class="pd-desc"><?= $desc !== '' ? nl2br(e($desc)) : 'Freshly baked in our kitchen. Ask us on the contact page if you would like more details.' ?></p>
    <dl class="pd-facts"><?php foreach ($facts as $k=>$v): ?><div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div><?php endforeach; ?>
      <div><dt>Availability</dt><dd><?= $out ? 'Out of stock' : ($p['stock_qty'] <= $p['low_stock_level'] ? 'Only '.(int)$p['stock_qty'].' left' : 'In stock') ?></dd></div></dl>
    <?php if ($p['category_id'] == 1): ?><p class="muted" style="font-size:.9rem">Cakes must be ordered at least 24 hours ahead. You can add a message to write on the cake in your cart.</p><?php endif; ?>
    <div class="pd-buy">
      <?php if ($out): ?><button class="btn" disabled>Unavailable</button>
      <?php else: ?>
        <form method="post" action="<?= url('cart.php') ?>"><?= csrf_field() ?>
          <input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= $p['product_id'] ?>">
          <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>"><button class="btn">Add to cart</button></form>
      <?php endif; ?>
      <a class="btn-outline" href="<?= url('index.php') ?>">← Keep shopping</a>
    </div>
  </div>
</article>
<?php include 'includes/footer.php';
