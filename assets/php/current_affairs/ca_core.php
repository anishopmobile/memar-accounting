<?php
/**
 * current_affairs/ca_core.php — Current Affairs shared helpers.
 *
 * Pure helpers (no direct SQL): field-type metadata, safe input parsing,
 * Jalali month-boundary math (based on the project's own jdf.php conversion),
 * canonical value validation, and display formatting.
 *
 * The whole module is designed to be isolated from the product/category,
 * invoice, inventory and accounting systems.
 */
declare(strict_types=1);

/* ---------------- Field type metadata ---------------- */

/** Allowed field types (exactly these seven). */
function ca_allowed_field_types(): array
{
    return ['text', 'textarea', 'number', 'money', 'date', 'select', 'yesno'];
}

/** Persian label of a field type. */
function ca_field_type_label(string $t): string
{
    $map = [
        'text' => 'متن کوتاه',
        'textarea' => 'متن بلند',
        'number' => 'عدد',
        'money' => 'مبلغ (پول)',
        'date' => 'تاریخ',
        'select' => 'انتخاب / منوی کشویی',
        'yesno' => 'بله / خیر',
    ];
    return $map[$t] ?? $t;
}

/** True when the field type holds its canonical value in the DECIMAL column. */
function ca_type_is_numeric(string $t): bool
{
    return $t === 'number' || $t === 'money';
}

/** True when the field type holds its canonical value in the DATE column. */
function ca_type_is_date(string $t): bool
{
    return $t === 'date';
}

/** True when the field type may participate in totals. */
function ca_type_can_total(string $t): bool
{
    return ca_type_is_numeric($t);
}

/**
 * Sort/filter column expression for a field type.
 * Only called with server-side metadata values; returns a static identifier.
 */
function ca_type_sort_expr(string $t): string
{
    if (ca_type_is_date($t)) {
        return 'value_date';
    }
    if (ca_type_is_numeric($t)) {
        return 'value_number';
    }
    return 'value_text';
}

/* ---------------- Safe input parsing ---------------- */

/**
 * Parse a positive integer id. Returns 0 when the value is not a valid id.
 */
function ca_parse_id($v): int
{
    $s = trim((string) ($v ?? ''));
    if ($s === '' || !ctype_digit($s)) {
        return 0;
    }
    $n = (int) $s;
    return $n > 0 ? $n : 0;
}

/**
 * Parse a 0/1 / true / false flag into bool.
 */
function ca_flag_to_bool($v): bool
{
    if (is_bool($v)) {
        return (bool) $v;
    }
    $s = trim((string) ($v ?? ''));
    return $s === '1' || $s === 'true' || $s === 'on' || $s === 'بلی' || $s === 'بله';
}

/** Parse an int within a range; returns $default when invalid. */
function ca_int_clamp($v, int $min, int $max, int $default): int
{
    $s = trim((string) ($v ?? ''));
    if ($s === '' || !ctype_digit($s)) {
        return $default;
    }
    $n = (int) $s;
    return max($min, min($max, $n));
}

/* ---------------- Date helpers ---------------- */

/**
 * Validate a Gregorian ISO date string (Y-m-d). Returns true only for a real date.
 */
function ca_valid_iso_date($v): bool
{
    if (!is_string($v)) {
        return false;
    }
    $s = trim((string) $v);
    if (mb_strlen($s, 'UTF-8') !== 10) {
        return false;
    }
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $s)) {
        return false;
    }
    if (!checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4))) {
        return false;
    }
    return date('Y-m-d', strtotime($s)) === $s;
}

/**
 * First/last Gregorian day (Y-m-d) of a Jalali year/month.
 *
 * The first day is derived through the project's own jalali_to_gregorian()
 * conversion; the last day is computed as (first day of the next Jalali
 * month) − 1 day. This automatically handles 31/30-day months, normal
 * Esfand (29 days) and leap-year Esfand (30 days), and Jalali year
 * boundaries without a second calendar library.
 *
 * @return array ['start' => 'Y-m-d', 'end' => 'Y-m-d', 'days' => int,
 *                'start_jalali' => 'Y/m/d', 'end_jalali' => 'Y/m/d']
 */
function ca_jalali_month_bounds(int $jy, int $jm): array
{
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, 1);
    $startTs = mktime(0, 0, 0, $gm, $gd, $gy);
    if ($jm === 12) {
        list($gy2, $gm2, $gd2) = jalali_to_gregorian($jy + 1, 1, 1);
    } else {
        list($gy2, $gm2, $gd2) = jalali_to_gregorian($jy, $jm + 1, 1);
    }
    $endTs = mktime(0, 0, 0, $gm2, $gd2, $gy2) - 86400;

    return [
        'start' => date('Y-m-d', $startTs),
        'end' => date('Y-m-d', $endTs),
        'days' => (int) (($endTs - $startTs) / 86400) + 1,
        'start_jalali' => jdate('Y/m/d', $startTs),
        'end_jalali' => jdate('Y/m/d', $endTs),
    ];
}

