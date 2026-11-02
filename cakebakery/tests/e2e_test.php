<?php
/**
 * End-to-end test of the WEB layer (logout, admin access, product pages, delivery fees, admin rights, M-Pesa flow).
 * Run from the project folder, against a DEV database:
 *   php tests/e2e_test.php http://localhost/cakebakery  ADMIN_EMAIL  ADMIN_PASSWORD  [http://127.0.0.1:8098]
 * The optional 4th argument is a local MOCK of Daraja (tests/mock_daraja.php, see its header). Without it the M-Pesa part is skipped.
 * It creates throw-away rows prefixed ZZ and removes the customers and products afterwards.
 */
chdir(__DIR__ . '/..');
require_once 'includes/auth.php';
$base = rtrim($argv[1] ?? 'http://localhost/cakebakery', '/'); $adminEmail = $argv[2] ?? ''; $adminPass = $argv[3] ?? ''; $mock = rtrim($argv[4] ?? '', '/');
$pass = $fail = 0; $pdo = db();
function t(string $name, $ok, string $note = ''): void { global $pass, $fail; $ok = (bool)$ok; $ok ? $pass++ : $fail++; echo ($ok ? '  PASS  ' : '  FAIL  ').$name.($ok ? '' : "   <- $note")."\n"; }
class Web {
    public string $jar;
    function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'cj'); }
    function req(string $m, string $url, array $data = [], bool $follow = false): array {
        global $base;
        $ch = curl_init(str_starts_with($url, 'http') ? $url : $base.$url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true, CURLOPT_FOLLOWLOCATION=>$follow, CURLOPT_COOKIEJAR=>$this->jar, CURLOPT_COOKIEFILE=>$this->jar, CURLOPT_TIMEOUT=>30]);
        if ($m === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        $head = substr((string)$res, 0, $hs);
        return ['code'=>$code, 'head'=>$head, 'body'=>substr((string)$res, $hs), 'loc'=>preg_match('/^Location:\s*(\S+)/mi', $head, $x) ? $x[1] : ''];
    }
    function get(string $u, bool $f = false) { return $this->req('GET', $u, [], $f); }
    function post(string $u, array $d, bool $f = false) { return $this->req('POST', $u, $d, $f); }
    function tok(string $page = '/login.php'): string { $b = $this->get($page, true)['body']; return preg_match('/name="csrf" value="([a-f0-9]+)"/', $b, $m) ? $m[1] : ''; }
    function login(string $e, string $p, string $page = '/login.php'): array { return $this->post($page, ['csrf'=>$this->tok($page), 'email'=>$e, 'password'=>$p]); }
}
$u = uniqid(); $pw = 'Passw0rd!x';
// --- throw-away data ---
$mk = function (string $n, int $cat, float $price, int $stock, string $desc = '') use ($pdo, $u) {
    $pdo->prepare('INSERT INTO products(category_id,name,description,price,stock_qty) VALUES(?,?,?,?,?)')->execute([$cat, "ZZ $n $u", $desc, $price, $stock]); return (int)$pdo->lastInsertId(); };
$bread = $mk('Bread', 3, 100, 500, "Line one of the ZZ description.\nLine two with details $u.");
$hidden = $mk('Hidden', 3, 50, 5); $pdo->prepare('UPDATE products SET is_active=0 WHERE product_id=?')->execute([$hidden]);
$mkUser = function (string $tag) use ($u, $pw) { $e = "zz$tag$u@test.local"; register_user("ZZ $tag", $e, '0712345678', $pw); return [$e, (int)db()->query("SELECT user_id FROM users WHERE email='$e'")->fetchColumn()]; };
[$e1, $uid1] = $mkUser('a'); [$e2, $uid2] = $mkUser('b'); [$e3, $uid3] = $mkUser('c');
$stock = fn($id) => (int)$pdo->query("SELECT stock_qty FROM products WHERE product_id=$id")->fetchColumn();
$ord = fn($id) => $pdo->query("SELECT * FROM orders WHERE order_id=$id")->fetch();
$pay = fn($id) => $pdo->query("SELECT * FROM payments WHERE order_id=$id")->fetch();
$date = date('Y-m-d', strtotime('+2 days'));

