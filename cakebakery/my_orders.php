<?php
require_once 'includes/auth.php'; require_login();
$st = db()->prepare('SELECT o.*, p.status AS pay_status, p.method,
   (SELECT GROUP_CONCAT(CONCAT(pr.name,IF(oi.quantity>1,CONCAT(" x ",oi.quantity),"")) SEPARATOR ", ") FROM order_items oi JOIN products pr USING(product_id) WHERE oi.order_id=o.order_id) AS items
   FROM orders o LEFT JOIN payments p USING(order_id) WHERE o.user_id=? AND o.deleted_at IS NULL ORDER BY o.order_id DESC');
$st->execute([current_user()['id']]); $orders = $st->fetchAll();
$layout='simple'; $active='orders'; $title='My Orders'; include 'includes/header.php'; ?>
<h1 class="page-title">My Orders</h1>
<div class="tbl-card scroll"><table class="tbl">
  <thead><tr><th>Order ID</th><th>Items</th><th>Date needed</th><th>Destination</th><th>Delivery fee</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($orders as $o): ?>
    <tr><td class="id"><a href="<?= url('track.php?id='.$o['order_id']) ?>" style="text-decoration:none">#<?= $o['order_id'] ?></a></td><td><?= e($o['items']) ?></td><td><?= date('d M, g:i A', strtotime($o['date_needed'])) ?></td>
      <td><?= $o['fulfilment']==='delivery' ? e($o['destination'] ?: 'Delivery').($o['delivery_address'] ? '<br><small class="muted">'.e($o['delivery_address']).'</small>' : '') : 'Pickup at shop' ?></td>
      <td><?= $o['fulfilment']==='delivery' ? ($o['delivery_fee'] > 0 ? money($o['delivery_fee']) : 'Free') : '—' ?></td>
      <td><b><?= money($o['total_amount']) ?></b></td><td><?= pay_badge($o['pay_status']) ?></td><td><?= status_badge($o['status']) ?></td><td><a class="pill-btn pill-edit" href="<?= url('track.php?id='.$o['order_id']) ?>">Track</a></td></tr>
  <?php endforeach; if (!$orders): ?><tr><td colspan="9" class="muted" style="text-align:center;padding:40px">No orders yet. <a href="<?= url('index.php') ?>" style="color:var(--pink)">Start shopping</a></td></tr><?php endif; ?>
  </tbody></table></div>
<?php include 'includes/footer.php';
