<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/Mpesa.php';
require_once __DIR__ . '/Sms.php';

class OrderService {
    /** Message for the customer when the order was saved but the M-Pesa prompt failed. */
    public static string $notice = '';
    /** Validate, then (one transaction) lock stock rows, create order+items+payment, decrement stock. */
    public static function placeOrder(int $userId, array $cart, array $in): int {
        if (!$cart) throw new InvalidArgumentException('Your cart is empty.');
        $name = trim($in['name'] ?? '');
        $phone = normalize_phone($in['phone'] ?? '');
        $location = trim($in['location'] ?? '');
        $instr = mb_substr(trim($in['instructions'] ?? ''), 0, 255);
        $method = ($in['method'] ?? '') === 'cash_on_pickup' ? 'cash_on_pickup' : 'mpesa';
        $payPhone = null;   // the number that receives the M-Pesa prompt (may differ from the contact number)
        if ($method === 'mpesa') {
            $given = trim($in['mpesa_phone'] ?? '');
            $payPhone = $given === '' ? $phone : normalize_phone($given);
            if (!$payPhone) throw new InvalidArgumentException('Enter a valid M-Pesa phone number (e.g. 0712 345 678 or 0112 345 678).');
        }
        if (strlen($name) < 2) throw new InvalidArgumentException('Enter your full name.');
        if (!$phone) throw new InvalidArgumentException('Enter a valid Kenyan phone number (e.g. 0712 345 678).');
        $d = $in['date'] ?? ''; $tm = $in['time'] ?? '';
        $needed = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && preg_match('/^\d{2}:\d{2}$/', $tm)) ? strtotime("$d $tm") : false;
        if (!$needed) throw new InvalidArgumentException('Choose the date and time you need your order.');
        $h = (int)date('G', $needed);
        if ($h < 7 || $h >= 19) throw new InvalidArgumentException('We are open 7:00 AM – 7:00 PM. Choose a time within opening hours.');
        // Delivery fee depends on the AREA the customer picks (read from the database, never from the browser).
        $zoneId = (int)($in['zone'] ?? 0); $zone = null;
        if ($zoneId > 0 || $location !== '') {
            foreach (delivery_zones() as $z) if ((int)$z['zone_id'] === $zoneId) $zone = $z;
            if (!$zone) throw new InvalidArgumentException('Choose your delivery destination, or pick up at the shop.');
            if (mb_strlen($location) < 3) throw new InvalidArgumentException('Enter your street, estate or landmark for delivery.');
        }
        $fulfil = $zone ? 'delivery' : 'pickup';
        $destination = $zone ? $zone['name'] : null;
        $address = $zone ? mb_substr($location, 0, 250) : null;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $total = 0; $rows = []; $hasCake = false;
            foreach ($cart as $pid => $row) {
                $st = $pdo->prepare('SELECT * FROM products WHERE product_id=? AND is_active=1 FOR UPDATE');
                $st->execute([$pid]); $p = $st->fetch();
                if (!$p || $p['stock_qty'] < $row['qty']) throw new InvalidArgumentException('Not enough stock for '.($p['name'] ?? 'an item').'.');
                if ($p['category_id'] == 1) $hasCake = true;
                $total += $p['price'] * $row['qty'];
                $rows[] = [$p, $row];
            }
            $minLead = $hasCake ? 24*3600 : 3600;
            if ($needed < time() + $minLead) throw new InvalidArgumentException($hasCake ? 'Cakes must be ordered at least 24 hours ahead.' : 'Choose a time at least 1 hour from now.');
            $fee = $zone ? (float)$zone['fee'] : 0.0; $total += $fee;
            $pdo->prepare('INSERT INTO orders(user_id,date_needed,contact_name,contact_phone,fulfilment,destination,delivery_address,special_instructions,delivery_fee,total_amount) VALUES(?,?,?,?,?,?,?,?,?,?)')
                ->execute([$userId, date('Y-m-d H:i:s',$needed), $name, $phone, $fulfil, $destination, $address, $instr ?: null, $fee, $total]);
            $orderId = (int)$pdo->lastInsertId();
            foreach ($rows as [$p,$row]) {
                $pdo->prepare('INSERT INTO order_items(order_id,product_id,quantity,unit_price,cake_message) VALUES(?,?,?,?,?)')
                    ->execute([$orderId,$p['product_id'],$row['qty'],$p['price'],($p['category_id']==1 && $row['message']!=='') ? $row['message'] : null]);
                $pdo->prepare('UPDATE products SET stock_qty=stock_qty-? WHERE product_id=?')->execute([$row['qty'],$p['product_id']]);
            }
            $pdo->prepare('INSERT INTO payments(order_id,method,amount,payer_phone) VALUES(?,?,?,?)')->execute([$orderId,$method,$total,$payPhone]);
            $pdo->commit();
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }

        // After commit: side-effects that must never roll the order back
        Sms::send($orderId, $phone, "Cake & Bakery: order #$orderId received. Total ".money($total).($method==='mpesa' ? '. Enter your M-Pesa PIN when the prompt appears.' : '. Pay on pickup.'));
        self::$notice = '';
        if ($method === 'mpesa' && !Mpesa::stkPush($orderId, $payPhone, $total)) self::$notice = 'Order #'.$orderId.' saved, but the M-Pesa prompt could not be sent ('.Mpesa::$lastError.'). Open the order and press "Try M-Pesa again".';
        return $orderId;
    }

    /**
     * Admin status change: validates, restores stock on cancel, SMS the customer.
     * Returns a note for the admin (e.g. "refund needed") or ''.
     */
    public static function updateStatus(int $orderId, string $status): string {
        if (!array_key_exists($status, STATUS_LABELS)) throw new InvalidArgumentException('Invalid status.');
        $pdo = db(); $pdo->beginTransaction(); $note = '';
        try {
            $st = $pdo->prepare('SELECT status, contact_phone FROM orders WHERE order_id=? AND deleted_at IS NULL FOR UPDATE'); $st->execute([$orderId]);
            $o = $st->fetch(); if (!$o) throw new InvalidArgumentException('Order not found.');
            if ($o['status'] !== $status) {
                if ($status === 'cancelled') {
                    if ($o['status'] === 'delivered') throw new InvalidArgumentException('A delivered order cannot be cancelled. You can delete it instead.');
                    $pdo->prepare('UPDATE products SET stock_qty = stock_qty + (SELECT SUM(quantity) FROM order_items WHERE order_id=? AND order_items.product_id=products.product_id) WHERE product_id IN (SELECT product_id FROM order_items WHERE order_id=?)')->execute([$orderId,$orderId]);
                    // An unpaid prompt must not stay open on a cancelled order
                    $pdo->prepare("UPDATE payments SET status='failed', result_desc='Order cancelled' WHERE order_id=? AND status='pending'")->execute([$orderId]);
                    $pay = $pdo->prepare("SELECT status, method FROM payments WHERE order_id=?"); $pay->execute([$orderId]); $pp = $pay->fetch();
                    if ($pp && $pp['status'] === 'paid' && $pp['method'] === 'mpesa') $note = ' This order was already PAID by M-Pesa: refund the customer manually.';
                } elseif ($o['status'] === 'cancelled') {
                    throw new InvalidArgumentException('A cancelled order cannot be re-opened. Ask the customer to place a new order.');
                }
                $pdo->prepare('UPDATE orders SET status=? WHERE order_id=?')->execute([$status, $orderId]);
            }
            $pdo->commit();
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
        if ($o['status'] !== $status) {
            $msgs = ['in_progress'=>'is now being baked','ready'=>'is READY for pickup/delivery','delivered'=>'has been delivered. Thank you!','cancelled'=>'has been cancelled','new'=>'has been received'];
            Sms::send($orderId, $o['contact_phone'], "Cake & Bakery: your order #$orderId ".$msgs[$status].'.');
        }
        return $note;
    }

    /**
     * Admin "delete" of a DELIVERED order. The order disappears from the admin list and the customer's history, but the row,
     * its items and its payment record are KEPT (marked deleted_at) so sales totals and M-Pesa receipts stay available for accounting.
     */
    public static function deleteDelivered(int $orderId): void {
        $pdo = db(); $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT status FROM orders WHERE order_id=? AND deleted_at IS NULL FOR UPDATE'); $st->execute([$orderId]);
            $s = $st->fetchColumn(); if ($s === false) throw new InvalidArgumentException('Order not found.');
            if ($s !== 'delivered') throw new InvalidArgumentException('Only delivered orders can be deleted. Cancel the order instead.');
            $pdo->prepare("UPDATE orders SET deleted_at=? WHERE order_id=? AND status='delivered'")->execute([date('Y-m-d H:i:s'), $orderId]);
            $pdo->commit();
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
    }
}
