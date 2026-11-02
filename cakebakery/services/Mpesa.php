<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/PaymentService.php';

/**
 * M-Pesa Express (STK Push) through the official Safaricom Daraja API.
 *   sandbox : https://sandbox.safaricom.co.ke  (testing, no real money)
 *   live    : https://api.safaricom.co.ke      (real money, after Safaricom "Go Live")
 * An order is NEVER marked paid here. Only PaymentService::handleCallback() does that, from Safaricom's own result.
 */
class Mpesa {
    public static string $lastError = '';
    /** true when stkPush() did not send a new prompt because one was sent a moment ago (double click / refresh). */
    public static bool $duplicate = false;
    private const SANDBOX_PASSKEY = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919'; // Safaricom's PUBLIC sandbox test passkey

    public static function mode(): string { return MPESA_MODE === 'live' ? 'live' : 'sandbox'; }
    private static function base(): string {
        if (MPESA_BASE_URL !== '') return rtrim(MPESA_BASE_URL, '/');   // tests only (local mock of Daraja)
        return self::mode() === 'live' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    }
    private static function passkey(): string {
        if (MPESA_PASSKEY !== '') return MPESA_PASSKEY;
        return (self::mode() === 'sandbox' && MPESA_SHORTCODE === '174379') ? self::SANDBOX_PASSKEY : '';
    }
    public static function log(string $what, $data): void {
        $txt = is_string($data) ? $data : json_encode($data);
        $txt = preg_replace('/"(Password|access_token)":"[^"]*"/', '"$1":"***"', (string)$txt);   // never write secrets to the log
        @file_put_contents(__DIR__.'/../logs/mpesa.log', date('c')." $what $txt\n", FILE_APPEND);
    }
           public static function callbackBase(): string {
           $u = trim(MPESA_CALLBACK_URL);
           return $u !== '' ? $u : 'https://example.com/cakebakery/pay_callback.php';
       }
    /** The URL Safaricom posts the result to (with our secret key appended). */
    public static function callbackUrl(): string {
                  $u = self::callbackBase(); if ($u === '') return '';
        return MPESA_CALLBACK_SECRET !== '' ? $u.(str_contains($u, '?') ? '&' : '?').'k='.rawurlencode(MPESA_CALLBACK_SECRET) : $u;
    }

