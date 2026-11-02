<?php
require_once 'includes/auth.php';
require_once 'services/OrderService.php';

// ---- cart actions (add / step / update / remove) ----
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') !== 'place') {
    csrf_check();
    $pid = (int)($_POST['product_id'] ?? 0);
    $st = db()->prepare('SELECT stock_qty FROM products WHERE product_id=? AND is_active=1'); $st->execute([$pid]);
    $stock = (int)($st->fetchColumn() ?: 0);
    $cur = $_SESSION['cart'][$pid]['qty'] ?? 0;
    switch ($_POST['action'] ?? '') {
        case 'add':
            if ($stock <= 0) { flash('Sorry, that item is out of stock.'); break; }
            $_SESSION['cart'][$pid] = ['qty'=>min($cur + 1, $stock), 'message'=>$_SESSION['cart'][$pid]['message'] ?? ''];
            flash('Added to cart.');
            $back = $_POST['back'] ?? ''; if ($back && str_starts_with($back, BASE_URL.'/')) { header('Location: '.$back); exit; }
            break;
        case 'update':
            if (!isset($_SESSION['cart'][$pid])) break;
            $qty = $cur + (($_POST['op'] ?? '') === 'plus' ? 1 : (($_POST['op'] ?? '') === 'minus' ? -1 : 0));
            if ($qty <= 0) unset($_SESSION['cart'][$pid]);
            else { $_SESSION['cart'][$pid]['qty'] = min($qty, max($stock,1));
                   if (isset($_POST['message'])) $_SESSION['cart'][$pid]['message'] = mb_substr(trim($_POST['message']), 0, 120); }
            break;
        case 'remove': unset($_SESSION['cart'][$pid]); break;
    }
    redirect('cart.php');
}

// ---- load cart lines ----
$lines = []; $subtotal = 0; $hasCake = false;
foreach ($_SESSION['cart'] ?? [] as $pid => $row) {
    $st = db()->prepare('SELECT * FROM products WHERE product_id=? AND is_active=1'); $st->execute([$pid]);
    if (!($p = $st->fetch())) { unset($_SESSION['cart'][$pid]); continue; }
    $line = $p['price'] * $row['qty']; $subtotal += $line; if ($p['category_id'] == 1) $hasCake = true;
    $lines[] = ['p'=>$p,'row'=>$row,'line'=>$line];
}

// ---- place order ----
$errors = []; $in = $_POST;
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'place') {
    csrf_check(); require_login();
    // One-time form token: a double click or a refresh cannot create the same order twice.
    if (!isset($_SESSION['ptok']) || !hash_equals($_SESSION['ptok'], (string)($_POST['ptok'] ?? ''))) { flash('That order was already submitted. Check My Orders below.'); redirect('my_orders.php'); }
    try {
        $orderId = OrderService::placeOrder(current_user()['id'], $_SESSION['cart'] ?? [], $_POST);
        $_SESSION['cart'] = []; unset($_SESSION['ptok']);
        $viaMpesa = ($_POST['method'] ?? 'mpesa') !== 'cash_on_pickup';
        flash(OrderService::$notice ?: ($viaMpesa ? "Order #$orderId placed. Complete the M-Pesa payment below." : "Order #$orderId placed. We've sent you an SMS."));
        redirect($viaMpesa ? 'track.php?id='.$orderId : 'my_orders.php');
    } catch (InvalidArgumentException $ex) { $errors[] = $ex->getMessage(); }
}
$u = current_user();
$_SESSION['ptok'] ??= bin2hex(random_bytes(16));
$minDate = date('Y-m-d', strtotime($hasCake ? '+1 day' : 'today'));
$layout='simple'; $active='cart'; $title='Cart & Checkout'; include 'includes/header.php'; ?>
<h1 class="page-title">Shopping Cart &amp; Checkout</h1>
<?php if (!$lines): ?>
  <div class="card empty-cart"><h3>Your cart is empty</h3><p class="muted">Pick something fresh from the oven.</p><a class="btn" href="<?= url('index.php') ?>">Browse the shop</a></div>
