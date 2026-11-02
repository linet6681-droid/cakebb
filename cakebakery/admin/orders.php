<?php
require_once __DIR__.'/../includes/auth.php'; require_admin();
require_once __DIR__.'/_order_actions.php';
$userFilter = (int)($_GET['user'] ?? 0);
if ($_SERVER['REQUEST_METHOD']==='POST') handle_order_post('admin/orders.php'.($userFilter ? '?user='.$userFilter : ''));

$cnt = db()->query("SELECT status, COUNT(*) n FROM orders WHERE deleted_at IS NULL GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$where = 'o.deleted_at IS NULL'; $args = [];
if ($userFilter) { $where .= ' AND o.user_id=?'; $args[] = $userFilter; }
$limit = isset($_GET['all']) ? 300 : 15;
$st = db()->prepare('SELECT o.*, p.status pay_status, p.method,
   (SELECT GROUP_CONCAT(CONCAT(pr.name, IF(oi.quantity>1, CONCAT(" x ",oi.quantity*COALESCE(pr.pack_size,1)), "")) SEPARATOR ", ") FROM order_items oi JOIN products pr USING(product_id) WHERE oi.order_id=o.order_id) items
   FROM orders o LEFT JOIN payments p USING(order_id) WHERE '.$where.' ORDER BY o.order_id DESC LIMIT '.$limit);
$st->execute($args); $orders = $st->fetchAll();
$layout='admin'; $active='orders'; $section='Order Management'; $title='Orders'; include __DIR__.'/../includes/header.php'; ?>
<div class="stats">
  <div class="stat"><span>New Orders</span><b><?= $cnt['new'] ?? 0 ?></b></div>
  <div class="stat"><span>In Oven</span><b><?= $cnt['in_progress'] ?? 0 ?></b></div>
  <div class="stat"><span>Ready</span><b><?= $cnt['ready'] ?? 0 ?></b></div>
  <div class="stat grad"><span>Delivered</span><b><?= $cnt['delivered'] ?? 0 ?></b></div>
</div>
<?php if ($userFilter): ?><p class="muted">Showing one customer's orders. <a href="orders.php" style="color:var(--pink)">Show all orders</a></p><?php endif; ?>
<div class="tbl-card scroll"><table class="tbl compact" id="orders">
  <thead><tr><th>Order</th><th>Customer</th><th>Items</th><th>Date needed</th><th>Destination</th><th>Fee</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($orders as $o): ?>
  <tr data-row data-id="<?= $o['order_id'] ?>" data-status="<?= e($o['status']) ?>">
    <td class="id">#<?= $o['order_id'] ?></td><td><b><?= e($o['contact_name']) ?></b><br><small class="muted"><?= e(display_phone($o['contact_phone'])) ?></small></td>
    <td class="wrap-ok"><?= e($o['items']) ?></td>
    <td><?= date('d M, g:i A', strtotime($o['date_needed'])) ?></td>
    <td class="wrap-ok"><?= $o['fulfilment']==='delivery' ? e($o['destination'] ?: 'Delivery').($o['delivery_address'] ? '<br><small class="muted">'.e($o['delivery_address']).'</small>' : '') : 'Pickup' ?></td>
    <td><?= $o['fulfilment']==='delivery' ? ($o['delivery_fee'] > 0 ? money($o['delivery_fee']) : 'Free') : '—' ?></td>
    <td><b><?= money($o['total_amount']) ?></b></td>
    <td><?= pay_badge($o['pay_status']) ?></td><td><?= status_badge($o['status']) ?></td>
    <td><a class="pill-btn pill-edit" href="<?= url('admin/order_view.php?id='.$o['order_id']) ?>">View</a></td></tr>
  <?php endforeach; if (!$orders): ?><tr><td colspan="10" class="muted" style="text-align:center;padding:36px">No orders yet.</td></tr><?php endif; ?>
  </tbody></table></div>
<p class="muted no-print" style="margin:12px 4px 0;font-size:.85rem">Click a row to select an order, then choose an action, or press View for the full details. <?= isset($_GET['all']) ? '<a href="orders.php'.($userFilter?'?user='.$userFilter:'').'" style="color:var(--pink)">Show latest only</a>' : '<a href="orders.php?all=1'.($userFilter?'&user='.$userFilter:'').'" style="color:var(--pink)">Show all orders</a>' ?></p>
<div class="actions no-print">
  <button class="btn" type="button" data-open="dlg-status">Update status</button>
  <button class="btn-ghost" type="button" data-open="dlg-cancel">Cancel order</button>
  <button class="btn-ghost" type="button" data-open="dlg-delete" data-need="delivered">Delete delivered order</button>
  <button class="btn-ghost" type="button" data-open="dlg-sms">Send SMS</button>
</div>

<dialog id="dlg-status"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="order_id" class="sel-id">
  <h3>Update status — order <span class="sel-label"></span></h3>
  <select class="field" name="status"><?php foreach (STATUS_LABELS as $k=>$l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
  <p class="muted" style="font-size:.85rem">The customer receives an SMS about the change.</p>
  <div class="acts"><button type="button" class="btn-ghost" data-close>Close</button><button class="btn">Save</button></div></form></dialog>
<dialog id="dlg-cancel"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="order_id" class="sel-id">
  <h3>Cancel order <span class="sel-label"></span>?</h3>
  <p>The order will be marked <b>Cancelled</b>, the cakes go back into stock and the customer gets an SMS. A cancelled order cannot be re-opened.</p>
  <div class="acts"><button type="button" class="btn-ghost" data-close>Keep order</button><button class="btn">Yes, cancel it</button></div></form></dialog>
<dialog id="dlg-delete"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="order_id" class="sel-id">
  <h3>Delete delivered order <span class="sel-label"></span>?</h3>
  <p>The order will disappear from the order list and from the customer's history. Its payment record is kept for accounting. Only <b>delivered</b> orders can be deleted.</p>
  <div class="acts"><button type="button" class="btn-ghost" data-close>Keep order</button><button class="btn">Yes, delete it</button></div></form></dialog>
<dialog id="dlg-sms"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sms"><input type="hidden" name="order_id" class="sel-id">
  <h3>Send SMS — order <span class="sel-label"></span></h3>
  <textarea class="field" name="message" rows="4" maxlength="300" placeholder="e.g. Your cake is ready for pickup." required></textarea>
  <div class="acts"><button type="button" class="btn-ghost" data-close>Close</button><button class="btn">Send</button></div></form></dialog>
<?php include __DIR__.'/../includes/footer.php';
