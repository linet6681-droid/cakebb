<?php
// Safaricom POSTs the M-Pesa result (STK callback) here. This address must be PUBLIC and https (see README > "Daraja setup").
// Set it in config/secrets.php as MPESA_CALLBACK_URL, together with MPESA_CALLBACK_SECRET.
define('NO_SESSION', true);   // Safaricom's server has no browser session
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/services/PaymentService.php';
header('Content-Type: application/json');
$ack = static function (int $code = 0, string $desc = 'Accepted') { echo json_encode(['ResultCode'=>$code, 'ResultDesc'=>$desc]); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); $ack(1, 'POST only'); }
// Shared secret in the callback URL (?k=...): strangers who do not know it cannot post fake "payment successful" messages.
if (MPESA_CALLBACK_SECRET !== '' && !hash_equals(MPESA_CALLBACK_SECRET, (string)($_GET['k'] ?? ''))) { Mpesa_log('callback rejected: bad key', $_SERVER['REMOTE_ADDR'] ?? ''); http_response_code(403); $ack(1, 'Forbidden'); }
$raw = file_get_contents('php://input', false, null, 0, 65536);
Mpesa_log('callback', $raw);
$cb = json_decode((string)$raw, true)['Body']['stkCallback'] ?? null;
if (!is_array($cb) || empty($cb['CheckoutRequestID']) || !isset($cb['ResultCode'])) { http_response_code(400); $ack(1, 'Bad request'); }
try { PaymentService::handleCallback($cb, 'callback'); }
catch (Throwable $t) { error_log('M-Pesa callback error: '.$t->getMessage()); Mpesa_log('callback error', $t->getMessage()); http_response_code(500); $ack(1, 'Server error, please retry'); }
$ack();