/** Persian display of a Gregorian ISO date. */
function ca_jalali_display($iso): string
{
    if ($iso === null || $iso === '') {
        return '';
    }
    return jdate('Y/m/d', strtotime((string) $iso));
}

/* ---------------- Number / money formatting ---------------- */

/**
 * Format a number with thousand separators, trimming insignificant zeros.
 */
function ca_format_number($v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    $n = (float) $v;
    if (is_nan($n)) {
        return '';
    }
    if (abs($n - round($n)) < 0.0049999) {
        return number_format($n, 0);
    }
    return number_format($n, 2);
}

/** Format a money value (same as number + ریال suffix, project convention). */
function ca_format_money($v): string
{
    return ca_format_number($v) . ' ریال';
}

/* ---------------- Value validation ---------------- */

/**
 * Validate + canonicalize a raw user value against a field definition.
 *
 * On success returns:
 *   ['ok' => true, 'text' => ?string, 'number' => ?string, 'date' => ?string]
 * where exactly one of text/number/date is non-null for a non-empty value and
 * all three are null when the value is empty (optional field).
 *
 * On failure returns ['ok' => false, 'message' => Persian error].
 */
function ca_validate_field_value(array $field, $raw): array
{
    $type = (string) $field['field_type'];
    $label = (string) ($field['label'] ?? '');
    $required = ca_flag_to_bool($field['is_required'] ?? null);

    if (is_array($raw)) {
        $raw = $raw['value'] ?? '';
    }
    $value = is_string($raw) ? trim((string) $raw) : trim((string) ($raw ?? ''));

    if ($value === '') {
        if ($required) {
            return ['ok' => false, 'message' => 'مقدار «' . $label . '» الزامی است.'];
        }
        return ['ok' => true, 'text' => null, 'number' => null, 'date' => null];
    }

    switch ($type) {
        case 'text':
            if (mb_strlen($value, 'UTF-8') > 500) {
                return ['ok' => false, 'message' => 'مقدار «' . $label . '» نمی‌تواند بیشتر از ۵۰۰ کاراکتر باشد.'];
            }
            return ['ok' => true, 'text' => $value, 'number' => null, 'date' => null];

        case 'textarea':
            if (mb_strlen($value, 'UTF-8') > 10000) {
                return ['ok' => false, 'message' => 'مقدار «' . $label . '» نمی‌تواند بیشتر از ۱۰۰۰۰ کاراکتر باشد.'];
            }
            return ['ok' => true, 'text' => $value, 'number' => null, 'date' => null];

        case 'number':
        case 'money':
            if (!preg_match('/^-?\\d+(\\.\\d{1,2})?$/', $value)) {
                return ['ok' => false, 'message' => 'مقدار «' . $label . '» باید یک عدد معتبر با حداکثر دو رقم اعشار باشد.'];
            }
            $num = (float) $value;
            if (abs($num) > 999999999999999999.0) {
                return ['ok' => false, 'message' => 'مقدار «' . $label . '» خارج از بازه مجاز است.'];
            }
            return ['ok' => true, 'text' => null, 'number' => sprintf('%.2f', $num), 'date' => null];

        case 'date':
            if (!ca_valid_iso_date($value)) {
                return ['ok' => false, 'message' => 'تاریخ «' . $label . '» نامعتبر است.'];
            }
            return ['ok' => true, 'text' => null, 'number' => null, 'date' => $value];

        case 'yesno':
            if ($value !== '1' && $value !== '0') {
                return ['ok' => false, 'message' => 'مقدار «' . $label . '» باید بله یا خیر باشد.'];
            }
            return ['ok' => true, 'text' => $value, 'number' => null, 'date' => null];

        case 'select':
            /* Option values are validated against the field's own option rows
             * (server-side). The $field payload should include 'options'. */
            $options = ($field['options'] ?? null);
            $allowed = [];
            if (is_array($options)) {
                foreach ($options as $opt) {
                    $allowed[] = (string) trim((string) ($opt['option_value'] ?? ($opt['value'] ?? '')));
                }
            }
            if (!in_array($value, $allowed, true)) {
                return ['ok' => false, 'message' => 'مقدار انتخابی «' . $label . '» مجاز نیست.'];
            }
            return ['ok' => true, 'text' => $value, 'number' => null, 'date' => null];

        default:
            return ['ok' => false, 'message' => 'نوع فیلد «' . $label . '» ناشناخته است.'];
    }
}

/**
 * Build a unique internal field name from a label (server-side only).
 * Kept stable on later label edits; only used for metadata, never as a
 * raw SQL identifier.
 */
function ca_field_name_from_label(mysqli $m, int $categoryId, string $label): string
{
    $base = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim($label)));
    $base = trim((string) $base, '_-');
    if ($base === '') {
        $base = 'field';
    }
    $name = $base;
    $seen = [];
    $stmt = $m->prepare('SELECT name FROM current_affairs_fields WHERE category_id = ?');
    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $seen[(string) $row['name']] = true;
        }
        $res->free();
    }
    $i = 2;
    while (isset($seen[$name])) {
        $name = $base . '_' . $i;
        $i++;
    }
    return mb_substr((string) $name, 0, 100, 'UTF-8');
}