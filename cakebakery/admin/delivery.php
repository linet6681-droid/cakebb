<?php
require_once __DIR__.'/../includes/auth.php'; require_admin();
$zones = delivery_zones(false);   // also creates the table for older installs
$errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check(); $act = $_POST['action'] ?? ''; $id = (int)($_POST['zone_id'] ?? 0);
    if ($act === 'toggle') {
        db()->prepare('UPDATE delivery_zones SET is_active = 1 - is_active WHERE zone_id=?')->execute([$id]);
        flash('Delivery area updated.'); redirect('admin/delivery.php');
    }
    if ($act === 'save') {
        $name = trim($_POST['name'] ?? ''); $fee = $_POST['fee'] ?? '';
        if (mb_strlen($name) < 2) $errors[] = 'Enter the area name.';
        if (!is_numeric($fee) || $fee < 0) $errors[] = 'The fee must be 0 or more.';
        $dup = db()->prepare('SELECT zone_id FROM delivery_zones WHERE name=? AND zone_id<>?'); $dup->execute([$name, $id]);
        if ($dup->fetch()) $errors[] = 'That area already exists.';
        if (!$errors) {
            if ($id) db()->prepare('UPDATE delivery_zones SET name=?, fee=?, is_active=1 WHERE zone_id=?')->execute([$name, $fee, $id]);
            else db()->prepare('INSERT INTO delivery_zones(name,fee) VALUES(?,?)')->execute([$name, $fee]);
            flash('Delivery area saved.'); redirect('admin/delivery.php');
        }
    }
}
$edit = null;
if (isset($_GET['edit'])) foreach ($zones as $z) if ((int)$z['zone_id'] === (int)$_GET['edit']) $edit = $z;
$layout='admin'; $active='delivery'; $section='Delivery Areas'; $title='Delivery'; include __DIR__.'/../includes/header.php'; ?>
<div class="head-row"><h1>Delivery areas</h1><a class="btn" href="<?= url('admin/delivery.php#form') ?>">+ Add Area</a></div>
<p class="muted" style="margin:-8px 0 18px">Customers choose one of these areas at checkout and pay the fee shown. Pickup at the shop is always free.</p>
<div class="tbl-card scroll"><table class="tbl"><thead><tr><th>ID</th><th>Area</th><th>Fee (Ksh)</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($zones as $z): ?>
<tr><td><?= $z['zone_id'] ?></td><td><b><?= e($z['name']) ?></b></td><td><?= num($z['fee']) ?></td>
<td><?= $z['is_active'] ? '<span class="pill pay-paid">Active</span>' : '<span class="pill st-delivered">Hidden</span>' ?></td>
<td style="white-space:nowrap"><a class="pill-btn pill-edit" href="?edit=<?= $z['zone_id'] ?>#form">Edit</a>
 <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="zone_id" value="<?= $z['zone_id'] ?>"><button class="pill-btn pill-del"><?= $z['is_active'] ? 'Hide' : 'Show' ?></button></form></td></tr>
<?php endforeach; if (!$zones): ?><tr><td colspan="5" class="muted" style="text-align:center;padding:30px">No areas yet.</td></tr><?php endif; ?></tbody></table></div>

<form id="form" class="card formcard" method="post">
  <h3>Add / Edit Delivery Area</h3>
  <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="zone_id" value="<?= (int)($edit['zone_id'] ?? 0) ?>">
  <div class="two"><div><label class="f">Area name</label><input class="field" name="name" required placeholder="e.g. Syokimau" value="<?= e($_POST['name'] ?? $edit['name'] ?? '') ?>"></div>
    <div><label class="f">Delivery fee (Ksh)</label><input class="field" type="number" min="0" step="10" name="fee" required value="<?= e($_POST['fee'] ?? (isset($edit['fee']) ? (int)$edit['fee'] : '')) ?>"></div></div>
  <button class="btn" style="margin-top:8px;padding:16px 40px;text-transform:uppercase">Save Area</button>
  <?php if ($edit): ?> <a class="btn-ghost" href="delivery.php" style="margin-left:10px">Cancel edit</a><?php endif; ?>
</form>
<?php include __DIR__.'/../includes/footer.php';
