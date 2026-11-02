<?php
require_once __DIR__ . '/../includes/helpers.php';

class AdminService {
    /**
     * Delete a customer account.
     *  - No orders at all  -> the account row is removed completely.
     *  - Has orders        -> the account is ERASED (name, email, phone, password) but the order, item and payment rows are kept,
     *                         because sales and M-Pesa records must be retained for accounting. Personal data on those orders is blanked.
     * Customers with unfinished orders (new / baking / ready) cannot be deleted: finish or cancel those first.
     * Returns a message describing what happened.
     */
    public static function deleteCustomer(int $userId): string {
        $pdo = db(); $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT user_id, role, deleted_at FROM users WHERE user_id=? FOR UPDATE'); $st->execute([$userId]);
            $u = $st->fetch();
            if (!$u || $u['deleted_at']) throw new InvalidArgumentException('Customer not found.');
            if ($u['role'] !== 'customer') throw new InvalidArgumentException('Only customer accounts can be deleted here.');
            $open = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND status IN ('new','in_progress','ready')"); $open->execute([$userId]);
            if ((int)$open->fetchColumn() > 0) throw new InvalidArgumentException('This customer still has unfinished orders. Mark them delivered or cancel them first.');
            $all = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id=?'); $all->execute([$userId]);
            if ((int)$all->fetchColumn() === 0) {
                $pdo->prepare('DELETE FROM users WHERE user_id=?')->execute([$userId]);
                $pdo->commit();
                return 'Customer deleted.';
            }
            $ids = "SELECT order_id FROM orders WHERE user_id=?";
            $pdo->prepare("DELETE FROM notifications WHERE order_id IN ($ids)")->execute([$userId]);                 // SMS log: personal, not financial
            $pdo->prepare("UPDATE payments SET payer_phone=NULL WHERE order_id IN ($ids)")->execute([$userId]);
            $pdo->prepare("UPDATE orders SET contact_name='Deleted customer', contact_phone='', delivery_address=NULL, special_instructions=NULL WHERE user_id=?")->execute([$userId]);
            $pdo->prepare("UPDATE users SET full_name='Deleted customer', email=?, phone='', password_hash='!', failed_attempts=0, locked_until=NULL, deleted_at=? WHERE user_id=?")
                ->execute(['deleted-'.$userId.'@removed.invalid', date('Y-m-d H:i:s'), $userId]);
            $pdo->commit();
            return 'Customer deleted. Their personal details were erased; their orders and payment records were kept for accounting.';
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
    }

    public static function unlockCustomer(int $userId): void {
        db()->prepare("UPDATE users SET failed_attempts=0, locked_until=NULL WHERE user_id=? AND role='customer'")->execute([$userId]);
    }
}
