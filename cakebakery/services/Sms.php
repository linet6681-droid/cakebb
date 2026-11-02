<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

class Sms {
    /** Always logged to `notifications`; sent via Africa's Talking when SMS_ENABLED. Never throws. */
    public static function send(int $orderId, ?string $phone, string $message): void {
        if (!$phone) return;
        db()->prepare('INSERT INTO notifications(order_id,phone,message) VALUES(?,?,?)')->execute([$orderId,$phone,$message]);
        $nid = (int)db()->lastInsertId();
        if (!SMS_ENABLED) return;
        try {
            $host = SMS_USERNAME === 'sandbox' ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com';
            $ch = curl_init($host.'/version1/messaging');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>15,
                CURLOPT_HTTPHEADER=>['apiKey: '.SMS_API_KEY,'Accept: application/json'],
                CURLOPT_POSTFIELDS=>http_build_query(['username'=>SMS_USERNAME,'to'=>'+'.$phone,'message'=>$message])]);
            $res = json_decode((string)curl_exec($ch), true); curl_close($ch);
            $ok = ($res['SMSMessageData']['Recipients'][0]['status'] ?? '') === 'Success';
        } catch (Throwable $t) { $ok = false; }
        db()->prepare('UPDATE notifications SET delivery_status=? WHERE notification_id=?')->execute([$ok?'sent':'failed',$nid]);
    }
}