echo "== 1. Access control and logout ==\n";
$g = new Web();
foreach (['/admin/index.php','/admin/orders.php','/admin/products.php','/admin/customers.php','/admin/payments.php','/admin/delivery.php','/admin/reports.php','/admin/order_view.php?id=1'] as $p) { $r = $g->get($p); t("guest sent to admin login from $p", $r['code']===302 && str_contains($r['loc'], 'admin/login.php'), "{$r['code']} {$r['loc']}"); }
$r = $g->get('/my_orders.php'); t('guest sent to customer login from my_orders', $r['code']===302 && str_contains($r['loc'], 'login.php') && !str_contains($r['loc'], 'admin'));
$c = new Web(); $r = $c->login($e1, $pw); t('customer logs in', $r['code']===302, (string)$r['code']);
foreach (['/admin/index.php','/admin/orders.php','/admin/products.php','/admin/customers.php','/admin/payments.php','/admin/delivery.php','/admin/reports.php'] as $p) { $r = $c->get($p); t("customer blocked (403) from $p", $r['code']===403, (string)$r['code']); }
$tok = $c->tok('/index.php');
$r = $c->post('/admin/customers.php', ['csrf'=>$tok, 'action'=>'delete', 'user_id'=>$uid2]); t('customer cannot POST an admin action (403)', $r['code']===403 && $pdo->query("SELECT COUNT(*) FROM users WHERE user_id=$uid2 AND deleted_at IS NULL")->fetchColumn()==1, (string)$r['code']);
$r = $c->post('/admin/orders.php', ['csrf'=>$tok, 'action'=>'cancel', 'order_id'=>1]); t('customer cannot cancel orders via admin page (403)', $r['code']===403, (string)$r['code']);
$r = $c->get('/index.php', true);
t('customer shop page has a visible Logout button (POST form)', preg_match('#<form method="post" action="[^"]*/logout\.php"[^>]*>.*?>Logout</button>#s', $r['body']) === 1);
t('signed-in pages are sent with Cache-Control: no-store', (bool)preg_match('/Cache-Control:[^\r\n]*no-store/i', $c->get('/my_orders.php')['head']));
$c->get('/logout.php'); t('opening /logout.php by link does NOT log you out (needs the button)', $c->get('/my_orders.php')['code']===200);
$r = $c->post('/logout.php', ['csrf'=>'wrong', 'to'=>'customer']); t('logout without a valid token is refused', $r['code']===419 && $c->get('/my_orders.php')['code']===200, (string)$r['code']);
$r = $c->post('/logout.php', ['csrf'=>$c->tok('/my_orders.php'), 'to'=>'customer']); t('customer logout redirects to login page', $r['code']===302 && str_contains($r['loc'], '/login.php') && !str_contains($r['loc'], 'admin'), $r['loc']);
t('Clear-Site-Data header sent on logout', (bool)stripos($r['head'], 'Clear-Site-Data'));
$r = $c->get('/my_orders.php'); t('after logout the Back-button page is gone: my_orders needs login again', $r['code']===302);
$r = $c->get('/admin/index.php'); t('after logout admin pages stay closed', $r['code']===302);
$a = new Web(); $r = $a->login($e1, $pw, '/admin/login.php'); t('customer cannot use the ADMIN login page', $r['code']===200 && str_contains($r['body'], 'Invalid email or password') && $a->get('/admin/index.php')['code']===302);
if ($adminEmail !== '') {
    $r = $a->login($adminEmail, $adminPass, '/admin/login.php'); t('admin logs in on the admin login page', $r['code']===302 && str_contains($r['loc'], 'admin/index.php'), "{$r['code']} {$r['loc']}");
    foreach (['/admin/index.php','/admin/products.php','/admin/orders.php','/admin/customers.php','/admin/payments.php','/admin/delivery.php','/admin/reports.php','/admin/reports.php?p=weekly','/admin/reports.php?p=daily'] as $p) { $r = $a->get($p); t("admin page loads: $p", $r['code']===200 && !str_contains($r['body'], 'went wrong'), (string)$r['code']); }
    $r = $a->get('/admin/index.php'); t('admin dashboard has visible Logout button', preg_match('#action="[^"]*/logout\.php".*?>Logout</button>#s', $r['body']) === 1);
    $ca = new Web(); $ca->login($adminEmail, $adminPass); $r = $ca->get('/admin/index.php'); t('admin can also come in through the normal login', $r['code']===200);
    $r = $a->post('/logout.php', ['csrf'=>$a->tok('/admin/index.php'), 'to'=>'admin']); t('admin logout redirects to ADMIN login page', $r['code']===302 && str_contains($r['loc'], 'admin/login.php'), $r['loc']);
    t('after admin logout dashboard is closed', $a->get('/admin/index.php')['code']===302);
    $a->login($adminEmail, $adminPass, '/admin/login.php');
} else echo "  (admin tests skipped: no admin email/password given)\n";

