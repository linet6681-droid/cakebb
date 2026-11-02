<?php
require_once 'includes/auth.php'; require_once 'services/Mpesa.php'; require_once 'services/PaymentService.php'; require_login();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = db()->prepare('SELECT o.*, p.method, p.status AS pay_status, p.mpesa_receipt, p.amount, p.paid_amount, p.payer_phone, p.result_code, p.result_desc, p.requested_at FROM orders o LEFT JOIN payments p USING(order_id) WHERE o.order_id=? AND o.deleted_at IS NULL'); $st->execute([$id]);
$o = $st->fetch();
if (!$o || ($o['user_id'] != current_user()['id'] && current_user()['role'] !== 'admin')) { http_response_code(404); include '404.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // pay / retry the M-Pesa prompt (same order, never a duplicate order)
    csrf_check();
    if ($o['user_id'] != current_user()['id']) { http_response_code(403); exit('Only the customer who placed this order can pay for it.'); }
    $stale = $o['pay_status'] === 'pending' && $o['requested_at'] && strtotime($o['requested_at']) < time() - 90;   // an unanswered prompt expires after about 90 seconds
    if ($o['method'] === 'mpesa' && ($o['pay_status'] === 'failed' || $stale) && $o['status'] !== 'cancelled') {
        $given = trim($_POST['mpesa_phone'] ?? ''); $phone = $given === '' ? (string)$o['payer_phone'] : normalize_phone($given);
        if (!$phone) { flash('Enter a valid M-Pesa phone number (e.g. 0712 345 678).'); redirect('track.php?id='.$id); }
        if (Mpesa::stkPush($id, $phone)) flash(Mpesa::$duplicate ? 'A prompt was just sent. Check your phone.' : 'M-Pesa prompt sent to '.display_phone($phone).'. Enter your PIN to pay.');
        else flash('We could not send the M-Pesa prompt: '.Mpesa::$lastError);
    }
    redirect('track.php?id='.$id);
}
if ($o['method'] === 'mpesa' && $o['pay_status'] === 'pending') {   // ask Safaricom (works without a public callback)
    Mpesa::syncStatus($id);
    $st->execute([$id]); $o = $st->fetch();
}
$it = db()->prepare('SELECT oi.*, p.name, p.pack_size, p.image FROM order_items oi JOIN products p USING(product_id) WHERE oi.order_id=?'); $it->execute([$id]); $items = $it->fetchAll();
$steps = ['new'=>'Order received','in_progress'=>'Baking','ready'=>'Ready','delivered'=>$o['fulfilment']==='delivery' ? 'Delivered' : 'Collected'];
$reached = array_search($o['status'], array_keys($steps), true);
$layout='simple'; $active='orders'; $title='Order #'.$id; include 'includes/header.php'; ?>
<h1 class="page-title">Order #<?= $id ?> <?= status_badge($o['status']) ?></h1>
<div class="co">
  <div class="stack">
    <div class="panel">
      <h3>Order tracking</h3>
      <?php if ($o['status'] === 'cancelled'): ?><p class="err" style="margin:0">This order was cancelled.</p>
      <?php else: ?><ol class="steps"><?php $i = 0; foreach ($steps as $k=>$l): ?><li class="<?= $i < $reached ? 'done' : ($i === $reached ? 'now' : '') ?>"><span><?= $i < $reached ? '✓' : $i+1 ?></span><?= e($l) ?></li><?php $i++; endforeach; ?></ol><?php endif; ?>
    </div>
    <?php if ($o['method']==='mpesa'): [$pstate, $phead, $pdetail] = PaymentService::describe(['status'=>$o['pay_status']] + $o);
          $canRetry = $o['status']!=='cancelled' && $o['user_id']==current_user()['id'] && ($pstate==='failed' || ($pstate==='pending' && $o['requested_at'] && strtotime($o['requested_at']) < time()-90)); ?>
    <div class="panel paybox pay-<?= e($pstate) ?>" id="paybox" data-id="<?= $id ?>" data-url="<?= e(url('payment_status.php?id='.$id)) ?>">
      <h3>M-Pesa payment <span id="paypill"><?= pay_badge($o['pay_status']) ?></span></h3>
      <p class="phead" id="payhead"><b><?= e($phead) ?></b></p>
      <p class="muted" id="paydetail" style="margin-top:-6px"><?= e($pdetail) ?></p>
      <form method="post" id="retryform" <?= $canRetry ? '' : 'hidden' ?>><?= csrf_field() ?>
        <label class="f">M-Pesa phone number</label>
        <input class="field" name="mpesa_phone" inputmode="tel" placeholder="0712 345 678" value="<?= e(display_phone((string)$o['payer_phone'])) ?>" style="max-width:240px">
        <button class="btn" style="margin-left:8px"><?= $pstate==='failed' ? 'Try M-Pesa again' : 'Send a new prompt' ?></button></form>
    </div>
    <?php if ($pstate === 'pending'): ?><script>
    (function(){var b=document.getElementById('paybox');if(!b)return;var n=0;
    function tick(){fetch(b.dataset.url,{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json()}).then(function(j){
      if(!j.state)return; if(j.state!=='pending'){location.reload();return;}
      document.getElementById('payhead').innerHTML='<b></b>';document.getElementById('payhead').firstChild.textContent=j.headline;document.getElementById('paydetail').textContent=j.detail;
      if(++n>60)return; setTimeout(tick,4000);}).catch(function(){setTimeout(tick,8000)});}
    setTimeout(tick,4000);})();</script><?php endif; ?>
    <?php endif; ?>
    <?php foreach ($items as $r): ?>
    <div class="item"><img class="contain" src="<?= img_url($r['image']) ?>" alt="">
      <div class="info"><h3><?= e($r['name']) ?><?= $r['pack_size'] ? ' x '.($r['quantity']*$r['pack_size']) : '' ?></h3>
        <div class="note"><?= $r['pack_size'] ? $r['quantity'].' box'.($r['quantity']>1?'es':'').' of '.$r['pack_size'] : 'Quantity: '.$r['quantity'] ?><?= $r['cake_message'] ? ' · Message on cake: <span style="color:var(--pink)">'.e($r['cake_message']).'</span>' : '' ?></div></div>
      <div class="line"><?= money($r['unit_price']*$r['quantity']) ?></div></div>
    <?php endforeach; ?>
  </div>
  <aside class="card summary">
    <h2>Details</h2>
    <div class="r"><span>Needed</span><b><?= date('d M Y, g:i A', strtotime($o['date_needed'])) ?></b></div>
    <div class="r"><span><?= $o['fulfilment']==='delivery' ? 'Destination' : 'Pickup' ?></span><b style="text-align:right"><?= $o['fulfilment']==='delivery' ? e($o['destination'] ?: 'Delivery').($o['delivery_address'] ? '<br><small style="font-weight:400">'.e($o['delivery_address']).'</small>' : '') : 'At the shop' ?></b></div>
    <div class="r"><span>Subtotal</span><b><?= money($o['total_amount'] - $o['delivery_fee']) ?></b></div>
    <div class="r"><span>Delivery fee</span><b><?= $o['delivery_fee'] > 0 ? money($o['delivery_fee']) : 'Free' ?></b></div>
    <div class="r"><span>Payment</span><b><?= $o['method']==='mpesa' ? 'M-Pesa' : 'Pay on pickup' ?> <?= pay_badge($o['pay_status']) ?></b></div>
    <?php if ($o['mpesa_receipt']): ?><div class="r"><span>M-Pesa ref</span><b><?= e($o['mpesa_receipt']) ?></b></div><?php endif; ?>
    <div class="total"><span>Total</span><b><?= money($o['total_amount']) ?></b></div>
  </aside>
</div>
<?php include 'includes/footer.php';
