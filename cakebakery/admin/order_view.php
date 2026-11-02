<?php
require_once __DIR__.'/../includes/auth.php'; require_admin();
require_once __DIR__.'/_order_actions.php';
$id = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD']==='POST') handle_order_post('admin/order_view.php?id='.$id);
$st = db()->prepare('SELECT o.*, u.email, u.full_name account_name, p.method, p.status pay_status, p.amount pay_amount, p.paid_amount, p.mpesa_receipt, p.payer_phone, p.checkout_request_id, p.paid_at, p.result_desc
   FROM orders o LEFT JOIN users u ON u.user_id=o.user_id LEFT JOIN payments p ON p.order_id=o.order_id WHERE o.order_id=? AND o.deleted_at IS NULL');
$st->execute([$id]); $o = $st->fetch();
if (!$o) { flash('That order does not exist (or was deleted).'); redirect('admin/orders.php'); }
$it = db()->prepare('SELECT oi.*, p.name, p.pack_size FROM order_items oi JOIN products p USING(product_id) WHERE oi.order_id=?'); $it->execute([$id]); $items = $it->fetchAll();
$subtotal = $o['total_amount'] - $o['delivery_fee'];
$layout='admin'; $active='orders'; $section='Order #'.$id; $title='Order #'.$id; include __DIR__.'/../includes/header.php'; ?>
<div class="head-row"><h1>Order #<?= $id ?> <?= status_badge($o['status']) ?></h1><a class="btn-ghost" href="<?= url('admin/orders.php') ?>">← All orders</a></div>
<div class="two" style="align-items:start">
  <div class="panel"><h3>Customer</h3>
    <div class="r"><span>Name</span><b><?= e($o['contact_name']) ?></b></div>
    <div class="r"><span>Phone</span><b><?= e(display_phone($o['contact_phone'])) ?></b></div>
    <div class="r"><span>Email</span><b><?= e($o['email'] ?? '—') ?></b></div>
    <div class="r"><span>Placed</span><b><?= date('d M Y, g:i A', strtotime($o['order_date'])) ?></b></div>
    <div class="r"><span>Needed</span><b><?= date('d M Y, g:i A', strtotime($o['date_needed'])) ?></b></div>
    <?php if ($o['special_instructions']): ?><div class="r"><span>Instructions</span><b><?= e($o['special_instructions']) ?></b></div><?php endif; ?></div>
  <div class="panel"><h3><?= $o['fulfilment']==='delivery' ? 'Delivery' : 'Pickup' ?></h3>
    <div class="r"><span>Destination</span><b><?= $o['fulfilment']==='delivery' ? e($o['destination'] ?: 'Delivery') : 'Pickup at the shop' ?></b></div>
    <?php if ($o['fulfilment']==='delivery'): ?><div class="r"><span>Address</span><b><?= e($o['delivery_address'] ?: '—') ?></b></div><?php endif; ?>
    <div class="r"><span>Delivery fee</span><b><?= $o['delivery_fee'] > 0 ? money($o['delivery_fee']) : 'Free' ?></b></div>
    <div class="r"><span>Subtotal</span><b><?= money($subtotal) ?></b></div>
    <div class="r"><span>Order total</span><b><?= money($o['total_amount']) ?></b></div></div>
</div>
<div class="tbl-card scroll" style="margin-top:18px"><table class="tbl"><thead><tr><th>Product</th><th>Qty</th><th>Unit price</th><th>Line total</th><th>Message on cake</th></tr></thead><tbody>
<?php foreach ($items as $r): ?><tr><td><b><?= e($r['name']) ?></b><?= $r['pack_size'] ? ' (box of '.(int)$r['pack_size'].')' : '' ?></td><td><?= (int)$r['quantity'] ?></td><td><?= money($r['unit_price']) ?></td><td><?= money($r['unit_price']*$r['quantity']) ?></td><td><?= e($r['cake_message'] ?: '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="panel" style="margin-top:18px"><h3>Payment <?= pay_badge($o['pay_status']) ?></h3>
  <div class="r"><span>Method</span><b><?= $o['method']==='mpesa' ? 'M-Pesa STK Push' : 'Pay on pickup' ?></b></div>
  <div class="r"><span>Amount due</span><b><?= money($o['pay_amount']) ?></b></div>
  <?php if ($o['method']==='mpesa'): ?>
    <div class="r"><span>M-Pesa number</span><b><?= e($o['payer_phone'] ? display_phone($o['payer_phone']) : '—') ?></b></div>
    <div class="r"><span>Receipt</span><b><?= e($o['mpesa_receipt'] ?: '—') ?></b></div>
    <?php if ($o['paid_amount'] !== null): ?><div class="r"><span>Amount received</span><b><?= money($o['paid_amount']) ?></b></div><?php endif; ?>
    <?php if ($o['paid_at']): ?><div class="r"><span>Paid at</span><b><?= date('d M Y, g:i A', strtotime($o['paid_at'])) ?></b></div><?php endif; ?>
    <?php if ($o['pay_status']!=='paid' && $o['result_desc']): ?><div class="r"><span>Last result</span><b><?= e($o['result_desc']) ?></b></div><?php endif; ?>
  <?php endif; ?></div>
<div class="actions no-print">
  <form method="post" style="display:inline-flex;gap:8px;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="order_id" value="<?= $id ?>">
    <select class="field" name="status" style="width:auto"><?php foreach (STATUS_LABELS as $k=>$l): ?><option value="<?= $k ?>" <?= $k===$o['status']?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select>
    <button class="btn">Update status</button></form>
  <?php if ($o['status'] !== 'cancelled' && $o['status'] !== 'delivered'): ?>
  <form method="post" style="display:inline" onsubmit="return confirm('Cancel order #<?= $id ?>? Stock is returned and the customer is notified. This cannot be undone.')"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="order_id" value="<?= $id ?>"><button class="btn-ghost">Cancel order</button></form><?php endif; ?>
  <?php if ($o['status'] === 'delivered'): ?>
  <form method="post" style="display:inline" onsubmit="return confirm('Delete delivered order #<?= $id ?>? It will disappear from the lists; the payment record is kept for accounting.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="order_id" value="<?= $id ?>"><button class="btn-ghost">Delete order</button></form><?php endif; ?>
</div>
<?php include __DIR__.'/../includes/footer.php';
