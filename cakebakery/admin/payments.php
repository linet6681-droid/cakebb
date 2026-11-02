<?php
require_once __DIR__.'/../includes/auth.php'; require_once __DIR__.'/../services/Mpesa.php'; require_admin();
if ($_SERVER['REQUEST_METHOD']==='POST') { csrf_check(); [$ok, $msg] = Mpesa::testConnection(); flash(($ok ? '✔ ' : '✖ ').$msg); redirect('admin/payments.php'); }
$rows = db()->query("SELECT p.*, o.contact_name, o.deleted_at FROM payments p JOIN orders o USING(order_id) ORDER BY p.payment_id DESC LIMIT 200")->fetchAll();
$sum = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid'")->fetchColumn();
$pend = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='pending'")->fetchColumn();
$layout='admin'; $active='payments'; $section='Payments'; $title='Payments'; include __DIR__.'/../includes/header.php'; ?>
<div class="tbl-card pad no-print" style="margin-bottom:24px;display:flex;align-items:center;gap:18px;flex-wrap:wrap">
  <div style="flex:1;min-width:240px"><b>M-Pesa environment: <?= e(ucfirst(Mpesa::mode())) ?></b><br><span class="muted"><?= Mpesa::mode()==='live' ? 'LIVE: real money. Prompts go to real phones.' : 'Sandbox: Safaricom\'s test environment (no real money). Switch MPESA_MODE to live in config/secrets.php only after Safaricom Go-Live approval.' ?></span></div>
  <form method="post"><?= csrf_field() ?><button class="btn">Test M-Pesa connection</button></form>
  <?php $cbOk = str_starts_with(MPESA_CALLBACK_URL, 'https://') && !preg_match('#localhost|127\.0\.0\.1|example\.com#', MPESA_CALLBACK_URL);
     $chk = [
     ['Consumer Key and Secret entered (config/secrets.php)', MPESA_CONSUMER_KEY !== '' && MPESA_CONSUMER_SECRET !== ''],
     ['Shortcode and Passkey set', MPESA_SHORTCODE !== '' && (MPESA_PASSKEY !== '' || (Mpesa::mode()==='sandbox' && MPESA_SHORTCODE==='174379'))],
     ['Callback URL is a public https address', $cbOk],
     ['Callback secret key set (blocks fake callbacks)', MPESA_CALLBACK_SECRET !== ''],
     ['Admin SMS alert phone set (optional)', (bool)normalize_phone((string)ADMIN_PHONE)]]; ?>
  <ul class="checks" style="flex-basis:100%"><?php foreach ($chk as [$l,$ok]): ?><li class="<?= $ok?'ok':'no' ?>"><?= $ok ? '✔' : '○' ?> <?= e($l) ?></li><?php endforeach; ?></ul></div>
<div class="stats" style="grid-template-columns:repeat(2,1fr)"><div class="stat grad"><span>Received</span><b><?= money($sum) ?></b></div><div class="stat"><span>Awaiting payment</span><b><?= money($pend) ?></b></div></div>
<div class="tbl-card scroll"><table class="tbl"><thead><tr><th>Order</th><th>Customer</th><th>Method</th><th>Amount</th><th>M-Pesa ref</th><th>Status</th><th>Paid at</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="id">#<?= $r['order_id'] ?></td><td><?= e($r['contact_name']) ?><?= $r['deleted_at'] ? ' <small class="muted">(order deleted)</small>' : '' ?></td><td><?= $r['method']==='mpesa'?'M-Pesa STK':'Pay on pickup' ?></td><td><?= money($r['amount']) ?></td><td><?= e($r['mpesa_receipt'] ?: '—') ?></td><td><?= pay_badge($r['status']) ?></td><td><?= $r['paid_at'] ? date('d M, H:i', strtotime($r['paid_at'])) : '—' ?></td></tr><?php endforeach;
if (!$rows): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:36px">No payments yet.</td></tr><?php endif; ?></tbody></table></div>
<?php include __DIR__.'/../includes/footer.php';
