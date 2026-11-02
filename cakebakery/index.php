<?php
require_once 'includes/helpers.php';
require_once 'includes/auth.php';
$f = $_GET['f'] ?? 'all';
$q = trim($_GET['q'] ?? '');
$where = ['p.is_active=1']; $args = [];
switch ($f) {
    case 'cakes': case 'cupcakes': case 'bread': $where[] = 'LOWER(c.name)=?'; $args[] = $f; break;
    case 'offers':   $where[] = 'p.is_offer=1'; break;
    case 'best':     $where[] = "p.badge='best_seller'"; break;
    case 'new':      $where[] = "p.badge='new'"; break;
    case 'wedding':  $where[] = "p.occasion='wedding'"; break;
    case 'birthday': $where[] = "p.occasion='birthday'"; break;
    default: $f = 'all';
}
if ($q !== '') { $where[] = '(p.name LIKE ? OR p.description LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
$st = db()->prepare('SELECT p.*, c.name AS category FROM products p JOIN categories c USING(category_id) WHERE '.implode(' AND ', $where).' ORDER BY (p.badge IS NULL), p.product_id');
$st->execute($args); $products = $st->fetchAll();
$titles = ['all'=>'Best Sales','cakes'=>'Cakes','cupcakes'=>'Cupcakes','bread'=>'Bread','offers'=>'Offers','best'=>'Best Sellers','new'=>'New Arrivals','wedding'=>'Wedding Cakes','birthday'=>'Birthday Cakes'];
$layout='shop'; $active='shop'; $title='Shop'; include 'includes/header.php'; ?>
<section class="hero">
  <div><small>Fresh from the oven</small><h2>Order 24 hours ahead for custom cakes</h2>
    <a class="btn-black" href="#products">Order Now</a></div>
  <img src="<?= img_url('hero-cake.png') ?>" alt="">
</section>

<div class="chips">
  <?php foreach (['all'=>'All','cakes'=>'Cakes','cupcakes'=>'Cupcakes','bread'=>'Bread','offers'=>'Offers'] as $k=>$l): ?>
    <a class="chip <?= $f===$k?'on':'' ?>" href="<?= url('index.php'.($k==='all'?'':'?f='.$k)) ?>"><?= $l ?></a>
  <?php endforeach; ?>
  <span class="quick">
    <?php $qs = ['best'=>'Best sellers','new'=>'New arrivals','wedding'=>'Wedding cakes','birthday'=>'Birthday cakes']; $i=0;
    foreach ($qs as $k=>$l): if ($i++) echo '<i>|</i>'; ?><a class="<?= $f===$k?'on':'' ?>" href="<?= url('index.php?f='.$k) ?>"><?= $l ?></a><?php endforeach; ?>
  </span>
</div>

<h2 class="section-title" id="products"><?= $q !== '' ? 'Results for “'.e($q).'”' : e($titles[$f]) ?></h2>
<div class="grid">
<?php foreach ($products as $p): $out = $p['stock_qty'] <= 0; ?>
  <article class="pcard">
    <?php if ($out): ?><span class="tag out">Out of stock</span>
    <?php elseif ($p['badge']): ?><span class="tag"><?= $p['badge']==='new' ? 'New' : 'Best seller' ?></span><?php endif; ?>
    <a class="plink" href="<?= url('product.php?id='.$p['product_id']) ?>" title="View details">
      <div class="imgbox"><img src="<?= img_url($p['image']) ?>" alt="<?= e($p['name']) ?>"></div>
      <h3><?= e(product_title($p)) ?></h3></a>
    <a class="pmore" href="<?= url('product.php?id='.$p['product_id']) ?>">View details</a>
    <div class="buy">
      <span class="price"><?= money($p['price']) ?></span>
      <?php if ($out): ?><button class="btn" disabled>Unavailable</button>
      <?php else: ?>
        <form method="post" action="<?= url('cart.php') ?>"><?= csrf_field() ?>
          <input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= $p['product_id'] ?>">
          <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>"><button class="btn">Add to cart</button></form>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; if (!$products): ?><p class="empty">No products found.</p><?php endif; ?>
</div>
<?php include 'includes/footer.php';
