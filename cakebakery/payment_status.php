<?php
// Polled by the order page every few seconds: returns the payment state as JSON. Only the order's owner (or an admin) may ask.
require_once 'includes/auth.php'; require_once 'services/Mpesa.php';
no_store_headers(); header('Content-Type: application/json');
if (!current_user() || !session_user_is_valid()) { http_response_code(401); echo json_encode(['error'=>'login']); exit; }
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT o.user_id, o.status order_status, p.* FROM orders o JOIN payments p USING(order_id) WHERE o.order_id=? AND o.deleted_at IS NULL'); $st->execute([$id]);
$p = $st->fetch();
if (!$p || ($p['user_id'] != current_user()['id'] && current_user()['role'] !== 'admin')) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
if ($p['method'] === 'mpesa' && $p['status'] === 'pending' && $p['checkout_request_id']) {
    $last = $_SESSION['qs'][$id] ?? 0;
    if (time() - $last >= 8) {            // ask Safaricom at most every 8 seconds
        $_SESSION['qs'][$id] = time();
        Mpesa::syncStatus($id);
        $st->execute([$id]); $p = $st->fetch();
    }
    // A prompt nobody answered for 5 minutes is treated as timed out (a late genuine success from Safaricom still wins).
    if ($p['status'] === 'pending' && $p['requested_at'] && time() - strtotime($p['requested_at']) > 300) {
        db()->prepare("UPDATE payments SET status='failed', result_code=1037, result_desc='Timed out' WHERE order_id=? AND status='pending'")->execute([$id]);
        $st->execute([$id]); $p = $st->fetch();
    }
}
[$state, $head, $detail] = PaymentService::describe($p);
echo json_encode(['state'=>$state, 'headline'=>$head, 'detail'=>$detail, 'receipt'=>$p['mpesa_receipt']]);
