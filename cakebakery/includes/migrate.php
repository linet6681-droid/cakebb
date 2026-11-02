<?php
/**
 * Brings an EXISTING database up to date without losing data (new installs get the same shape from sql/schema.sql).
 * Runs automatically, once per request, and costs one tiny query when nothing needs changing.
 * Existing orders are never touched, so they keep the delivery fee they were placed with.
 */
const DELIVERY_DESTINATIONS = [   // exact fees required by the shop owner
    ['Within CBD', 0], ['Between Allsops and Juja', 100], ['Thika', 150], ["Murang'a", 250],
    ['Nyeri', 350], ['Nakuru', 400], ['Mombasa', 500],
];
/** The five sample areas from the first version. They are removed when the real destinations are installed. */
const OLD_SAMPLE_ZONES = ['Machakos Town (CBD)', 'Machakos Town (estates & outskirts)', 'Athi River / Mlolongo', 'Syokimau / Kitengela', 'Nairobi'];

function ensure_schema(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    $col = function (string $table, string $name, string $ddl) use ($pdo): void {
        try { $pdo->query("SELECT $name FROM $table LIMIT 0"); }
        catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) == 1146 || stripos($e->getMessage(), 'no such table') !== false) throw $e;   // table missing: installer handles it
            $pdo->exec("ALTER TABLE $table ADD COLUMN $name $ddl");
        }
    };
    try { $pdo->query('SELECT 1 FROM orders LIMIT 0'); } catch (PDOException $e) { return; }   // not installed yet
    $col('orders',   'destination',         'VARCHAR(80) NULL');
    $col('orders',   'deleted_at',          'DATETIME NULL');
    $col('users',    'deleted_at',          'DATETIME NULL');
    $col('payments', 'payer_phone',         'VARCHAR(15) NULL');
    $col('payments', 'paid_amount',         'DECIMAL(10,2) NULL');
    $col('payments', 'merchant_request_id', 'VARCHAR(100) NULL');
    $col('payments', 'result_code',         'INT NULL');
    $col('payments', 'result_desc',         'VARCHAR(255) NULL');
    $col('payments', 'requested_at',        'DATETIME NULL');
    install_delivery_destinations($pdo);
}

function install_delivery_destinations(PDO $pdo): void {
    try { $pdo->query('SELECT 1 FROM delivery_zones LIMIT 0'); }
    catch (PDOException $e) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS delivery_zones (zone_id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE, fee DECIMAL(10,2) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB');
    }
    $has = $pdo->prepare('SELECT COUNT(*) FROM delivery_zones WHERE name=?'); $has->execute([DELIVERY_DESTINATIONS[0][0]]);
    if ((int)$has->fetchColumn() > 0) return;          // already installed (the admin may have edited fees since)
    $del = $pdo->prepare('DELETE FROM delivery_zones WHERE name=?');
    foreach (OLD_SAMPLE_ZONES as $n) $del->execute([$n]);
    $ins = $pdo->prepare('INSERT INTO delivery_zones(name,fee,is_active) VALUES(?,?,1)');
    foreach (DELIVERY_DESTINATIONS as [$n, $f]) {
        $ex = $pdo->prepare('SELECT zone_id FROM delivery_zones WHERE name=?'); $ex->execute([$n]);
        if ($ex->fetchColumn()) $pdo->prepare('UPDATE delivery_zones SET fee=?, is_active=1 WHERE name=?')->execute([$f, $n]);
        else $ins->execute([$n, $f]);
    }
}
