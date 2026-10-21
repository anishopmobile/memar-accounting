<?php
declare(strict_types=1);
require_once __DIR__ . '/../payment/payment_currency.php';
$pass = 0;
function ck($a, $b): void {
    global $pass;
    $pass++;
    if ($a !== $b) throw new RuntimeException("assertion $pass: " . var_export($a, true) . " != " . var_export($b, true));
}
function bad(callable $fn): void {
    global $pass;
    $pass++;
    try { $fn(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException("assertion $pass: invalid value accepted");
}
ck(PaymentDisplayCurrency::fromRial(100000, 'TOMAN'), '10000');
ck(PaymentDisplayCurrency::fromRial(100000, 'IRR'), '100000');
ck(PaymentDisplayCurrency::formatRial(100000, 'TOMAN'), '10,000 تومان');
ck(PaymentDisplayCurrency::formatRial(100000, 'IRR'), '100,000 ریال');
ck(PaymentDisplayCurrency::fromRial(1251, 'TOMAN'), '125.1');
ck(PaymentDisplayCurrency::formatRial(1251, 'TOMAN'), '125.1 تومان');
ck(PaymentDisplayCurrency::fromRial(0, 'TOMAN'), '0');
ck(PaymentDisplayCurrency::fromRial(123456789, 'TOMAN'), '12345678.9');
ck(PaymentDisplayCurrency::toRial('10000', 'TOMAN'), 100000);
ck(PaymentDisplayCurrency::toRial('100,000', 'IRR'), 100000);
ck(PaymentDisplayCurrency::toRial('۱۰٬۰۰۰', 'TOMAN'), 100000);
ck(PaymentDisplayCurrency::toRial('۱۲۵٫۱', 'TOMAN'), 1251);
ck(PaymentDisplayCurrency::toRial('0.1', 'TOMAN'), 1);
ck(PaymentDisplayCurrency::toRial('', 'TOMAN'), 0);
ck(PaymentDisplayCurrency::toRial('0', 'IRR'), 0);
ck(PaymentDisplayCurrency::toRial('999999999999999', 'IRR'), 999999999999999);
ck(PaymentDisplayCurrency::toRial('99999999999999.9', 'TOMAN'), 999999999999999);
ck(PaymentMoney::toGatewayToman(100000), 10000); // gateway contract UNCHANGED
bad(fn()=>PaymentDisplayCurrency::toRial('0.01', 'TOMAN'));
bad(fn()=>PaymentDisplayCurrency::toRial('99.1', 'IRR'));
bad(fn()=>PaymentDisplayCurrency::toRial('-100', 'TOMAN'));
bad(fn()=>PaymentDisplayCurrency::toRial('1e5', 'TOMAN'));
bad(fn()=>PaymentDisplayCurrency::toRial('1,00', 'IRR'));
bad(fn()=>PaymentDisplayCurrency::toRial('1000000000000000', 'IRR'));
bad(fn()=>PaymentDisplayCurrency::toRial('100000000000000', 'TOMAN'));
bad(fn()=>PaymentDisplayCurrency::toRial(10.5, 'TOMAN'));
bad(fn()=>PaymentDisplayCurrency::toRial('10', 'USD'));
echo "PASS $pass payment currency boundary assertions\n";