    private static function http(string $method, string $url, array $headers, ?string $body = null, ?string $userpwd = null): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>25, CURLOPT_HTTPHEADER=>$headers, CURLOPT_SSL_VERIFYPEER=>true]);
        if ($userpwd) curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $raw = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false) {
            // Most common XAMPP problem: PHP has no CA certificate list.
            $hint = stripos($err, 'SSL certificate') !== false ? ' (XAMPP fix: download cacert.pem from curl.se/ca/cacert.pem, set curl.cainfo in php.ini, restart Apache)' : '';
            self::log('network-error', $err);   // technical detail goes to the log, not to the customer
            return [0, null, 'Cannot reach Safaricom right now. Please try again in a moment.'.$hint];
        }
        return [$code, json_decode($raw, true), $raw];
    }

    /** Returns an error text when the server is not ready to send STK pushes, else ''. */
    public static function configProblem(): string {
        if (MPESA_CONSUMER_KEY === '' || MPESA_CONSUMER_SECRET === '') return 'M-Pesa is not set up yet (Daraja Consumer Key/Secret missing in config/secrets.php)';
        if (self::passkey() === '') return 'M-Pesa is not set up yet (Passkey missing in config/secrets.php)';
                 $cb = self::callbackBase();
        if ($cb === '' || (MPESA_BASE_URL === '' && !preg_match('#^https://#i', $cb))) return 'M-Pesa is not set up yet (MPESA_CALLBACK_URL must be a public https address)';
        return '';
    }

    public static function token(): ?string {
        $cache = __DIR__.'/../logs/.mpesa_token_'.substr(sha1(self::base().MPESA_CONSUMER_KEY), 0, 12);
        if (is_file($cache) && ($c = json_decode((string)@file_get_contents($cache), true)) && ($c['exp'] ?? 0) > time() + 60) return $c['t'];
        [$code, $json, $raw] = self::http('GET', self::base().'/oauth/v1/generate?grant_type=client_credentials', ['Accept: application/json'], null, MPESA_CONSUMER_KEY.':'.MPESA_CONSUMER_SECRET);
        if (!empty($json['access_token'])) {
            @file_put_contents($cache, json_encode(['t'=>$json['access_token'], 'exp'=>time() + max(60, (int)($json['expires_in'] ?? 3500) - 120)])); @chmod($cache, 0600);
            return $json['access_token'];
        }
        self::$lastError = $code === 0 ? $raw : (in_array($code, [400, 401], true) ? 'Safaricom rejected the Consumer Key / Secret (check you copied the keys of the right Daraja app and environment)' : "Safaricom sign-in error (HTTP $code)");
        self::log('token-fail', [$code, $raw]);
        return null;
    }

    private static function stamp(): array { $ts = date('YmdHis'); return [$ts, base64_encode(MPESA_SHORTCODE.self::passkey().$ts)]; }

    /**
     * Send the payment prompt to the customer's phone. The AMOUNT always comes from the order in the database
     * (product subtotal + delivery fee), never from the browser. Returns true when Safaricom accepted the request.
     */
    public static function stkPush(int $orderId, string $phone, float $unused = 0): bool {
        self::$lastError = ''; self::$duplicate = false;
        $st = db()->prepare('SELECT o.total_amount, o.status, p.payment_id, p.status pay_status, p.method, p.checkout_request_id, p.requested_at FROM orders o JOIN payments p USING(order_id) WHERE o.order_id=?');
        $st->execute([$orderId]); $o = $st->fetch();
        if (!$o || $o['method'] !== 'mpesa') { self::$lastError = 'This order is not an M-Pesa order'; return false; }
        if ($o['pay_status'] === 'paid') { self::$lastError = 'This order is already paid'; return false; }
        if ($o['status'] === 'cancelled') { self::$lastError = 'This order was cancelled'; return false; }
        if ($o['pay_status'] === 'pending' && $o['checkout_request_id'] && $o['requested_at'] && strtotime($o['requested_at']) > time() - 60) { self::$duplicate = true; return true; }   // prompt already sent <60s ago
        $amount = (int)ceil((float)$o['total_amount']);
        if ($amount < 1) { self::$lastError = 'Invalid order amount'; return false; }
        if (!preg_match('/^254[17]\d{8}$/', $phone)) return self::fail($orderId, 'That is not a valid Safaricom number');
        if ($p = self::configProblem()) return self::fail($orderId, $p);
        $token = self::token(); if (!$token) return self::fail($orderId, self::$lastError);
        [$ts, $pw] = self::stamp();
        $payload = ['BusinessShortCode'=>MPESA_SHORTCODE, 'Password'=>$pw, 'Timestamp'=>$ts, 'TransactionType'=>MPESA_TXN_TYPE, 'Amount'=>$amount,
            'PartyA'=>$phone, 'PartyB'=>MPESA_SHORTCODE, 'PhoneNumber'=>$phone, 'CallBackURL'=>self::callbackUrl(),
            'AccountReference'=>'ORDER'.$orderId, 'TransactionDesc'=>'Cake Bakery'];   // Daraja limits: reference 12 chars, description 13 chars
        [$code, $json, $raw] = self::http('POST', self::base().'/mpesa/stkpush/v1/processrequest', ['Content-Type: application/json', 'Authorization: Bearer '.$token], json_encode($payload));
        self::log("stkpush order=$orderId amount=$amount phone=".substr($phone, 0, 6).'xxxxxx', [$code, $raw]);
        if ($code === 0) return self::fail($orderId, (string)$raw);
        if ($code === 401) { @array_map('unlink', glob(__DIR__.'/../logs/.mpesa_token_*') ?: []); }   // stale token: next try signs in again
        if (($json['ResponseCode'] ?? '') === '0' && !empty($json['CheckoutRequestID'])) {
            db()->prepare("UPDATE payments SET status='pending', amount=?, payer_phone=?, checkout_request_id=?, merchant_request_id=?, requested_at=?, result_code=NULL, result_desc=? WHERE payment_id=? AND status<>'paid'")
                ->execute([$o['total_amount'], $phone, $json['CheckoutRequestID'], $json['MerchantRequestID'] ?? null, date('Y-m-d H:i:s'), $json['CustomerMessage'] ?? 'Request accepted', $o['payment_id']]);
            return true;
        }
        return self::fail($orderId, (string)($json['errorMessage'] ?? $json['ResponseDescription'] ?? "Safaricom error (HTTP $code)"));
    }
    private static function fail(int $orderId, string $why): bool {
        self::$lastError = $why;
        db()->prepare("UPDATE payments SET status='failed', result_code=NULL, result_desc=? WHERE order_id=? AND status<>'paid'")->execute([mb_substr($why, 0, 250), $orderId]);
        return false;
    }

    /** Ask Safaricom what happened to a pending prompt (STK Query). Works even if the callback URL is not reachable. */
    public static function syncStatus(int $orderId): void {
        $st = db()->prepare("SELECT checkout_request_id, requested_at FROM payments WHERE order_id=? AND status='pending' AND method='mpesa'"); $st->execute([$orderId]);
        $r = $st->fetch(); $cid = (string)($r['checkout_request_id'] ?? ''); if ($cid === '') return;
        if (self::configProblem() || !($token = self::token())) return;
        [$ts, $pw] = self::stamp();
        [$code, $json, $raw] = self::http('POST', self::base().'/mpesa/stkpushquery/v1/query', ['Content-Type: application/json', 'Authorization: Bearer '.$token],
            json_encode(['BusinessShortCode'=>MPESA_SHORTCODE, 'Password'=>$pw, 'Timestamp'=>$ts, 'CheckoutRequestID'=>$cid]));
        self::log("stkquery order=$orderId", [$code, $raw]);
              // 4999 / errorCode 500.001.1001 = "still being processed": leave the payment pending
        if (isset($json['ResultCode']) && ($json['ResponseCode'] ?? '0') === '0' && !in_array((string)$json['ResultCode'], ['4999', '1037x'], true) && stripos((string)($json['ResultDesc'] ?? ''), 'still under processing') === false)
            PaymentService::handleCallback(['CheckoutRequestID'=>$cid, 'MerchantRequestID'=>$json['MerchantRequestID'] ?? null, 'ResultCode'=>(int)$json['ResultCode'], 'ResultDesc'=>$json['ResultDesc'] ?? ''], 'query');
    }

    /** For the admin "Test M-Pesa connection" button. */
    public static function testConnection(): array {
        self::$lastError = '';
        if (MPESA_CONSUMER_KEY === '' || MPESA_CONSUMER_SECRET === '') return [false, 'Consumer Key / Secret are not set. Put them in config/secrets.php (see README).'];
        $t = self::token();
        return $t ? [true, 'Connected to Safaricom Daraja ('.self::mode().'). The Consumer Key and Secret are valid.'] : [false, self::$lastError ?: 'Could not get an access token.'];
    }
}
