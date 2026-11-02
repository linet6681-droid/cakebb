<?php
// Run: php tests/smoke_test.php http://localhost/cakebakery
// Needs the schema imported (products exist). Creates one throw-away customer account.
$base = rtrim($argv[1] ?? 'http://localhost/cakebakery', '/');
$jar = tempnam(sys_get_temp_dir(), 'cj'); $pass = $fail = 0;
function req(string $method, string $url, array $data = [], bool $follow = false): array {
    global $jar, $base;
    $ch = curl_init(str_starts_with($url,'http') ? $url : $base.$url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true, CURLOPT_FOLLOWLOCATION=>$follow, CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_TIMEOUT=>20]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return ['code'=>$code, 'head'=>substr((string)$res,0,$hs), 'body'=>substr((string)$res,$hs)];
}
function token(string $html): string { return preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : ''; }
function t(string $name, bool $ok, string $note = ''): void { global $pass,$fail; $ok ? $pass++ : $fail++; echo ($ok ? '  PASS  ' : '  FAIL  ').$name.($ok?'':"   <- $note")."\n"; }

echo "== Public pages ==\n";
foreach (['/index.php','/login.php','/register.php','/contact.php','/cart.php'] as $p) { $r = req('GET',$p); t("GET $p is 200", $r['code']===200, "got {$r['code']}"); }
$r = req('GET','/index.php'); t('catalogue lists products', str_contains($r['body'],'Add to cart') || str_contains($r['body'],'Unavailable'));
t('filter cakes works', req('GET','/index.php?f=cakes')['code']===200);

echo "== Access control ==\n";
foreach (['/admin/index.php','/admin/orders.php','/admin/products.php','/admin/reports.php','/my_orders.php'] as $p) { $r = req('GET',$p); t("guest blocked from $p", in_array($r['code'],[302,403]), "got {$r['code']}"); }
foreach (['/config/config.php','/sql/schema.sql','/logs/error.log','/includes/auth.php'] as $p) { $r = req('GET',$p); t("private file not downloadable: $p", $r['code']!==200 || !str_contains($r['body'],'DB_PASS') && !str_contains($r['body'],'CREATE TABLE'), "got {$r['code']}"); }

echo "== Injection / XSS ==\n";
$r = req('GET','/index.php?q='.urlencode('<script>alert(1)</script>')); t('search XSS escaped', !str_contains($r['body'],'<script>alert(1)</script>'));
$r = req('GET','/index.php?q='.urlencode("' OR 1=1 --")); t('search SQL injection harmless (200)', $r['code']===200);
$tok = token(req('GET','/login.php')['body']);
$r = req('POST','/login.php',['csrf'=>$tok,'email'=>"' OR '1'='1",'password'=>"' OR '1'='1"],true); t('login SQL injection rejected', str_contains($r['body'],'Invalid email or password') || str_contains($r['body'],'Please enter') || !str_contains($r['body'],'Logout'));

echo "== CSRF ==\n";
$r = req('POST','/cart.php',['action'=>'add','product_id'=>1]); t('POST without CSRF token refused (419)', $r['code']===419, "got {$r['code']}");

echo "== Registration & login ==\n";
$tok = token(req('GET','/register.php')['body']);
$r = req('POST','/register.php',['csrf'=>$tok,'name'=>'T','email'=>'bad','phone'=>'123','password'=>'short']); t('bad registration shows errors', str_contains($r['body'],'class="err"'));
$email = 'smoke'.time().'@example.com';
$tok = token(req('GET','/register.php')['body']);
$r = req('POST','/register.php',['csrf'=>$tok,'name'=>'Smoke Tester','email'=>$email,'phone'=>'0712345678','password'=>'Passw0rd!x'],true); t('valid registration succeeds', str_contains($r['body'],'Account created'));
$tok = token(req('GET','/login.php')['body']);
$r = req('POST','/login.php',['csrf'=>$tok,'email'=>$email,'password'=>'wrongpass'],true); t('wrong password rejected', str_contains($r['body'],'Invalid email or password'));
$tok = token(req('GET','/login.php')['body']);
$r = req('POST','/login.php',['csrf'=>$tok,'email'=>$email,'password'=>'Passw0rd!x'],true); t('correct login succeeds', str_contains($r['body'],'Logout') || str_contains($r['body'],'My Orders') || str_contains($r['body'],'Cart ('));
$r = req('GET','/admin/index.php'); t('customer cannot open admin (403)', $r['code']===403, "got {$r['code']}");

echo "== Ordering ==\n";
$page = req('GET','/index.php'); $tok = token($page['body']);
preg_match_all('/name="product_id" value="(\d+)"/', $page['body'], $m); $pid = $m[1][0] ?? 1;
req('POST','/cart.php',['csrf'=>$tok,'action'=>'add','product_id'=>$pid]);
$cart = req('GET','/cart.php'); t('item is in the cart', str_contains($cart['body'],'Order Summary'));
$tok = token($cart['body']); preg_match('/name="ptok" value="([a-f0-9]+)"/', $cart['body'], $ot);
$base_in = ['csrf'=>$tok,'action'=>'place','ptok'=>$ot[1] ?? '','time'=>'10:00','name'=>'Smoke Tester','phone'=>'0712345678','location'=>'','instructions'=>'','method'=>'cash_on_pickup'];
$r = req('POST','/cart.php',$base_in+['date'=>date('Y-m-d',strtotime('-1 day'))],true); t('past date rejected', str_contains($r['body'],'at least') || str_contains($r['body'],'24 hours') || str_contains($r['body'],'1 hour'));
$r = req('POST','/cart.php',array_merge($base_in,['date'=>date('Y-m-d',strtotime('+3 days')),'phone'=>'12']),true); t('bad phone rejected', str_contains($r['body'],'valid Kenyan phone'));
$r = req('POST','/cart.php',array_merge($base_in,['date'=>date('Y-m-d',strtotime('+3 days')),'time'=>'23:00']),true); t('outside opening hours rejected', str_contains($r['body'],'7:00 AM'));
$r = req('POST','/cart.php',array_merge($base_in,['date'=>date('Y-m-d',strtotime('+3 days'))]),true); t('valid order placed', str_contains($r['body'],'placed') && str_contains($r['body'],'My Orders'));
$r = req('POST','/cart.php',array_merge($base_in,['date'=>date('Y-m-d',strtotime('+3 days'))]),true); t('double submit does not create 2nd order', str_contains($r['body'],'already submitted') || str_contains($r['body'],'empty'));

echo "\n$pass passed, $fail failed\n"; @unlink($jar); exit($fail ? 1 : 0);
