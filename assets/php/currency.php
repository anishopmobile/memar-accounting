<?php
/**
 * Currency boundary for legacy accounting data. Database money is ALWAYS TOMAN.
 * No floating point calculations and no changes to existing monetary rows.
 * Only TOMAN <-> IRR are active. Display rates are decimal powers, allowing exact shifts.
 */
declare(strict_types=1);

function currency_catalog(): array
{
    return [
        'TOMAN' => ['name_fa' => 'تومان', 'name_en' => 'Toman', 'symbol' => 'تومان', 'power' => 0, 'decimals' => 2, 'enabled' => true],
        'IRR' => ['name_fa' => 'ریال', 'name_en' => 'Iranian Rial', 'symbol' => '﷼', 'power' => 1, 'decimals' => 1, 'enabled' => true],
    ];
}

function getBaseCurrency(): string { return 'TOMAN'; }

function currency_assert_code(string $code): string
{
    $catalog = currency_catalog();
    if (!isset($catalog[$code]) || !$catalog[$code]['enabled']) {
        throw new InvalidArgumentException('واحد پولی نامعتبر است.');
    }
    return $code;
}

function getCurrentCurrency(?mysqli $db = null): string
{
    static $cache = null;
    if ($cache !== null && $db === null) return $cache;
    $db = $db ?? ($GLOBALS['mysqli'] ?? null);
    if (!$db instanceof mysqli) return 'TOMAN';
    try {
        $result = $db->query('SELECT currency_code FROM currency_settings WHERE id = 1 LIMIT 1');
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) $result->free();
        $code = (string) ($row['currency_code'] ?? 'TOMAN');
        if (!isset(currency_catalog()[$code])) $code = 'TOMAN';
        return $cache = $code;
    } catch (Throwable $e) {
        // A pre-migration database must keep working with the legacy TOMAN unit.
        return 'TOMAN';
    }
}

/** Normalize Persian/Arabic digits and separators, validate grouping and decimals. */
function currency_decimal($value): string
{
    if (is_float($value) || is_array($value) || is_object($value) || $value === null) {
        throw new InvalidArgumentException('مبلغ عددی معتبر نیست.');
    }
    $s = trim((string) $value);
    $s = strtr($s, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        '٫'=>'.','٬'=>',','−'=>'-'
    ]);
    if (!preg_match('/^[+-]?(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\.[0-9]+)?$/D', $s)) {
        throw new InvalidArgumentException('قالب مبلغ نامعتبر است.');
    }
    $s = str_replace(',', '', $s);
    $neg = ($s[0] === '-') ? '-' : '';
    if ($s[0] === '-' || $s[0] === '+') $s = substr($s, 1);
    $parts = explode('.', $s, 2);
    $int = ltrim($parts[0], '0');
    $int = $int === '' ? '0' : $int;
    $frac = rtrim($parts[1] ?? '', '0');
    if (strlen($int) > 24 || strlen($frac) > 24) throw new InvalidArgumentException('مبلغ بیش از حد بزرگ است.');
    if ($int === '0' && $frac === '') $neg = '';
    return $neg . $int . ($frac !== '' ? '.' . $frac : '');
}

/** Move decimal point exactly; positive shift multiplies by ten. */
function currency_shift_decimal($amount, int $power): string
{
    $s = currency_decimal($amount);
    $neg = str_starts_with($s, '-') ? '-' : '';
    if ($neg !== '') $s = substr($s, 1);
    $parts = explode('.', $s, 2);
    $digits = $parts[0] . ($parts[1] ?? '');
    $pos = strlen($parts[0]) + $power;
    if ($pos <= 0) { $digits = str_repeat('0', 1 - $pos) . $digits; $pos = 1; }
    if ($pos >= strlen($digits)) $digits .= str_repeat('0', $pos - strlen($digits));
    $res = substr($digits, 0, $pos) . ($pos < strlen($digits) ? '.' . substr($digits, $pos) : '');
    return currency_decimal($neg . $res);
}

function convertFromBaseCurrency($amount, ?string $currency = null): string
{
    $code = currency_assert_code($currency ?? getCurrentCurrency());
    return currency_shift_decimal($amount, currency_catalog()[$code]['power']);
}

function convertToBaseCurrency($amount, ?string $currency = null): string
{
    $code = currency_assert_code($currency ?? getCurrentCurrency());
    return currency_shift_decimal($amount, -currency_catalog()[$code]['power']);
}

function getCurrencyLabel(?string $currency = null): string
{
    return currency_catalog()[currency_assert_code($currency ?? getCurrentCurrency())]['name_fa'];
}
function getCurrencySymbol(?string $currency = null): string
{
    return currency_catalog()[currency_assert_code($currency ?? getCurrentCurrency())]['symbol'];
}

function currency_grouped($amount): string
{
    $s = currency_decimal($amount);
    $neg = str_starts_with($s, '-') ? '-' : '';
    if ($neg !== '') $s = substr($s, 1);
    $parts = explode('.', $s, 2);
    $integer = preg_replace('/\B(?=(?:\d{3})+(?!\d))/', ',', $parts[0]);
    return $neg . $integer . (isset($parts[1]) ? '.' . $parts[1] : '');
}

function formatMoneyNumber($amount, ?string $currency = null): string
{
    return currency_grouped(convertFromBaseCurrency($amount, $currency));
}
function formatMoney($amount, ?string $currency = null): string
{
    return formatMoneyNumber($amount, $currency) . ' ' . getCurrencyLabel($currency);
}

/**
 * Interpret entered monetary values; return DECIMAL(14,2)-compatible TOMAN.
 * Reject values that cannot be stored exactly, do not round silently.
 */
function parseMoneyInput($amount, ?string $currency = null, int $maxIntegerDigits = 12): string
{
    $code = currency_assert_code($currency ?? getCurrentCurrency());
    $s = convertToBaseCurrency($amount, $code);
    $parts = explode('.', ltrim($s, '-'), 2);
    if ($maxIntegerDigits < 1 || $maxIntegerDigits > 24 || strlen($parts[0]) > $maxIntegerDigits || strlen($parts[1] ?? '') > 2) {
        throw new InvalidArgumentException('این مبلغ با دقت مجاز پایگاه داده قابل ذخیره نیست.');
    }
    return $s;
}
