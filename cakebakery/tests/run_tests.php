<?php
/**
 * Automated checks for Milestone 4 (Chapter 4 test cases).
 * Run from the project folder on a DEV database:   php tests/run_tests.php
 * It creates its own test users/products (prefixed ZZ) and soft-deletes the products afterwards.
 */
chdir(__DIR__ . '/..');
require_once 'includes/auth.php';
require_once 'services/OrderService.php';
require_once 'services/PaymentService.php';
$pass = 0; $fail = 0;
function t(string $id, string $name, callable $fn): void {
    global $pass, $fail;
    try { $r = $fn(); $ok = $r === true; $msg = $ok ? '' : (is_string($r) ? $r : 'returned false'); }
    catch (Throwable $e) { $ok = false; $msg = get_class($e).': '.$e->getMessage(); }
    $ok ? $pass++ : $fail++;
    printf("%-6s %-4s %s%s\n", $id, $ok ? 'PASS' : 'FAIL', $name, $ok ? '' : "  -> $msg");
}
function throws(callable $f, string $contains): bool {
    try { $f(); } catch (InvalidArgumentException $e) { return stripos($e->getMessage(), $contains) !== false; }
    return false;
}
$pdo = db(); $u = uniqid();
$email = "zz$u@test.local";
$cake = ['name'=>"ZZ Cake $u", 'cat'=>1, 'price'=>1000, 'stock'=>5];
$bread = ['name'=>"ZZ Bread $u", 'cat'=>3, 'price'=>70, 'stock'=>50];
foreach ([&$cake, &$bread] as &$p) {
    $pdo->prepare('INSERT INTO products(category_id,name,price,stock_qty) VALUES(?,?,?,?)')->execute([$p['cat'],$p['name'],$p['price'],$p['stock']]);
    $p['id'] = (int)$pdo->lastInsertId();
}
unset($p);
$stock = fn($id) => (int)$pdo->query("SELECT stock_qty FROM products WHERE product_id=$id")->fetchColumn();
$tomorrow2 = date('Y-m-d', strtotime('+2 days')); $today = date('Y-m-d');
$base = ['name'=>'Test User','phone'=>'0712345678','location'=>'','instructions'=>'','method'=>'cash_on_pickup','date'=>$tomorrow2,'time'=>'10:00'];
$cartCake = fn($q=1,$msg='') => [$cake['id']=>['qty'=>$q,'message'=>$msg]];
$cartBread = fn($q=1) => [$bread['id']=>['qty'=>$q,'message'=>'']];

echo "== Registration & login ==\n";
t('T01','Register with valid details', fn() => register_user('Test User',$email,'0712345678','password123') === []);
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE email='$email'")->fetchColumn();
t('T02','Password is stored hashed, not plain text', fn() => (bool)preg_match('/^\$2y\$|^\$argon/', $pdo->query("SELECT password_hash FROM users WHERE user_id=$uid")->fetchColumn()));
t('T03','Duplicate email rejected', fn() => in_array('Email already registered.', register_user('Test Two',$email,'0712345678','password123')));
t('T04','Short password rejected', fn() => count(register_user('Test',"b$u@t.local",'0712345678','123')) >= 1);
t('T05','Invalid phone rejected', fn() => count(register_user('Test',"c$u@t.local",'12345','password123')) >= 1);
t('T06','Invalid email rejected', fn() => count(register_user('Test','not-an-email','0712345678','password123')) >= 1);
t('T07','SQL injection in login is rejected', fn() => login_user("' OR '1'='1' -- ", "' OR '1'='1") === 'Invalid email or password.');
t('T08','Wrong password gives generic error', fn() => login_user($email,'wrongpass') === 'Invalid email or password.');
t('T09','Account locks after 3 failed attempts', function() use ($email) { login_user($email,'bad'); $r = login_user($email,'bad'); return stripos((string)$r,'locked') !== false; });
t('T10','Locked account rejects even the correct password', fn() => stripos((string)login_user($email,'password123'),'locked') !== false);
$pdo->prepare('UPDATE users SET locked_until=NULL, failed_attempts=0 WHERE user_id=?')->execute([$uid]);
t('T11','Correct login succeeds and sets role session', fn() => login_user($email,'password123') === null && ($_SESSION['user']['role'] ?? '') === 'customer');

