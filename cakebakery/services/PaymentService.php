<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/Sms.php';

class PaymentService {
    /**
     * Apply a payment RESULT from Safaricom (callback POST, or STK Query answer).
     *  - Unknown CheckoutRequestID, or a MerchantRequestID that does not match ours  -> ignored.
     *  - ResultCode 0 (success)  -> paid, but only if a receipt number is present and the amount equals the order total (callbacks).
     *  - any other ResultCode    -> failed (cancelled by customer, wrong PIN, no funds, timeout ...).
     *  - Idempotent: a repeated callback changes nothing and sends nothing twice. A paid payment is never overwritten by a failure.
     */
    public static function handleCallback(array $cb, string $source = 'callback'): void {
        $checkoutId = (string)($cb['CheckoutRequestID'] ?? '');
        if ($checkoutId === '' || !isset($cb['ResultCode']) || !is_numeric($cb['ResultCode'])) return;
                // Safaricom code 4999 / "still under processing" is NOT a result: keep the payment pending and wait.
        if ((int)$cb['ResultCode'] === 4999 || stripos((string)($cb['ResultDesc'] ?? ''), 'still under processing') !== false) return;
        $st = db()->prepare('SELECT p.*, o.status AS order_status, o.contact_phone FROM payments p JOIN orders o USING(order_id) WHERE p.checkout_request_id=?'); $st->execute([$checkoutId]);
        $p = $st->fetch();
        if (!$p) { Mpesa_log('unknown CheckoutRequestID ignored', $checkoutId); return; }
        $orderId = (int)$p['order_id'];
        $mid = (string)($cb['MerchantRequestID'] ?? '');
        if ($mid !== '' && !empty($p['merchant_request_id']) && !hash_equals((string)$p['merchant_request_id'], $mid)) { Mpesa_log('MerchantRequestID mismatch ignored', [$orderId, $mid]); return; }
        $code = (int)$cb['ResultCode']; $desc = mb_substr((string)($cb['ResultDesc'] ?? ''), 0, 250);

        if ($code === 0) {
            $receipt = null; $paidAmount = null; $payerPhone = null;
            foreach ($cb['CallbackMetadata']['Item'] ?? [] as $i) {
                if (($i['Name'] ?? '') === 'MpesaReceiptNumber') $receipt = substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$i['Value']), 0, 30);
                if (($i['Name'] ?? '') === 'Amount' && is_numeric($i['Value'] ?? null)) $paidAmount = (float)$i['Value'];
                if (($i['Name'] ?? '') === 'PhoneNumber') $payerPhone = substr(preg_replace('/\D/', '', (string)$i['Value']), 0, 15);
            }
            if ($source === 'callback') {
                if (!$receipt || $paidAmount === null) { Mpesa_log('success callback without receipt/amount ignored', $orderId); return; }
                if (abs($paidAmount - ceil((float)$p['amount'])) > 0.009) {   // amount must equal the order total (whole shillings)
                    Mpesa_log('AMOUNT MISMATCH', [$orderId, $paidAmount, $p['amount']]);
                    self::alert($orderId, 'PAYMENT ALERT: REVIEW needed. Order #'.$orderId.' expected Ksh '.number_format((float)$p['amount']).' but the M-Pesa result says Ksh '.number_format($paidAmount).'. It was NOT marked paid.');
                    return;
                }
            }
            $n = db()->prepare("UPDATE payments SET status='paid', mpesa_receipt=?, paid_amount=?, payer_phone=COALESCE(?, payer_phone), result_code=0, result_desc=?, paid_at=? WHERE checkout_request_id=? AND status<>'paid'");
            $n->execute([$receipt, $paidAmount, $payerPhone ?: null, $desc ?: 'Success', date('Y-m-d H:i:s'), $checkoutId]);
            if ($n->rowCount()) {            // first time only
                Sms::send($orderId, $p['contact_phone'], "Cake & Bakery: payment received for order #$orderId.".($receipt ? " M-Pesa ref $receipt." : '')." Thank you!");
                self::alertAdmin($orderId, 'paid', $receipt, $paidAmount, $payerPhone, $p['order_status'] === 'cancelled' ? ' The order was CANCELLED: refund the customer.' : '');
            } elseif ($receipt && empty($p['mpesa_receipt'])) {   // paid via STK Query earlier; the callback now supplies the receipt
                db()->prepare('UPDATE payments SET mpesa_receipt=?, paid_amount=? WHERE checkout_request_id=?')->execute([$receipt, $paidAmount, $checkoutId]);
            }
        } else {
            $n = db()->prepare("UPDATE payments SET status='failed', result_code=?, result_desc=? WHERE checkout_request_id=? AND status='pending'");
            $n->execute([$code, $desc, $checkoutId]);
            if ($n->rowCount()) self::alertAdmin($orderId, 'failed');
        }
    }

    /** Customer-friendly wording for a payment row: [state, headline, detail]. */
    public static function describe(array $p): array {
        $phone = display_phone((string)($p['payer_phone'] ?? ''));
        switch ($p['status'] ?? 'pending') {
            case 'paid': return ['paid', 'Payment successful', 'We received '.money($p['paid_amount'] ?? $p['amount']).($p['mpesa_receipt'] ? '. M-Pesa receipt: '.$p['mpesa_receipt'] : '').'.'];
            case 'failed':
                $c = $p['result_code'] ?? null; $d = (string)($p['result_desc'] ?? '');
                $map = [1032 => ['Payment cancelled', 'You cancelled the request on your phone. No money was taken.'],
                        1037 => ['Payment timed out', 'The prompt expired before a PIN was entered. No money was taken.'],
                        1    => ['Payment unsuccessful', 'Your M-Pesa balance is too low for this payment.'],
                        2001 => ['Payment unsuccessful', 'The M-Pesa PIN entered was wrong.'],
                        1019 => ['Payment unsuccessful', 'The transaction expired. Please try again.'],
                        1025 => ['Payment unsuccessful', 'The prompt could not be delivered to your phone. Please try again.'],
                        1001 => ['Payment unsuccessful', 'Another M-Pesa transaction is in progress on your phone. Finish it, then try again.']];
                if ($c !== null && isset($map[(int)$c])) return ['failed', $map[(int)$c][0], $map[(int)$c][1]];
                if ($d === 'Order cancelled') return ['failed', 'Order cancelled', 'This order was cancelled, so no payment is needed.'];
                return ['failed', 'Payment unsuccessful', $d !== '' ? $d.'.' : 'The payment did not go through. You can try again.'];
            default:
                $age = !empty($p['requested_at']) ? time() - strtotime($p['requested_at']) : 999;
                return ['pending', $age < 25 ? 'Request submitted' : 'Waiting for your PIN', 'A payment prompt for '.money($p['amount']).' was sent to '.$phone.'. Enter your M-Pesa PIN on your phone to approve it. This page updates by itself.'];
        }
    }

    /** The message the admin sees after the customer enters their M-Pesa PIN (dashboard + optional SMS to ADMIN_PHONE). */
    public static function alertAdmin(int $orderId, string $result, ?string $receipt = null, $amount = null, ?string $payerPhone = null, string $extra = ''): void {
        $st = db()->prepare('SELECT o.contact_name, o.contact_phone, p.amount FROM orders o JOIN payments p USING(order_id) WHERE o.order_id=?'); $st->execute([$orderId]);
        $o = $st->fetch(); if (!$o) return;
        $amount = $amount ?? $o['amount']; $who = $o['contact_name'].' ('.($payerPhone ?: $o['contact_phone']).')';
        $msg = $result === 'paid'
            ? 'PAYMENT ALERT: '.($receipt ?: 'M-Pesa').' Confirmed. Ksh '.number_format((float)$amount).' received from '.$who.' for order #'.$orderId.'.'.$extra
            : 'PAYMENT ALERT: '.$who.' did not complete the M-Pesa payment of Ksh '.number_format((float)$amount).' for order #'.$orderId.' (cancelled, timed out or failed).';
        self::alert($orderId, $msg);
    }
    private static function alert(int $orderId, string $msg): void {
        $msg = mb_substr($msg, 0, 315);
        if (defined('ADMIN_PHONE') && normalize_phone((string)ADMIN_PHONE)) Sms::send($orderId, normalize_phone((string)ADMIN_PHONE), $msg);
        else db()->prepare("INSERT INTO notifications(order_id,phone,message,delivery_status) VALUES(?,?,?,'sent')")->execute([$orderId, 'ADMIN', $msg]);
    }
}
/** Log helper that works even when Mpesa.php has not been loaded (callback endpoint). */
function Mpesa_log(string $what, $data): void {
    $txt = is_string($data) ? $data : json_encode($data);
    @file_put_contents(__DIR__.'/../logs/mpesa.log', date('c')." $what $txt\n", FILE_APPEND);
}
