<?php
require_once __DIR__.'/../includes/auth.php'; require_admin();
require_once __DIR__.'/../services/AdminService.php';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check(); $uid = (int)($_POST['user_id'] ?? 0);
    try {
        if (($_POST['action'] ?? '') === 'delete') flash(AdminService::deleteCustomer($uid));
        elseif (($_POST['action'] ?? '') === 'unlock') { AdminService::unlockCustomer($uid); flash('Account unlocked. The customer can log in again.'); }
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage()); }
    redirect('admin/customers.php');
}
$rows = db()->query("SELECT u.*, COUNT(o.order_id) n, COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN o.total_amount END),0) spent
  FROM users u LEFT JOIN orders o ON o.user_id=u.user_id AND o.deleted_at IS NULL WHERE u.role='customer' AND u.deleted_at IS NULL GROUP BY u.user_id ORDER BY spent DESC, u.user_id")->fetchAll();
$layout='admin'; $active='customers'; $section='Customers'; $title='Customers'; include __DIR__.'/../includes/header.php'; ?>
<div class="head-row"><h1>Customers</h1></div>
<div class="tbl-card scroll"><table class="tbl"><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Orders</th><th>Total spent</th><th>Joined</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $r): $locked = $r['locked_until'] && strtotime($r['locked_until']) > time(); ?>
<tr><td><?= $r['user_id'] ?></td><td><b><?= e($r['full_name']) ?></b><?= $locked ? ' <span class="pill st-cancelled">Locked</span>' : '' ?></td><td><?= e($r['email']) ?></td><td><?= e(display_phone($r['phone'])) ?></td>
<td><?= $r['n'] ? '<a href="'.url('admin/orders.php?user='.$r['user_id']).'" style="color:var(--pink)">'.$r['n'].'</a>' : 0 ?></td><td><?= money($r['spent']) ?></td><td><?= date('d M Y', strtotime($r['created_at'])) ?></td>
<td style="white-space:nowrap">
  <?php if ($locked): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="unlock"><input type="hidden" name="user_id" value="<?= $r['user_id'] ?>"><button class="pill-btn pill-edit">Unlock</button></form><?php endif; ?>
  <form method="post" style="display:inline" onsubmit="return confirm('Delete customer <?= e(addslashes($r['full_name'])) ?>?\n\nTheir personal details will be erased. Their orders and payment records are kept for accounting. This cannot be undone.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= $r['user_id'] ?>"><button class="pill-btn pill-del">Delete</button></form></td></tr>
<?php endforeach;
if (!$rows): ?><tr><td colspan="8" class="muted" style="text-align:center;padding:36px">No customers yet.</td></tr><?php endif; ?></tbody></table></div>
<p class="muted" style="margin:12px 4px 0;font-size:.85rem">Deleting a customer with order history erases their name, email, phone and password but keeps the orders and payment records (needed for accounting). Customers with unfinished orders cannot be deleted.</p>
<?php include __DIR__.'/../includes/footer.php';
