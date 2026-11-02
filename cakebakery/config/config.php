<?php
// Central settings. SECRETS (database password, M-Pesa keys) belong in config/secrets.php, which is NOT shared or committed.
// Copy config/secrets.example.php to config/secrets.php and fill it in. Anything defined there overrides the defaults below.
if (is_file(__DIR__ . '/secrets.php')) require_once __DIR__ . '/secrets.php';
/** Read a setting: environment variable first (hosting panels), else the default. */
function cfg_env(string $name, $default = '') { $v = getenv($name); return ($v !== false && $v !== '') ? $v : $default; }
function cfg_define(string $name, $default): void { if (!defined($name)) define($name, cfg_env($name, $default)); }

cfg_define('DB_HOST', 'localhost');
cfg_define('DB_NAME', 'cakeb');
cfg_define('DB_USER', 'root');
cfg_define('DB_PASS', '');
cfg_define('BASE_URL', '/cakebakery');
define('DEBUG', false);          // errors are always logged to logs/php_errors.log, never shown to visitors
// Delivery fees are set per destination in Admin > Delivery (table delivery_zones). The server always reads the fee from there.
define('MAX_FAILED_LOGINS', 3);
define('LOCK_MINUTES', 15);

// ---- M-Pesa via the Safaricom Daraja API ----
// MPESA_MODE: 'sandbox' = Safaricom's test environment (default; safe, no real money)
//             'live'    = real money, only after Safaricom approves your Paybill/Till (Go-Live) and you use PRODUCTION keys
// There is no "fake success" mode: an order is marked paid ONLY when Safaricom confirms it.
cfg_define('MPESA_MODE', 'sandbox');
cfg_define('MPESA_CONSUMER_KEY', '');      // Daraja > My Apps > your app > Consumer Key      (put it in config/secrets.php)
cfg_define('MPESA_CONSUMER_SECRET', '');   // Daraja > My Apps > your app > Consumer Secret   (put it in config/secrets.php)
cfg_define('MPESA_SHORTCODE', '174379');   // sandbox test shortcode. Live: YOUR Paybill or Till number
cfg_define('MPESA_TXN_TYPE', 'CustomerPayBillOnline'); // Till number? use 'CustomerBuyGoodsOnline'
cfg_define('MPESA_PASSKEY', '');           // Lipa na M-Pesa Online passkey (sandbox: copy it from Daraja > APIs > M-Pesa Express > Simulate)
// PUBLIC https address Safaricom calls with the result, e.g. https://yourdomain.co.ke/cakebakery/pay_callback.php  (use ngrok/Cloudflare Tunnel on a laptop)
cfg_define('MPESA_CALLBACK_URL', '');
// Long random text. It is added to the callback URL (?k=...) and checked on arrival so strangers cannot post fake results.
cfg_define('MPESA_CALLBACK_SECRET', '');
cfg_define('MPESA_BASE_URL', '');          // leave empty. Only for tests against a local mock of Daraja.

// Phone that also gets an SMS whenever a customer pays or cancels (optional, e.g. '0712345678'). Alerts always show on the admin dashboard.
cfg_define('ADMIN_PHONE', '');

// SMS (Africa's Talking)
cfg_define('SMS_USERNAME', 'sandbox');
cfg_define('SMS_API_KEY', '');
define('SMS_ENABLED', cfg_env('SMS_ENABLED', '') === '1' || (defined('SMS_ENABLED_SETTING') && SMS_ENABLED_SETTING));
