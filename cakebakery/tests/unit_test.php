<?php
// Run: php tests/unit_test.php
chdir(__DIR__.'/..');
require 'includes/helpers.php';
$pass = $fail = 0;
function check(string $name, $got, $want): void { global $pass,$fail;
    if ($got === $want) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name  (got ".var_export($got,true).", want ".var_export($want,true).")\n"; } }

check('phone 0712345678',      normalize_phone('0712345678'),   '254712345678');
check('phone with spaces',     normalize_phone('0712 345 678'), '254712345678');
check('phone 254 format',      normalize_phone('254712345678'), '254712345678');
check('phone 01xx (Airtel)',   normalize_phone('0112345678'),   '254112345678');
check('phone too short',       normalize_phone('07123'),        null);
check('phone letters',         normalize_phone('abc'),          null);
check('phone wrong prefix',    normalize_phone('0912345678'),   null);
check('display phone',         display_phone('254712345678'),   '0712 345 678');
check('money format',          money(1800),                     'Ksh 1,800');
check('escape XSS',            e('<script>alert(1)</script>'),  '&lt;script&gt;alert(1)&lt;/script&gt;');
check('product title pack',    product_title(['name'=>'Vanilla Cupcakes','pack_size'=>6]), 'Vanilla Cupcakes (6)');
check('product title plain',   product_title(['name'=>'White Bread','pack_size'=>null]),  'White Bread');
check('status label',          STATUS_LABELS['in_progress'],    'Baking');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