echo "== 2. Product details ==\n";
$g = new Web(); $r = $g->get('/index.php'); t('shop cards link to product page', str_contains($r['body'], "product.php?id=$bread") || preg_match('#product\.php\?id=\d+#', $r['body']));
$r = $g->get("/product.php?id=$bread"); $b = $r['body'];
t('product page opens for a guest (no login, no cart)', $r['code']===200);
t('shows name, price, image and Add to cart', str_contains($b, "ZZ Bread $u") && str_contains($b, 'Ksh 100') && str_contains($b, '<img') && str_contains($b, 'Add to cart'));
t('shows the description stored in the database', str_contains($b, 'Line one of the ZZ description.') && str_contains($b, "Line two with details $u."));
t('mobile ready (viewport meta)', str_contains($b, 'name="viewport"'));
t('unknown product id gives 404', $g->get('/product.php?id=99999999')['code']===404);
t('hidden (deleted) product gives 404', $g->get("/product.php?id=$hidden")['code']===404);
t('text id gives 404 not an error page', $g->get('/product.php?id=abc')['code']===404);
$r = $g->post('/cart.php', ['csrf'=>$g->tok("/product.php?id=$bread"), 'action'=>'add', 'product_id'=>$bread, 'back'=>BASE_URL."/product.php?id=$bread"]); t('Add to cart from the product page works', $r['code']===302 && str_contains($r['loc'], 'product.php'));

