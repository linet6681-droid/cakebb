<?php
// Shared by orders.php and order_view.php. Every action: admin only + CSRF token + server-side rules in OrderService.
require_once __DIR__.'/../services/OrderService.php';
function handle_order_post(string $back): void {
    require_admin(); csrf_check();
    $oid = (int)($_POST['order_id'] ?? 0); $act = $_POST['action'] ?? '';
    try {
        if ($act === 'status')      { $n = OrderService::updateStatus($oid, $_POST['status'] ?? ''); flash("Order #$oid status updated.".$n); }
        elseif ($act === 'cancel')  { $n = OrderService::updateStatus($oid, 'cancelled'); flash("Order #$oid cancelled. Stock was returned to the shop.".$n); }
        elseif ($act === 'delete')  { OrderService::deleteDelivered($oid); flash("Delivered order #$oid deleted."); $back = 'admin/orders.php'; }
        elseif ($act === 'sms') {
            $msg = trim($_POST['message'] ?? ''); if ($msg === '') throw new InvalidArgumentException('Write a message first.');
            $st = db()->prepare('SELECT contact_phone FROM orders WHERE order_id=? AND deleted_at IS NULL'); $st->execute([$oid]);
            $ph = $st->fetchColumn(); if (!$ph) throw new InvalidArgumentException('This order has no phone number to message.');
            Sms::send($oid, $ph, mb_substr($msg, 0, 300)); flash("SMS sent to the customer for order #$oid.");
        }
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage()); }
    redirect($back);
}
