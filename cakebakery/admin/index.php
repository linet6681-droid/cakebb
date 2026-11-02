<?php
require_once __DIR__.'/../includes/auth.php'; require_admin();
$cnt = db()->query("SELECT status, COUNT(*) n FROM orders WHERE deleted_at IS NULL GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$today = db()->query("SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) s FROM orders WHERE DATE(order_date)=CURDATE() AND status<>'cancelled'")->fetch();
$alerts = db()->query("SELECT message, sent_at FROM notifications WHERE message LIKE 'PAYMENT ALERT:%' ORDER BY notification_id DESC LIMIT 6")->fetchAll();
$low = db()->query('SELECT * FROM products WHERE stock_qty <= low_stock_level AND is_active=1 ORDER BY stock_qty')->fetchAll();
$layout='admin'; $active='index'; $section='Dashboard'; $title='Dashboard'; include __DIR__.'/../includes/header.php'; ?>
<div class="stats">
  <div class="stat"><span>New Orders</span><b><?= $cnt['new'] ?? 0 ?></b></div>
  <div class="stat"><span>In Oven</span><b><?= $cnt['in_progress'] ?? 0 ?></b></div>
  <div class="stat"><span>Ready</span><b><?= $cnt['ready'] ?? 0 ?></b></div>
  <div class="stat grad"><span>Delivered</span><b><?= $cnt['delivered'] ?? 0 ?></b></div>
</div>
<div class="stats" style="grid-template-columns:repeat(2,1fr)">
  <div class="stat"><span>Orders today</span><b><?= $today['n'] ?></b></div>
  <div class="stat"><span>Sales today</span><b class="pink"><?= money($today['s']) ?></b></div>
</div>
<div class="panel alerts" style="margin-bottom:28px"><h3>Payment alerts <span class="muted" style="font-weight:400;font-size:.85rem">(updates by itself)</span></h3>
<?php foreach ($alerts as $a): $ok = strpos($a['message'], 'Confirmed') !== false; ?>
  <div class="alert <?= $ok ? 'ok' : 'bad' ?>"><span><?= $ok ? '✔' : '✖' ?></span><div><?= e(substr($a['message'], 14)) ?><small><?= date('d M, g:i A', strtotime($a['sent_at'])) ?></small></div></div>
<?php endforeach; if (!$alerts): ?><p class="muted" style="margin:0">No payments yet. When a customer enters their M-Pesa PIN, the confirmation appears here.</p><?php endif; ?></div>
<div class="tbl-card"><table class="tbl"><thead><tr><th>Low stock item</th><th>Category</th><th>Stock</th></tr></thead><tbody>
<?php foreach ($low as $p): ?><tr><td><b><?= e(product_title($p)) ?></b></td><td><?= $p['category_id']==1?'Cakes':($p['category_id']==2?'Cupcakes':'Bread') ?></td><td><?= $p['stock_qty'] ?> <span class="low">Low</span></td></tr><?php endforeach;
if (!$low): ?><tr><td colspan="3" class="muted">Nothing is running low.</td></tr><?php endif; ?></tbody></table></div>
<div class="actions"><a class="btn" href="orders.php">Manage orders</a><a class="btn-ghost" href="products.php">Manage products</a></div>
<script>setTimeout(function(){ location.reload(); }, 20000);</script>
<?php include __DIR__.'/../includes/footer.php';