echo "== 3. Delivery fees by destination ==\n";
$want = ['Within CBD'=>0, 'Between Allsops and Juja'=>100, 'Thika'=>150, "Murang'a"=>250, 'Nyeri'=>350, 'Nakuru'=>400, 'Mombasa'=>500];
$zones = $pdo->query('SELECT zone_id,name,fee FROM delivery_zones WHERE is_active=1')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
$byName = []; foreach ($zones as $id=>$z) $byName[$z['name']] = ['id'=>$id, 'fee'=>(float)$z['fee']];
foreach ($want as $n=>$f) t("destination '$n' exists with fee Ksh $f", isset($byName[$n]) && $byName[$n]['fee']===(float)$f);
t('old flat/sample areas are gone', !$pdo->query("SELECT COUNT(*) FROM delivery_zones WHERE name IN ('Machakos Town (CBD)','Nairobi','Athi River / Mlolongo')")->fetchColumn());
$cu = new Web(); $cu->login($e1, $pw);
$cart = function (Web $w, int $qty = 2) use ($bread) { $w->post('/cart.php', ['csrf'=>$w->tok('/index.php'), 'action'=>'add', 'product_id'=>$bread]); for ($i = 1; $i < $qty; $i++) $w->post('/cart.php', ['csrf'=>$w->tok('/index.php'), 'action'=>'update', 'product_id'=>$bread, 'op'=>'plus']); };
$cart($cu, 2); $page = $cu->get('/cart.php')['body'];
foreach ($want as $n=>$f) t("checkout lists '$n' ".($f ? "at Ksh $f" : 'as Free'), str_contains($page, htmlspecialchars($n, ENT_QUOTES).' — '.($f ? 'Ksh '.$f : 'Free')));
t('checkout shows Subtotal and Delivery fee separately', str_contains($page, '>Subtotal<') && str_contains($page, '>Delivery fee<') && str_contains($page, 'id="total"'));
$fields = fn(array $x) => $x + ['name'=>'ZZ Buyer', 'phone'=>'0712345678', 'date'=>$date, 'time'=>'10:00', 'zone'=>'', 'location'=>'', 'instructions'=>'', 'method'=>'cash_on_pickup', 'mpesa_phone'=>'', 'action'=>'place'];
$placeWith = function (Web $w, array $x, int $qty = 2) use ($cart, $fields, $pdo) {
    $page = $w->get('/cart.php')['body'];
    if (!str_contains($page, 'name="ptok"')) { $cart($w, $qty); $page = $w->get('/cart.php')['body']; }
    preg_match('/name="ptok" value="([a-f0-9]+)"/', $page, $m); preg_match('/name="csrf" value="([a-f0-9]+)"/', $page, $c);
    return $w->post('/cart.php', $fields($x) + ['csrf'=>$c[1] ?? '', 'ptok'=>$m[1] ?? '']);
};
$lastOrder = fn() => (int)$pdo->query('SELECT MAX(order_id) FROM orders')->fetchColumn();
foreach ($want as $n=>$f) {
    $cu->post('/cart.php', ['csrf'=>$cu->tok('/index.php'), 'action'=>'remove', 'product_id'=>$bread]); $cart($cu, 2);
    $r = $placeWith($cu, ['zone'=>$byName[$n]['id'], 'location'=>'ZZ Street 1', 'delivery_fee'=>'0', 'total_amount'=>'1', 'total'=>'1']); $id = $lastOrder(); $o = $ord($id);
    t("order to $n: fee Ksh $f, total = 200 + fee, destination saved", $o && $o['destination']===$n && (float)$o['delivery_fee']===(float)$f && (float)$o['total_amount']===200.0+$f && $o['delivery_address']==='ZZ Street 1', json_encode($o));
}
$before = $lastOrder();
$cart($cu, 1); $r = $placeWith($cu, ['zone'=>'99999', 'location'=>'x street']); t('unknown destination id rejected, no order created', $lastOrder()===$before && str_contains($r['body'], 'delivery destination'));
$r = $placeWith($cu, ['zone'=>$byName['Thika']['id'], 'location'=>'']); t('delivery without an address is rejected', $lastOrder()===$before);
$r = $placeWith($cu, ['zone'=>'', 'location'=>'']); $o = $ord($lastOrder()); t('pickup: no fee, no destination', $lastOrder()>$before && (float)$o['delivery_fee']===0.0 && $o['destination']===null && $o['fulfilment']==='pickup');
$pickupId = $lastOrder();
$oldFee = $pdo->query("SELECT delivery_fee FROM orders WHERE destination='Mombasa' ORDER BY order_id DESC LIMIT 1")->fetchColumn();
$pdo->prepare("UPDATE delivery_zones SET fee=999 WHERE name='Mombasa'")->execute();
t('changing a fee later does not change old orders', (float)$pdo->query("SELECT delivery_fee FROM orders WHERE destination='Mombasa' ORDER BY order_id DESC LIMIT 1")->fetchColumn()===(float)$oldFee);
$pdo->prepare("UPDATE delivery_zones SET fee=500 WHERE name='Mombasa'")->execute();
$r = $cu->get('/my_orders.php')['body']; t('customer order history shows destination, fee and total', str_contains($r, 'Mombasa') && str_contains($r, 'Ksh 500') && str_contains($r, 'Ksh 700'));
$cart($cu, 1); $cu->get('/cart.php'); $page = $cu->get('/cart.php')['body']; preg_match('/name="ptok" value="([a-f0-9]+)"/', $page, $m); preg_match('/name="csrf" value="([a-f0-9]+)"/', $page, $cs);
$n0 = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(); $form = $fields(['zone'=>$byName['Thika']['id'], 'location'=>'ZZ dbl']) + ['csrf'=>$cs[1], 'ptok'=>$m[1]];
$cu->post('/cart.php', $form); $cu->post('/cart.php', $form);
t('double submit creates only ONE order', (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === $n0 + 1);

if ($adminEmail !== '') {
echo "== 4. Admin: orders ==\n";
$ids = $pdo->query("SELECT order_id FROM orders WHERE user_id=$uid1 ORDER BY order_id")->fetchAll(PDO::FETCH_COLUMN);
$target = (int)$ids[0]; $o = $ord($target);
$r = $a->get('/admin/orders.php?all=1'); $b = $r['body'];
t('admin order list shows customer, destination, fee, total and payment status', str_contains($b, "#$target") && str_contains($b, 'ZZ Buyer') && str_contains($b, '>Destination<') && str_contains($b, 'pill pay-'));
$r = $a->get("/admin/order_view.php?id=$target"); $b = $r['body'];
t('order detail shows customer, items, destination, fee, total, payment', $r['code']===200 && str_contains($b, 'ZZ Buyer') && str_contains($b, "ZZ Bread $u") && str_contains($b, htmlspecialchars((string)$o['destination'])) && str_contains($b, 'Delivery fee') && str_contains($b, 'Order total') && str_contains($b, 'Payment'));
$tk = $a->tok("/admin/order_view.php?id=$target");
$a->post('/admin/orders.php', ['csrf'=>$tk, 'action'=>'status', 'order_id'=>$target, 'status'=>'in_progress']); t('admin updates status in the database', $ord($target)['status']==='in_progress');
$cu2 = new Web(); $cu2->login($e1, $pw); t('customer history shows the new status', str_contains($cu2->get('/my_orders.php')['body'], 'Baking'));
$s0 = $stock($bread); $items = (int)$pdo->query("SELECT SUM(quantity) FROM order_items WHERE order_id=$target")->fetchColumn();
$r = $a->post('/admin/orders.php', ['csrf'=>$tk, 'action'=>'cancel', 'order_id'=>$target]); $o = $ord($target);
t('admin cancels order: status Cancelled, stock returned', $o['status']==='cancelled' && $stock($bread)===$s0+$items, $o['status'].' '.$stock($bread)." vs ".($s0+$items));
t('cancelled shows in admin list and customer history', str_contains($a->get('/admin/orders.php?all=1')['body'], 'st-cancelled') && str_contains($cu2->get('/my_orders.php')['body'], 'Cancelled'));
$a->post('/admin/orders.php', ['csrf'=>$tk, 'action'=>'status', 'order_id'=>$target, 'status'=>'new']); t('cancelled order cannot be re-opened', $ord($target)['status']==='cancelled');
$s1 = $stock($bread); $a->post('/admin/orders.php', ['csrf'=>$tk, 'action'=>'cancel', 'order_id'=>$target]); t('cancelling twice does not add stock twice', $stock($bread)===$s1);
$d = (int)$ids[1]; $a->post('/admin/orders.php', ['csrf'=>$tk, 'action'=>'delete', 'order_id'=>$d]); t('only DELIVERED orders can be deleted (new order refused)', $pdo->query("SELECT deleted_at FROM orders WHERE order_id=$d")->fetchColumn() === null);
$a->post('/admin/orders.php', ['csrf'=>$tk, 'action'=>'status', 'order_id'=>$d, 'status'=>'delivered']);
$a->post('/admin/orders.php', ['csrf'=>$tk, 'action'=>'cancel', 'order_id'=>$d]); t('a delivered order cannot be cancelled', $ord($d)['status']==='delivered');
$page = $a->get("/admin/order_view.php?id=$d")['body']; t('delete button asks for confirmation', str_contains($page, "confirm('Delete delivered order #$d"));
$page = $a->get('/admin/orders.php')['body']; t('orders page has confirmation dialog for delete', str_contains($page, 'dlg-delete') && str_contains($page, 'Delete delivered order'));
$other = (int)$ids[2]; $oBefore = json_encode($ord($other)); $nAll = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(); $nPay = (int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
$a->post('/admin/orders.php', ['csrf'=>$tk, 'action'=>'delete', 'order_id'=>$d]);
t('delivered order is deleted: gone from admin list and customer history', !str_contains($a->get('/admin/orders.php?all=1')['body'], 'data-id="'.$d.'"') && $pdo->query("SELECT deleted_at FROM orders WHERE order_id=$d")->fetchColumn() !== null && !preg_match("#track\.php\?id=$d\"#", $cu2->get('/my_orders.php')['body']));
t('deleting leaves unrelated orders untouched', json_encode($ord($other))===$oBefore && (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$nAll);
t('financial record (payment row, items) is kept', (int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn()===$nPay && (int)$pdo->query("SELECT COUNT(*) FROM order_items WHERE order_id=$d")->fetchColumn()>0);
t('deleted order page is gone for the customer (404)', $cu2->get("/track.php?id=$d")['code']===404);
$r = $a->post('/admin/orders.php', ['action'=>'cancel', 'order_id'=>$other]); t('admin POST without CSRF token is refused (419)', $r['code']===419 && $ord($other)['status']!=='cancelled');

echo "== 5. Admin: customers ==\n";
$page = $a->get('/admin/customers.php')['body']; t('customer list shows registered customers', str_contains($page, $e1) && str_contains($page, $e2));
t('delete customer asks for confirmation', str_contains($page, "confirm('Delete customer"));
$tk = $a->tok('/admin/customers.php');
$open = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE user_id=$uid1 AND status IN ('new','in_progress','ready')")->fetchColumn();
$a->post('/admin/customers.php', ['csrf'=>$tk, 'action'=>'delete', 'user_id'=>$uid1]); t('customer with unfinished orders cannot be deleted', $open > 0 && $pdo->query("SELECT deleted_at FROM users WHERE user_id=$uid1")->fetchColumn()===null);
$a->post('/admin/customers.php', ['csrf'=>$tk, 'action'=>'delete', 'user_id'=>(int)$pdo->query("SELECT user_id FROM users WHERE role='admin' LIMIT 1")->fetchColumn()]); t('admin accounts cannot be deleted from the customer page', (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND deleted_at IS NULL")->fetchColumn() >= 1);
$a->post('/admin/customers.php', ['csrf'=>$tk, 'action'=>'delete', 'user_id'=>$uid3]); t('customer with NO orders is removed completely', (int)$pdo->query("SELECT COUNT(*) FROM users WHERE user_id=$uid3")->fetchColumn()===0);
foreach ($pdo->query("SELECT order_id FROM orders WHERE user_id=$uid1 AND status IN ('new','in_progress','ready')")->fetchAll(PDO::FETCH_COLUMN) as $oid) $a->post('/admin/orders.php', ['csrf'=>$a->tok('/admin/orders.php'), 'action'=>'status', 'order_id'=>$oid, 'status'=>'delivered']);
$sums = $pdo->query("SELECT COUNT(*) n, SUM(total_amount) t FROM orders WHERE user_id=$uid1")->fetch(); $pn = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE order_id IN (SELECT order_id FROM orders WHERE user_id=$uid1)")->fetchColumn();
$a->post('/admin/customers.php', ['csrf'=>$a->tok('/admin/customers.php'), 'action'=>'delete', 'user_id'=>$uid1]);
$ur = $pdo->query("SELECT * FROM users WHERE user_id=$uid1")->fetch();
t('customer with history: personal data erased, row kept', $ur && $ur['deleted_at'] && $ur['full_name']==='Deleted customer' && $ur['email']!==$e1 && $ur['phone']==='');
$s2 = $pdo->query("SELECT COUNT(*) n, SUM(total_amount) t FROM orders WHERE user_id=$uid1")->fetch();
t('their orders and totals are kept for accounting', $s2['n']==$sums['n'] && $s2['t']==$sums['t'] && (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE order_id IN (SELECT order_id FROM orders WHERE user_id=$uid1)")->fetchColumn()===$pn);
t('order contact details were blanked', $pdo->query("SELECT COUNT(*) FROM orders WHERE user_id=$uid1 AND (contact_name<>'Deleted customer' OR contact_phone<>'' OR delivery_address IS NOT NULL)")->fetchColumn()==0);
$x = new Web(); $r = $x->login($e1, $pw); t('deleted customer can no longer log in', $r['code']===200 && str_contains($r['body'], 'Invalid email or password'));
t('deleted customer disappears from the customer list', !str_contains($a->get('/admin/customers.php')['body'], $e1));
$dead = new Web(); $dead->login($e2, $pw); $a->post('/admin/customers.php', ['csrf'=>$a->tok('/admin/customers.php'), 'action'=>'delete', 'user_id'=>$uid2]); t('a logged-in customer who is deleted loses access at once', $dead->get('/my_orders.php')['code']===302);
$pdo->prepare('UPDATE users SET locked_until=?, failed_attempts=0 WHERE user_id=?')->execute([date('Y-m-d H:i:s', time()+600), $uid3 ?: 0]);

echo "== 6. Admin: products ==\n";
$tk = $a->tok('/admin/products.php');
$r = $a->post('/admin/products.php', ['csrf'=>$tk, 'action'=>'save', 'product_id'=>0, 'name'=>"ZZ New $u", 'category_id'=>3, 'price'=>'250', 'stock'=>'9', 'description'=>"Fresh ZZ loaf.\nBaked daily."]);
$np = (int)$pdo->query("SELECT product_id FROM products WHERE name='ZZ New $u'")->fetchColumn(); t('admin adds a product', $np > 0 && $r['code']===302);
t('new product appears on the shop and has a product page with its description', str_contains($g->get('/index.php')['body'], "ZZ New $u") && str_contains($g->get("/product.php?id=$np")['body'], 'Baked daily.'));
$a->post('/admin/products.php', ['csrf'=>$tk, 'action'=>'save', 'product_id'=>$np, 'name'=>"ZZ New $u", 'category_id'=>3, 'price'=>'300', 'stock'=>'4', 'description'=>'Changed text.']);
$b = $g->get("/product.php?id=$np")['body']; t('edit is reflected on the customer page (price, description)', str_contains($b, 'Ksh 300') && str_contains($b, 'Changed text.'));
$r = $a->post('/admin/products.php', ['csrf'=>$tk, 'action'=>'save', 'product_id'=>0, 'name'=>'', 'category_id'=>3, 'price'=>'-5', 'stock'=>'x']); t('invalid product data is rejected', $pdo->query("SELECT COUNT(*) FROM products WHERE price<0")->fetchColumn()==0 && $r['code']===200);
$a->post('/admin/products.php', ['csrf'=>$tk, 'action'=>'delete', 'product_id'=>$np]);
t('deleted product disappears from the shop and its page', !str_contains($g->get('/index.php')['body'], "ZZ New $u") && $g->get("/product.php?id=$np")['code']===404);
t('admin can edit delivery destinations page', $a->get('/admin/delivery.php')['code']===200 && str_contains($a->get('/admin/delivery.php')['body'], 'Between Allsops and Juja'));
}

if ($mock !== '' && $adminEmail !== '') {
echo "== 7. M-Pesa (against the LOCAL MOCK of Daraja, not Safaricom) ==\n";
$mget = function (string $path) use ($mock) { return json_decode((string)file_get_contents($mock.$path), true); };
$cm = new Web(); [$e4, $uid4] = $mkUser('m'); $cm->login($e4, $pw);
$nOrders = fn() => (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$mpesaOrder = function (array $x = []) use ($cm, $placeWith, $cart, $lastOrder, $byName) {
    $cm->post('/cart.php', ['csrf'=>$cm->tok('/index.php'), 'action'=>'remove', 'product_id'=>$GLOBALS['bread']]);
    $cart($cm, 2); $GLOBALS['lastResp'] = $placeWith($cm, $x + ['method'=>'mpesa', 'zone'=>$byName['Mombasa']['id'], 'location'=>'ZZ Rd', 'mpesa_phone'=>'0712 345 678']); return $lastOrder(); };
$before = $nOrders(); $mpesaOrder(['mpesa_phone'=>'12345']); $r = $GLOBALS['lastResp']; t('invalid M-Pesa number is rejected before any payment', $nOrders()===$before && str_contains($r['body'], 'valid M-Pesa phone'));
$id1 = $mpesaOrder(); $p = $pay($id1); $o = $ord($id1); $reqs = $mget('/_mock/requests'); $rq = $reqs[$p['checkout_request_id']] ?? null;
t('STK push sent: payment is PENDING (not paid) with Safaricom ids', $p['status']==='pending' && $p['checkout_request_id'] && $p['merchant_request_id'], json_encode($p));
t('amount sent to Safaricom = subtotal + delivery fee (Ksh 700)', $rq && (int)$rq['amount']===700 && (float)$o['total_amount']===700.0 && (float)$p['amount']===700.0, json_encode($rq));
t('prompt goes to the entered M-Pesa number, reference ORDER<id>', $rq && $rq['phone']==='254712345678' && $rq['ref']==="ORDER$id1");
t('order page says the request was submitted / waiting', preg_match('/Request submitted|Waiting for your PIN/', $cm->get("/track.php?id=$id1")['body']) === 1);
$j = json_decode($cm->get("/payment_status.php?id=$id1")['body'], true); t('status endpoint reports pending', ($j['state'] ?? '')==='pending', json_encode($j));
t("another customer cannot read this order's payment status", (new Web())->get("/payment_status.php?id=$id1")['code']===401);
$cb = $base.'/pay_callback.php'; $ck = defined('MPESA_CALLBACK_SECRET') ? MPESA_CALLBACK_SECRET : '';
$body = fn($cid, $code, $amount = 700, $receipt = 'RCP1234XYZ') => json_encode(['Body'=>['stkCallback'=>['MerchantRequestID'=>$pay($GLOBALS['id1'])['merchant_request_id'], 'CheckoutRequestID'=>$cid, 'ResultCode'=>$code, 'ResultDesc'=>'x'] + ($code===0 ? ['CallbackMetadata'=>['Item'=>[['Name'=>'Amount','Value'=>$amount],['Name'=>'MpesaReceiptNumber','Value'=>$receipt],['Name'=>'PhoneNumber','Value'=>254712345678]]]] : [])]]);
$rawPost = function (string $url, string $json) { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$json, CURLOPT_HTTPHEADER=>['Content-Type: application/json']]); $o = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$c, $o]; };
$cid1 = $p['checkout_request_id'];
if ($ck !== '') { [$c1] = $rawPost($cb, $body($cid1, 0)); t('callback without the secret key is rejected (403) and changes nothing', $c1===403 && $pay($id1)['status']==='pending'); }
[$c1] = $rawPost("$cb?k=".urlencode($ck), 'not json'); t('garbage callback body is rejected (400)', $c1===400);
[$c1] = $rawPost("$cb?k=".urlencode($ck), $body('ws_CO_UNKNOWN', 0)); t('callback for an unknown CheckoutRequestID is ignored', $c1===200 && $pay($id1)['status']==='pending');
[$c1] = $rawPost("$cb?k=".urlencode($ck), $body($cid1, 0, 100)); t('callback with the WRONG amount is NOT marked paid', $pay($id1)['status']==='pending' && (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$id1 AND message LIKE '%REVIEW%'")->fetchColumn()===1);
[$c1, $o1] = $rawPost("$cb?k=".urlencode($ck), $body($cid1, 0)); $p = $pay($id1);
t('valid success callback: PAID with receipt, amount and number saved', $c1===200 && $p['status']==='paid' && $p['mpesa_receipt']==='RCP1234XYZ' && (float)$p['paid_amount']===700.0 && $p['payer_phone']==='254712345678' && (int)$p['result_code']===0, json_encode($p));
$rawPost("$cb?k=".urlencode($ck), $body($cid1, 0)); $rawPost("$cb?k=".urlencode($ck), $body($cid1, 0));
t('duplicate callbacks are processed only once (1 SMS, 1 admin alert)', (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$id1 AND message LIKE 'Cake & Bakery: payment received%'")->fetchColumn()===1 && (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$id1 AND message LIKE 'PAYMENT ALERT:%Confirmed%'")->fetchColumn()===1);
$rawPost("$cb?k=".urlencode($ck), $body($cid1, 1032)); t('a late failure callback never overwrites a paid payment', $pay($id1)['status']==='paid');
t('order page shows success and receipt', str_contains($cm->get("/track.php?id=$id1")['body'], 'Payment successful') && str_contains($cm->get("/track.php?id=$id1")['body'], 'RCP1234XYZ'));
t('admin dashboard shows the payment alert', str_contains($a->get('/admin/index.php')['body'], 'RCP1234XYZ') && str_contains($a->get('/admin/payments.php')['body'], 'RCP1234XYZ'));
t('admin order view shows the M-Pesa receipt', str_contains($a->get("/admin/order_view.php?id=$id1")['body'], 'RCP1234XYZ'));

$id2 = $mpesaOrder(); $p2 = $pay($id2); $n2 = $nOrders();
$mget("/_mock/complete?id=".urlencode($p2['checkout_request_id'])."&code=1032");
$p2 = $pay($id2); t('customer cancels on phone: payment FAILED with code 1032 (order stays unpaid)', $p2['status']==='failed' && (int)$p2['result_code']===1032 && $ord($id2)['status']==='new');
t('customer sees a "cancelled" message and a retry form', str_contains($cm->get("/track.php?id=$id2")['body'], 'Payment cancelled') && str_contains($cm->get("/track.php?id=$id2")['body'], 'Try M-Pesa again'));
t('admin got a "did not complete" alert', (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$id2 AND message LIKE 'PAYMENT ALERT:%did not complete%'")->fetchColumn()===1);
$cm->post("/track.php?id=$id2", ['csrf'=>$cm->tok("/track.php?id=$id2"), 'id'=>$id2, 'mpesa_phone'=>'0799 111 222']);
$p2b = $pay($id2); t('retry sends a NEW prompt on the SAME order (no duplicate order)', $p2b['status']==='pending' && $p2b['checkout_request_id']!==$p2['checkout_request_id'] && $nOrders()===$n2 && $p2b['payer_phone']==='254799111222', json_encode($p2b));
$cnt = count($mget('/_mock/requests')); $cm->post("/track.php?id=$id2", ['csrf'=>$cm->tok("/track.php?id=$id2"), 'id'=>$id2]); t('pressing retry again straight away does not send a second prompt', count($mget('/_mock/requests'))===$cnt);
$mget("/_mock/complete?id=".urlencode($p2b['checkout_request_id'])."&code=0&receipt=RETRYOK1"); t('retry then succeeds: paid', $pay($id2)['status']==='paid' && $pay($id2)['mpesa_receipt']==='RETRYOK1');

$id3 = $mpesaOrder(); $p3 = $pay($id3);
$mget("/_mock/complete?id=".urlencode($p3['checkout_request_id'])."&code=1037&post=0");
$j = json_decode($cm->get("/payment_status.php?id=$id3")['body'], true);
t('timeout found through STK Query (no callback arrived): failed, "timed out" message', ($j['state'] ?? '')==='failed' && str_contains($j['headline'] ?? '', 'timed out') && (int)$pay($id3)['result_code']===1037, json_encode($j));
$id4 = $mpesaOrder(); $p4 = $pay($id4); $mget("/_mock/complete?id=".urlencode($p4['checkout_request_id'])."&code=0&post=0");
$j = json_decode($cm->get("/payment_status.php?id=$id4")['body'], true); t('success found through STK Query when the callback URL is unreachable', ($j['state'] ?? '')==='paid' && $pay($id4)['status']==='paid', json_encode($j));
$mget("/_mock/complete?id=".urlencode($p4['checkout_request_id'])."&code=0&receipt=LATECB77"); t('callback arriving later fills in the missing receipt without a 2nd confirmation', $pay($id4)['mpesa_receipt']==='LATECB77' && (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$id4 AND message LIKE 'PAYMENT ALERT:%Confirmed%'")->fetchColumn()===1);

$id5 = $mpesaOrder(); $s0 = $stock($bread); $items = (int)$pdo->query("SELECT SUM(quantity) FROM order_items WHERE order_id=$id5")->fetchColumn();
$a->post('/admin/orders.php', ['csrf'=>$a->tok('/admin/orders.php'), 'action'=>'cancel', 'order_id'=>$id5]);
t('cancelling an unpaid M-Pesa order closes its payment and returns stock', $pay($id5)['status']==='failed' && $stock($bread)===$s0+$items);
$mget("/_mock/complete?id=".urlencode($p3['checkout_request_id'])."&code=0&receipt=ZZ");   // stale id from order 3
$id6 = $mpesaOrder(); $p6 = $pay($id6); $mget("/_mock/complete?id=".urlencode($p6['checkout_request_id'])."&code=0&receipt=PAIDTHENCANCEL");
$r = $a->post('/admin/orders.php', ['csrf'=>$a->tok('/admin/orders.php'), 'action'=>'cancel', 'order_id'=>$id6]); $f = $a->get('/admin/orders.php')['body'];
t('cancelling a PAID order warns the admin to refund', $ord($id6)['status']==='cancelled' && $pay($id6)['status']==='paid' && str_contains($f, 'refund'));
$r = $cm->get('/cart.php?x=1'); t('config problems never reach the customer as stack traces', !str_contains($r['body'], 'PDOException') && !str_contains($r['body'], MPESA_CONSUMER_SECRET ?: 'zzzz-none'));
t('M-Pesa secret keys do not appear in any page', !str_contains($cm->get("/track.php?id=$id1")['body'], MPESA_CONSUMER_KEY ?: 'zzzz-none') && !str_contains($a->get('/admin/payments.php')['body'], MPESA_CONSUMER_SECRET ?: 'zzzz-none'));
t('M-Pesa log does not contain the Password or token', !preg_match('/"Password":"[A-Za-z0-9+\/=]{8,}"/', (string)@file_get_contents('logs/mpesa.log')) && !str_contains((string)@file_get_contents('logs/mpesa.log'), 'MOCKTOKEN'));
}

// ---- clean up the throw-away products (orders stay: they belong to the test customers) ----
$pdo->prepare('UPDATE products SET is_active=0 WHERE name LIKE ?')->execute(["ZZ % $u"]);
echo "\nPassed: $pass   Failed: $fail\n";
exit($fail ? 1 : 0);