<?php else: ?>
<div class="co">
  <div class="stack">
    <?php foreach ($lines as ['p'=>$p,'row'=>$row,'line'=>$line]): $isCake = $p['category_id']==1; ?>
    <form class="item" method="post" action="<?= url('cart.php') ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="product_id" value="<?= $p['product_id'] ?>">
      <button name="op" value="keep" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px">save</button>
      <img src="<?= img_url($p['image']) ?>" alt="" class="<?= $isCake?'':'contain' ?>">
      <div class="info">
        <h3><?php if ($p['pack_size']): ?><?= e($p['name']) ?> x <?= $row['qty']*$p['pack_size'] ?><?php else: ?><?= e($p['name']) ?><?= $p['size_label'] ? ' ('.e($p['size_label']).')' : '' ?><?php endif; ?></h3>
        <?php if ($isCake): ?><div class="note">Message on cake: <input name="message" maxlength="120" value="<?= e($row['message']) ?>" placeholder="e.g. Happy Birthday Amina" onchange="this.form.submit()"></div>
        <?php elseif ($p['pack_size']): ?><div class="note"><?= $row['qty'] ?> box<?= $row['qty']>1?'es':'' ?> of <?= $p['pack_size'] ?></div>
        <?php else: ?><div class="note"><?= e($p['description']) ?></div><?php endif; ?>
        <div class="stepper"><button name="op" value="minus" aria-label="Decrease">-</button><span><?= $row['qty'] ?></span><button name="op" value="plus" aria-label="Increase">+</button></div>
        <button class="rm" name="action" value="remove" formnovalidate>Remove</button>
      </div>
      <div class="line"><?= money($line) ?></div>
    </form>
    <?php endforeach; ?>

    <form id="checkout" method="post" action="<?= url('cart.php') ?>" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="place"><input type="hidden" name="ptok" value="<?= e($_SESSION['ptok']) ?>">
      <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>
      <?php if (!$u): ?><p class="err">Please <a href="<?= url('login.php') ?>"><b>log in</b></a> or <a href="<?= url('register.php') ?>"><b>register</b></a> to place your order. Your cart will be kept.</p><?php endif; ?>
      <div class="panel">
        <h3>Pickup / Delivery Details</h3>
        <div class="two"><div><label class="f">Date needed</label><input class="field" type="date" name="date" min="<?= $minDate ?>" required value="<?= e($in['date'] ?? '') ?>"></div>
          <div><label class="f">Time</label><input class="field" type="time" name="time" min="07:00" max="19:00" required value="<?= e($in['time'] ?? '10:00') ?>"></div></div>
        <div class="two"><div><label class="f">Full name</label><input class="field" name="name" required value="<?= e($in['name'] ?? ($u['name'] ?? '')) ?>"></div>
          <div><label class="f">Phone</label><input class="field" name="phone" required placeholder="0712 345 678" value="<?= e($in['phone'] ?? display_phone($u['phone'] ?? '')) ?>"></div></div>
        <div class="row"><label class="f">Delivery destination</label>
          <select class="field" id="zone" name="zone">
            <option value="" data-fee="0">Pickup at the shop (no delivery fee)</option>
            <?php foreach (delivery_zones() as $z): ?><option value="<?= $z['zone_id'] ?>" data-fee="<?= (float)$z['fee'] ?>" <?= (int)($in['zone'] ?? 0)===(int)$z['zone_id']?'selected':'' ?>><?= e($z['name']) ?> — <?= $z['fee'] > 0 ? money($z['fee']) : 'Free' ?></option><?php endforeach; ?>
          </select></div>
        <div class="row"><label class="f">Delivery address</label><input class="field" id="location" name="location" maxlength="200" placeholder="Street, building, estate or landmark (needed for delivery)" value="<?= e($in['location'] ?? '') ?>"></div>
        <div class="row" style="margin-bottom:0"><label class="f">Special instructions</label><input class="field" name="instructions" maxlength="255" placeholder="e.g. no nuts, extra candles" value="<?= e($in['instructions'] ?? '') ?>"></div>
      </div>
      <div class="panel">
        <h3>Payment Method</h3>
        <div class="pay">
          <label class="radio"><input type="radio" name="method" value="mpesa" <?= ($in['method'] ?? 'mpesa')==='mpesa'?'checked':'' ?>> M-Pesa STK Push</label>
          <label class="radio"><input type="radio" name="method" value="cash_on_pickup" <?= ($in['method'] ?? '')==='cash_on_pickup'?'checked':'' ?>> Pay on pickup</label>
        </div>
        <div class="row" id="mpesabox" style="margin:14px 0 0"><label class="f">M-Pesa phone number</label>
          <input class="field" name="mpesa_phone" inputmode="tel" placeholder="0712 345 678 (the phone that will get the payment prompt)" value="<?= e($in['mpesa_phone'] ?? '') ?>">
          <p class="muted" style="font-size:.82rem;margin:6px 2px 0">Leave empty to use the phone number above. You will pay the full total shown in the summary, including delivery.</p></div>
      </div>
    </form>
  </div>

  <aside class="card summary" data-subtotal="<?= $subtotal ?>" >
    <h2>Order Summary</h2>
    <div class="r"><span>Subtotal</span><b><?= money($subtotal) ?></b></div>
    <div class="r"><span>Delivery fee</span><b id="fee">Free</b></div>
    <div class="total"><span>Total</span><b id="total"><?= money($subtotal) ?></b></div>
    <button class="btn btn-block" form="checkout" id="placebtn">Place Order</button>
    <p class="note" id="paynote">An M-Pesa prompt for the total above is sent to your phone when you place the order.</p>
  </aside>
</div>
<?php endif; include 'includes/footer.php';
