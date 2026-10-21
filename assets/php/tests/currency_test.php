<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/currency.php';
$tests = 0;
function eq($actual, $expected): void { global $tests; $tests++; if ($actual !== $expected) throw new RuntimeException("Test #{$tests}: ".var_export($actual,true)." != ".var_export($expected,true)); }
function rejected(callable $f): void {global $tests; $tests++; try {$f();} catch (InvalidArgumentException $e) {return;} throw new RuntimeException("Test #{$tests}: expected rejection"); }
eq(getBaseCurrency(), 'TOMAN');
eq(getCurrentCurrency(), 'TOMAN');
eq(formatMoney(10000), '10,000 تومان');
eq(formatMoney(10000, 'IRR'), '100,000 ریال');
eq(formatMoney('4465000', 'IRR'), '44,650,000 ریال');
eq(formatMoney('75000', 'IRR'), '750,000 ریال');
eq(formatMoney(0, 'TOMAN'), '0 تومان');
eq(formatMoney(0, 'IRR'), '0 ریال');
eq(formatMoney(-5000, 'IRR'), '-50,000 ریال');
eq(formatMoney('123456789', 'IRR'), '1,234,567,890 ریال');
eq(parseMoneyInput('1,000,000', 'IRR'), '100000');
eq(parseMoneyInput('۱٬۰۰۰٬۰۰۰', 'IRR'), '100000');
eq(parseMoneyInput('2500000', 'IRR'), '250000');
eq(parseMoneyInput('1000.1', 'IRR'), '100.01');
eq(convertToBaseCurrency('0.1', 'IRR'), '0.01');
eq(convertFromBaseCurrency('0.01', 'IRR'), '0.1');
eq(parseMoneyInput('2000000', 'IRR'), '200000');
eq(parseMoneyInput('200000', 'TOMAN'), '200000');
rejected(fn()=>parseMoneyInput('0.01', 'IRR'));
rejected(fn()=>parseMoneyInput('12,34', 'TOMAN'));
rejected(fn()=>parseMoneyInput('NaN', 'TOMAN'));
rejected(fn()=>parseMoneyInput('9999999999999', 'TOMAN'));
eq(parseMoneyInput('999999999999999999', 'TOMAN', 18), '999999999999999999');
rejected(fn()=>parseMoneyInput('9999999999999999999', 'TOMAN', 18));
rejected(fn()=>convertToBaseCurrency('100', 'USD'));
echo "PASS {$tests} currency assertions\n";
