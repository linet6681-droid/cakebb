<?php
require_once __DIR__ . '/config.php';
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // MySQL error 1049 = database does not exist yet -> send the owner to the one-time installer.
            if (PHP_SAPI !== 'cli' && (($e->errorInfo[1] ?? 0) == 1049) && is_file(__DIR__.'/../install.php') && !headers_sent()) {
                header('Location: '.BASE_URL.'/install.php'); exit;
            }
            throw $e;
        }
        require_once __DIR__ . '/../includes/migrate.php';
        ensure_schema($pdo);   // adds any columns/delivery destinations an older database is missing
    }
    return $pdo;
}