echo "== Ordering rules ==\n";
t('T12','Empty cart cannot be ordered', fn() => throws(fn() => OrderService::placeOrder($uid, [], $base), 'empty'));
t('T13','Invalid phone at checkout rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['phone'=>'999']+$base), 'phone'));
t('T14','Missing date rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['date'=>'']+$base), 'date'));
t('T15','Date in the past rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['date'=>date('Y-m-d',strtotime('-1 day'))]+$base), 'hour'));
t('T16','Cake needs 24 hours notice', fn() => throws(fn() => OrderService::placeOrder($uid, $cartCake(), ['date'=>$today,'time'=>'18:59']+$base), '24 hours') || throws(fn() => OrderService::placeOrder($uid, $cartCake(), ['date'=>$today,'time'=>'18:59']+$base), 'hour'));
t('T17','Outside opening hours rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['time'=>'23:30']+$base), 'open'));
t('T18','Quantity above stock rejected, stock unchanged', function() use ($uid,$cartCake,$base,$cake,$stock) {
    $ok = throws(fn() => OrderService::placeOrder($uid, $cartCake(99), $base), 'stock'); return $ok && $stock($cake['id']) === 5; });
$orderId = 0;
t('T19','Valid cake order is placed (pickup, pay on pickup)', function() use (&$orderId,$uid,$cartCake,$base) { $orderId = OrderService::placeOrder($uid, $cartCake(2,'Happy Birthday Amina'), $base); return $orderId > 0; });
t('T20','Stock decreases by ordered quantity', fn() => $stock($cake['id']) === 3);
t('T21','Total = price x qty with no delivery fee for pickup', fn() => (float)$pdo->query("SELECT total_amount FROM orders WHERE order_id=$orderId")->fetchColumn() === 2000.0);
t('T22','Cake message saved on the order item', fn() => $pdo->query("SELECT cake_message FROM order_items WHERE order_id=$orderId")->fetchColumn() === 'Happy Birthday Amina');
t('T23','Payment row created as pending', fn() => $pdo->query("SELECT status FROM payments WHERE order_id=$orderId")->fetchColumn() === 'pending');
t('T24','Confirmation SMS logged in notifications', fn() => (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$orderId")->fetchColumn() >= 1);
$zoneId = fn($like) => (int)$pdo->query("SELECT zone_id FROM delivery_zones WHERE name LIKE '$like%' ORDER BY zone_id LIMIT 1")->fetchColumn();
delivery_zones();   // creates + seeds the zones table on older databases
t('T25','Delivery adds the fee of the chosen destination (Between Allsops and Juja = Ksh 100)', function() use ($uid,$cartBread,$base,$zoneId) {
    $id = OrderService::placeOrder($uid, $cartBread(2), ['zone'=>$zoneId('Between Allsops'),'location'=>'Mwatu wa Ngoma Rd']+$base);
    return (float)db()->query("SELECT total_amount FROM orders WHERE order_id=$id")->fetchColumn() === 240.0; });
t('T26','Cake message HTML is escaped on output (XSS)', fn() => e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;');

echo "== Payments (M-Pesa callback) ==\n";
$pdo->prepare('UPDATE payments SET checkout_request_id=? WHERE order_id=?')->execute(["ws_CO_$u",$orderId]);
$cbOk = ['CheckoutRequestID'=>"ws_CO_$u",'ResultCode'=>0,'CallbackMetadata'=>['Item'=>[['Name'=>'Amount','Value'=>(float)$pdo->query("SELECT amount FROM payments WHERE order_id=$orderId")->fetchColumn()],['Name'=>'MpesaReceiptNumber','Value'=>'QWE123RTY']]]];
t('T27','Successful callback marks payment paid with receipt', function() use ($cbOk,$pdo,$orderId) {
    PaymentService::handleCallback($cbOk); $r = $pdo->query("SELECT status, mpesa_receipt FROM payments WHERE order_id=$orderId")->fetch(); return $r['status'] === 'paid' && $r['mpesa_receipt'] === 'QWE123RTY'; });
t('T28','Repeated callback is ignored (no duplicate SMS)', function() use ($cbOk,$pdo,$orderId) {
    $before = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$orderId")->fetchColumn();
    PaymentService::handleCallback($cbOk);
    return (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$orderId")->fetchColumn() === $before; });
t('T29','Failed/cancelled STK sets payment failed', function() use ($uid,$cartBread,$base,$pdo,$u) {
    $id = OrderService::placeOrder($uid, $cartBread(), $base); $pdo->prepare('UPDATE payments SET checkout_request_id=? WHERE order_id=?')->execute(["fail_$u",$id]);
    PaymentService::handleCallback(['CheckoutRequestID'=>"fail_$u",'ResultCode'=>1032]);
    return $pdo->query("SELECT status FROM payments WHERE order_id=$id")->fetchColumn() === 'failed'; });
t('T30','Callback with unknown id is ignored safely', fn() => PaymentService::handleCallback(['CheckoutRequestID'=>'nope','ResultCode'=>0]) === null);

echo "== Admin order handling ==\n";
t('T31','Invalid status rejected', fn() => throws(fn() => OrderService::updateStatus($orderId,'flying'), 'invalid'));
t('T32','Status change to Baking saved + SMS sent', function() use ($orderId,$pdo) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$orderId")->fetchColumn();
    OrderService::updateStatus($orderId,'in_progress');
    return $pdo->query("SELECT status FROM orders WHERE order_id=$orderId")->fetchColumn() === 'in_progress'
        && (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$orderId")->fetchColumn() === $n + 1; });
t('T33','Cancelling restores stock', function() use ($orderId,$stock,$cake) { OrderService::updateStatus($orderId,'cancelled'); return $stock($cake['id']) === 5; });
t('T34','Cancelled order cannot be re-opened', fn() => throws(fn() => OrderService::updateStatus($orderId,'ready'), 'cancelled'));
t('T35','Cancelling twice does not restore stock twice', function() use ($orderId,$stock,$cake) { OrderService::updateStatus($orderId,'cancelled'); return $stock($cake['id']) === 5; });


echo "== Delivery areas =============================\n";
t('T36','A farther area costs more (Mombasa = Ksh 500)', function() use ($uid,$cartBread,$base,$zoneId) {
    $id = OrderService::placeOrder($uid, $cartBread(2), ['zone'=>$zoneId('Mombasa'),'location'=>'Nyali']+$base);
    return (float)db()->query("SELECT delivery_fee FROM orders WHERE order_id=$id")->fetchColumn() === 500.0; });
t('T37','Delivery area without a street/landmark is rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['zone'=>$zoneId('Mombasa'),'location'=>'']+$base), 'street'));
t('T38','Location typed but no area chosen is rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['zone'=>'','location'=>'Somewhere']+$base), 'delivery destination'));
t('T39','Unknown / tampered area id is rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['zone'=>99999,'location'=>'Somewhere','fee'=>0]+$base), 'delivery destination'));
t('T40','Pickup order has no delivery fee', fn() => (float)db()->query("SELECT delivery_fee FROM orders WHERE order_id=$orderId")->fetchColumn() === 0.0);

echo "== M-Pesa (Daraja) rules: logic only, no Safaricom call ===\n";
require_once 'services/Mpesa.php';
$pending = function (float $total, string $cid) use ($uid,$cartBread,$base,$pdo) {   // an M-Pesa order whose prompt is "pending" with a known checkout id
    $id = OrderService::placeOrder($uid, $cartBread(1), ['method'=>'mpesa','mpesa_phone'=>'0712 345 678']+$base);
    $pdo->prepare("UPDATE payments SET status='pending', checkout_request_id=?, merchant_request_id='M1', requested_at=?, result_desc=NULL WHERE order_id=?")->execute([$cid, date('Y-m-d H:i:s'), $id]); return $id; };
$cb = fn($cid,$code,$amt,$rcpt='RC12345AB') => ['CheckoutRequestID'=>$cid,'MerchantRequestID'=>'M1','ResultCode'=>$code,'ResultDesc'=>'x'] + ($code===0 ? ['CallbackMetadata'=>['Item'=>[['Name'=>'Amount','Value'=>$amt],['Name'=>'MpesaReceiptNumber','Value'=>$rcpt]]]] : []);
$pst = fn($id) => $pdo->query("SELECT * FROM payments WHERE order_id=$id")->fetch();
t('T41','M-Pesa order with no Daraja keys: order saved, payment FAILED with a clear reason, never "paid"', function() use ($uid,$cartBread,$base,$pst) {
    if (MPESA_CONSUMER_KEY !== '') return true;   // only meaningful on an unconfigured install
    $id = OrderService::placeOrder($uid, $cartBread(1), ['method'=>'mpesa']+$base); $p = $pst($id);
    return $p['status']==='failed' && stripos((string)$p['result_desc'], 'not set up') !== false && OrderService::$notice !== ''; });
t('T42','Invalid M-Pesa number is rejected before the order is created', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['method'=>'mpesa','mpesa_phone'=>'12345']+$base), 'M-Pesa phone'));
$id1 = $pending(0, "ws_A$u"); $amt1 = (float)$pst($id1)['amount'];
t('T43','Callback with the WRONG amount is not marked paid', function() use ($id1,$cb,$pst,$u,$amt1) { PaymentService::handleCallback($cb("ws_A$u",0,$amt1+50)); return $pst($id1)['status']==='pending'; });
t('T44','Callback for an unknown CheckoutRequestID changes nothing', function() use ($id1,$cb,$pst) { PaymentService::handleCallback($cb('ws_NOPE',0,1)); return $pst($id1)['status']==='pending'; });
t('T45','Success callback with matching amount: paid, receipt + amount saved', function() use ($id1,$cb,$pst,$u,$amt1) {
    PaymentService::handleCallback($cb("ws_A$u",0,$amt1)); $p = $pst($id1); return $p['status']==='paid' && $p['mpesa_receipt']==='RC12345AB' && (float)$p['paid_amount']===$amt1; });
t('T46','Repeated callback: one confirmation SMS and one admin alert only', function() use ($id1,$cb,$pdo,$u,$amt1) {
    PaymentService::handleCallback($cb("ws_A$u",0,$amt1)); PaymentService::handleCallback($cb("ws_A$u",0,$amt1));
    return (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$id1 AND message LIKE 'PAYMENT ALERT:%Confirmed%'")->fetchColumn()===1; });
t('T47','A later failure callback cannot undo a paid payment', function() use ($id1,$cb,$pst,$u) { PaymentService::handleCallback($cb("ws_A$u",1032,0)); return $pst($id1)['status']==='paid'; });
$id2 = $pending(0, "ws_B$u");
t('T48','Cancelled-on-phone callback (1032): payment failed, code saved, admin alerted', function() use ($id2,$cb,$pst,$pdo,$u) {
    PaymentService::handleCallback($cb("ws_B$u",1032,0)); $p = $pst($id2);
    return $p['status']==='failed' && (int)$p['result_code']===1032 && (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$id2 AND message LIKE 'PAYMENT ALERT:%did not complete%'")->fetchColumn()===1; });
t('T49','Customer-friendly messages exist for cancelled / timed out / unpaid states', function() use ($pst,$id2) {
    $p = $pst($id2); [$s,$h] = PaymentService::describe($p); $p['result_code']=1037; [,$h2] = PaymentService::describe($p); $p['status']='pending'; $p['requested_at']=date('Y-m-d H:i:s'); [$s3,$h3] = PaymentService::describe($p);
    return $s==='failed' && stripos($h,'cancelled')!==false && stripos($h2,'timed out')!==false && $s3==='pending' && $h3==='Request submitted'; });
t('T50','Retry does not create another order', function() use ($id2,$pdo) { $n = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(); Mpesa::stkPush($id2, '254712345678'); return (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$n; });
$id3 = $pending(0, "ws_C$u");
t('T51','Cancelling an unpaid M-Pesa order closes its payment and returns stock', function() use ($id3,$pdo,$pst,$bread,$stock) {
    $s0 = $stock($bread['id']); OrderService::updateStatus($id3, 'cancelled'); return $pst($id3)['status']==='failed' && $stock($bread['id'])===$s0+1; });
t('T52','Delivered orders can be deleted (hidden, record kept); others cannot', function() use ($uid,$cartBread,$base,$pdo) {
    $id = OrderService::placeOrder($uid, $cartBread(1), $base);
    if (!throws(fn() => OrderService::deleteDelivered($id), 'delivered')) return 'new order was deletable';
    OrderService::updateStatus($id, 'delivered'); OrderService::deleteDelivered($id);
    return $pdo->query("SELECT deleted_at FROM orders WHERE order_id=$id")->fetchColumn() !== null && (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE order_id=$id")->fetchColumn()===1; });
t('T53','Delete customer: no orders = removed; with orders = erased but financial rows kept', function() use ($pdo,$u,$cartBread,$base) {
    require_once 'services/AdminService.php';
    register_user('ZZ Del', "del1$u@t.local", '0712345678', 'password123'); $x = (int)$pdo->query("SELECT user_id FROM users WHERE email='del1$u@t.local'")->fetchColumn();
    AdminService::deleteCustomer($x); if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE user_id=$x")->fetchColumn() !== 0) return 'row not removed';
    register_user('ZZ Del2', "del2$u@t.local", '0712345678', 'password123'); $y = (int)$pdo->query("SELECT user_id FROM users WHERE email='del2$u@t.local'")->fetchColumn();
    $id = OrderService::placeOrder($y, $cartBread(1), $base);
    if (!throws(fn() => AdminService::deleteCustomer($y), 'unfinished')) return 'deleted with an open order';
    OrderService::updateStatus($id, 'delivered'); AdminService::deleteCustomer($y);
    $r = $pdo->query("SELECT u.full_name, u.deleted_at, o.contact_name, o.total_amount FROM users u JOIN orders o ON o.user_id=u.user_id WHERE u.user_id=$y")->fetch();
    return $r && $r['deleted_at'] && $r['full_name']==='Deleted customer' && $r['contact_name']==='Deleted customer' && $r['total_amount'] > 0; });

// cleanup: hide test products (orders reference them so rows are kept)
$pdo->prepare('UPDATE products SET is_active=0 WHERE product_id IN (?,?)')->execute([$cake['id'],$bread['id']]);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
