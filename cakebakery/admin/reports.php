<?php
require_once __DIR__.'/../includes/auth.php'; require_admin();
$period = in_array($_GET['p'] ?? '', ['weekly','daily']) ? $_GET['p'] : 'monthly';

// Build buckets: key => [label, total]
$buckets = []; $today = new DateTimeImmutable('today');
if ($period === 'monthly') {
    $start = $today->modify('first day of this month')->modify('-5 months');
    for ($i=0;$i<6;$i++) { $d = $start->modify("+$i months"); $buckets[$d->format('Y-m')] = [$d->format('M'), 0]; }
    $keyOf = fn($d) => date('Y-m', strtotime($d)); $title2 = 'Sales per month (Ksh)';
} elseif ($period === 'weekly') {
    $start = $today->modify('monday this week')->modify('-5 weeks');
    for ($i=0;$i<6;$i++) { $d = $start->modify("+$i weeks"); $buckets[$d->format('Y-m-d')] = [$d->format('d M'), 0]; }
    $keyOf = fn($d) => date('Y-m-d', strtotime('monday this week', strtotime($d))); $title2 = 'Sales per week (Ksh) — week starting';
} else {
    $start = $today->modify('-6 days');
    for ($i=0;$i<7;$i++) { $d = $start->modify("+$i days"); $buckets[$d->format('Y-m-d')] = [$d->format('D d'), 0]; }
    $keyOf = fn($d) => date('Y-m-d', strtotime($d)); $title2 = 'Sales per day (Ksh)';
}
$from = $start->format('Y-m-d 00:00:00');
$st = db()->prepare("SELECT order_date, total_amount FROM orders WHERE status<>'cancelled' AND order_date>=?"); $st->execute([$from]);
$orders = 0; $total = 0;
foreach ($st as $r) { $k = $keyOf($r['order_date']); if (isset($buckets[$k])) { $buckets[$k][1] += $r['total_amount']; $total += $r['total_amount']; $orders++; } }
$st = db()->prepare("SELECT CONCAT(p.name) n, SUM(oi.quantity) q FROM order_items oi JOIN products p USING(product_id) JOIN orders o USING(order_id) WHERE o.status<>'cancelled' AND o.order_date>=? GROUP BY p.product_id ORDER BY q DESC LIMIT 1");
$st->execute([$from]); $best = $st->fetch();
$lowCount = (int)db()->query('SELECT COUNT(*) FROM products WHERE is_active=1 AND stock_qty <= low_stock_level')->fetchColumn();
$max = max(array_column($buckets, 1) ?: [0]) ?: 1;
$layout='admin'; $active='reports'; $section='Sales Reports'; $title='Sales Reports'; include __DIR__.'/../includes/header.php'; ?>
<div class="head-row">
  <div class="seg"><?php foreach (['monthly'=>'Monthly','weekly'=>'Weekly','daily'=>'Daily'] as $k=>$l): ?><a class="<?= $period===$k?'on':'' ?>" href="?p=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
  <button class="btn-black no-print" onclick="window.print()">Export PDF</button>
</div>
<div class="stats">
  <div class="stat grad"><span>Total Sales</span><b>Ksh <?= num($total) ?></b></div>
  <div class="stat"><span>Orders</span><b><?= $orders ?></b></div>
  <div class="stat"><span>Best Seller</span><b class="sm"><?= e($best['n'] ?? '—') ?></b></div>
  <div class="stat"><span>Low Stock</span><b class="pink"><?= $lowCount ?> item<?= $lowCount===1?'':'s' ?></b></div>
</div>
<div class="card chart"><h3><?= e($title2) ?></h3>
  <div class="bars"><?php foreach ($buckets as [$lab,$val]): ?><div class="bar" style="height:<?= round($val/$max*100) ?>%" title="<?= e($lab) ?>: Ksh <?= num($val) ?>"></div><?php endforeach; ?></div>
  <div class="labels"><?php foreach ($buckets as [$lab,$val]): ?><span><?= e($lab) ?></span><?php endforeach; ?></div>
</div>
<?php include __DIR__.'/../includes/footer.php';
