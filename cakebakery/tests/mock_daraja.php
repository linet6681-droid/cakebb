<?php
/**
 * DEV-ONLY stand-in for the Safaricom Daraja server, so the shop's M-Pesa code (token, STK push, STK query, callback)
 * can be exercised on a laptop with no internet and no real phone. It is NOT Safaricom and proves nothing about
 * your Daraja account: always also test with the real sandbox (README > "Daraja setup").
 * Start:  php -S 127.0.0.1:8098 tests/mock_daraja.php      then set MPESA_BASE_URL = 'http://127.0.0.1:8098' in config/secrets.php (tests only!)
 * It checks the same rules Daraja enforces (field names, lengths, Password = base64(shortcode+passkey+timestamp)).
 */
$stateFile = getenv('MOCK_STATE') ?: sys_get_temp_dir().'/mock_daraja_state.json';
$S = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : ['reqs'=>[]];
$save = function () use (&$S, $stateFile) { file_put_contents($stateFile, json_encode($S)); };
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
$out = function ($data, int $code = 200) { http_response_code($code); echo json_encode($data); exit; };
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$KEY = 'mockkey'; $SECRET = 'mocksecret'; $PASSKEY = getenv('MOCK_PASSKEY') ?: 'mockpasskey';

if ($path === '/oauth/v1/generate') {
    $ok = ($_SERVER['PHP_AUTH_USER'] ?? '') === $KEY && ($_SERVER['PHP_AUTH_PW'] ?? '') === $SECRET;
    if (!$ok) $out(['errorCode'=>'400.008.01', 'errorMessage'=>'Invalid Authentication passed'], 400);
    $out(['access_token'=>'MOCKTOKEN', 'expires_in'=>'3599']);
}
if (str_starts_with($path, '/mpesa/')) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer MOCKTOKEN') $out(['requestId'=>'x', 'errorCode'=>'404.001.03', 'errorMessage'=>'Invalid Access Token'], 401);
}
if ($path === '/mpesa/stkpush/v1/processrequest') {
    $need = ['BusinessShortCode','Password','Timestamp','TransactionType','Amount','PartyA','PartyB','PhoneNumber','CallBackURL','AccountReference','TransactionDesc'];
    foreach ($need as $f) if (!isset($body[$f]) || $body[$f] === '') $out(['requestId'=>'x', 'errorCode'=>'400.002.02', 'errorMessage'=>"Bad Request - Invalid $f"], 400);
    if (!preg_match('/^254[17]\d{8}$/', (string)$body['PhoneNumber'])) $out(['requestId'=>'x', 'errorCode'=>'400.002.02', 'errorMessage'=>'Bad Request - Invalid PhoneNumber'], 400);
    if (!is_int($body['Amount']) || $body['Amount'] < 1) $out(['requestId'=>'x', 'errorCode'=>'400.002.02', 'errorMessage'=>'Bad Request - Invalid Amount'], 400);
    if (strlen($body['AccountReference']) > 12) $out(['requestId'=>'x', 'errorCode'=>'400.002.02', 'errorMessage'=>'Bad Request - Invalid AccountReference'], 400);
    if (strlen($body['TransactionDesc']) > 13) $out(['requestId'=>'x', 'errorCode'=>'400.002.02', 'errorMessage'=>'Bad Request - Invalid TransactionDesc'], 400);
    if ($body['Password'] !== base64_encode($body['BusinessShortCode'].$PASSKEY.$body['Timestamp'])) $out(['requestId'=>'x', 'errorCode'=>'500.001.1001', 'errorMessage'=>'Wrong credentials'], 500);
    $cid = 'ws_CO_'.date('YmdHis').random_int(100000, 999999); $mid = random_int(10000, 99999).'-'.random_int(1000000, 9999999).'-1';
    $S['reqs'][$cid] = ['mid'=>$mid, 'amount'=>$body['Amount'], 'phone'=>$body['PartyA'], 'cb'=>$body['CallBackURL'], 'ref'=>$body['AccountReference'], 'result'=>null];
    $save();
    $out(['MerchantRequestID'=>$mid, 'CheckoutRequestID'=>$cid, 'ResponseCode'=>'0', 'ResponseDescription'=>'Success. Request accepted for processing', 'CustomerMessage'=>'Success. Request accepted for processing']);
}
if ($path === '/mpesa/stkpushquery/v1/query') {
    $r = $S['reqs'][$body['CheckoutRequestID'] ?? ''] ?? null;
    if (!$r) $out(['requestId'=>'x', 'errorCode'=>'500.001.1001', 'errorMessage'=>'Merchant does not exist'], 500);
    if ($r['result'] === null) $out(['requestId'=>'x', 'errorCode'=>'500.001.1001', 'errorMessage'=>'The transaction is being processed'], 500);
    $out(['ResponseCode'=>'0', 'ResponseDescription'=>'The service request has been accepted successfully', 'MerchantRequestID'=>$r['mid'], 'CheckoutRequestID'=>$body['CheckoutRequestID'], 'ResultCode'=>(string)$r['result'], 'ResultDesc'=>$r['result'] == 0 ? 'The service request is processed successfully.' : 'Request cancelled by user']);
}
// ---- test controls (not part of Daraja) ----
if ($path === '/_mock/requests') $out($S['reqs']);
if ($path === '/_mock/complete') {          // ?id=CHECKOUT&code=0|1032|1037&amount=(optional)&receipt=(optional)&post=1 (post=0: only set the query result, send no callback)
    $cid = $_GET['id'] ?? ''; $r = $S['reqs'][$cid] ?? null; if (!$r) $out(['error'=>'unknown id'], 404);
    $code = (int)($_GET['code'] ?? 0); $S['reqs'][$cid]['result'] = $code; $save();
    $cb = ['MerchantRequestID'=>$r['mid'], 'CheckoutRequestID'=>$cid, 'ResultCode'=>$code, 'ResultDesc'=>$code === 0 ? 'The service request is processed successfully.' : ($code === 1032 ? 'Request cancelled by user' : 'DS timeout user cannot be reached')];
    if ($code === 0) $cb['CallbackMetadata'] = ['Item'=>[['Name'=>'Amount', 'Value'=>(float)($_GET['amount'] ?? $r['amount'])], ['Name'=>'MpesaReceiptNumber', 'Value'=>$_GET['receipt'] ?? 'TST'.strtoupper(substr(md5($cid), 0, 7))],
        ['Name'=>'TransactionDate', 'Value'=>(int)date('YmdHis')], ['Name'=>'PhoneNumber', 'Value'=>(int)$r['phone']]]];
    $resp = null;
    if (($_GET['post'] ?? '1') === '1') {
        $ch = curl_init($r['cb']); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>json_encode(['Body'=>['stkCallback'=>$cb]]), CURLOPT_TIMEOUT=>15]);
        $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $out(['posted_to'=>$r['cb'], 'http'=>$http, 'response'=>json_decode((string)$resp, true)]);
    }
    $out(['ok'=>true]);
}
$out(['error'=>'not found', 'path'=>$path], 404);
