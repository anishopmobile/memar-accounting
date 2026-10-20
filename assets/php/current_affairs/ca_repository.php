<?php
/**
 * current_affairs/ca_repository.php — Current Affairs data access.
 *
 * All SQL uses prepared statements. Dynamic identifiers (join aliases,
 * ORDER BY expressions) are selected only from trusted server-side field
 * metadata (field ids are validated integers referencing the selected
 * category's own fields). Amounts are stored as canonical DECIMAL values,
 * never formatted strings, and never sent through client round-trips.
 *
 * Accounting boundary: this module only writes to its own
 * `current_affairs_*` tables. It never touches products, invoices,
 * customers, suppliers, stock movements, vouchers or ledgers.
 */
declare(strict_types=1);

/* ============================================================
 * Categories
 * ========================================================== */

function ca_cat_get(mysqli $m, int $id): ?array
{
    $stmt = $m->prepare('SELECT * FROM current_affairs_categories WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res === null) {
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return $row !== null ? $row : null;
}

function ca_cat_list(mysqli $m): array
{
    $rows = [];
    $res = $m->query(
        'SELECT c.*, '
        . '(SELECT COUNT(*) FROM current_affairs_records r WHERE r.category_id = c.id) AS record_count, '
        . '(SELECT COUNT(*) FROM current_affairs_fields f WHERE f.category_id = c.id) AS field_count '
        . 'FROM current_affairs_categories c '
        . 'ORDER BY c.is_active DESC, c.id ASC'
    );
    if ($res !== null) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return $rows;
}

/**
 * Create or update a category.
 */
function ca_cat_save(mysqli $m, ?int $id, string $name, string $description): array
{
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'message' => 'نام دسته‌بندی الزامی است.'];
    }
    if (mb_strlen($name, 'UTF-8') > 150) {
        return ['ok' => false, 'message' => 'نام دسته‌بندی نمی‌تواند بیشتر از ۱۵۰ کاراکتر باشد.'];
    }
    $description = trim($description);
    if (mb_strlen($description, 'UTF-8') > 4000) {
        return ['ok' => false, 'message' => 'توضیحات دسته‌بندی نمی‌تواند بیشتر از ۴۰۰۰ کاراکتر باشد.'];
    }

    if ($id !== null && $id > 0) {
        $existing = ca_cat_get($m, $id);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'دسته‌بندی یافت نشد.'];
        }
        $params = [];
        $params[] = $name;
        $params[] = $description !== '' ? $description : null;
        $params[] = $id;
        $stmt = $m->prepare('UPDATE current_affairs_categories SET name = ?, description = ? WHERE id = ?');
        $stmt->bind_param('ssi', ...$params);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['ok' => false, 'message' => 'خطا در بروزرسانی دسته‌بندی.'];
        }
        $stmt->close();
        return ['ok' => true, 'message' => 'دسته‌بندی بروزرسانی شد.', 'data' => ['id' => $id, 'name' => $name, 'description' => $description]];
    }

    $params = [];
    $params[] = $name;
    $params[] = $description !== '' ? $description : null;
    $stmt = $m->prepare('INSERT INTO current_affairs_categories (name, description) VALUES (?, ?)');
    $stmt->bind_param('ss', ...$params);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'message' => 'خطا در ذخیره‌سازی دسته‌بندی.'];
    }
    $newId = $stmt->insert_id;
    $stmt->close();
    return ['ok' => true, 'message' => 'دسته‌بندی ایجاد شد.', 'data' => ['id' => (int) $newId, 'name' => $name, 'description' => $description]];
}

/** Activate / deactivate a category. Records are never touched. */
function ca_cat_set_active(mysqli $m, int $id, bool $active): array
{
    $existing = ca_cat_get($m, $id);
    if ($existing === null) {
        return ['ok' => false, 'message' => 'دسته‌بندی یافت نشد.'];
    }
    $flag = $active ? 1 : 0;
    $stmt = $m->prepare('UPDATE current_affairs_categories SET is_active = ? WHERE id = ?');
    $stmt->bind_param('ii', $flag, $id);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'message' => 'خطا در تغییر وضعیت دسته‌بندی.'];
    }
    $stmt->close();
    return ['ok' => true, 'message' => $active ? 'دسته‌بندی فعال شد.' : 'دسته‌بندی غیرفعال شد.', 'data' => ['id' => $id, 'is_active' => $active ? 1 : 0]];
}

/**
 * Delete a category ONLY when it contains no records (safe deletion rule).
 */
function ca_cat_delete(mysqli $m, int $id): array
{
    $existing = ca_cat_get($m, $id);
    if ($existing === null) {
        return ['ok' => false, 'message' => 'دسته‌بندی یافت نشد.'];
    }
    $stmt = $m->prepare('SELECT COUNT(*) AS c FROM current_affairs_records WHERE category_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $count = 0;
    if ($res !== null && ($row = $res->fetch_assoc())) {
        $count = (int) $row['c'];
        $res->free();
    }
    if ($count > 0) {
        return [
            'ok' => false,
            'message' => 'این دسته‌بندی شامل ' . number_format($count, 0) . ' رکورد است و قابل حذف نیست. به‌جای آن می‌توانید آن را غیرفعال کنید.',
        ];
    }

    $m->begin_transaction();
    try {
        $m->query('DELETE FROM current_affairs_field_options WHERE field_id IN (SELECT id FROM current_affairs_fields WHERE category_id = ' . (int) $id . ')');
        $m->query('DELETE FROM current_affairs_fields WHERE category_id = ' . (int) $id);
        $stmt = $m->prepare('DELETE FROM current_affairs_categories WHERE id = ?');
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('delete_failed');
        }
        $stmt->close();
        $m->commit();
    } catch (Exception $e) {
        $m->rollback();
        return ['ok' => false, 'message' => 'خطا در حذف دسته‌بندی.'];
    }
    return ['ok' => true, 'message' => 'دسته‌بندی حذف شد.', 'data' => ['id' => $id]];
}

/* ============================================================
 * Fields
 * ========================================================== */

function ca_field_get(mysqli $m, int $id): ?array
{
    $stmt = $m->prepare('SELECT * FROM current_affairs_fields WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res === null) {
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return $row !== null ? $row : null;
}

function ca_option_list(mysqli $m, int $fieldId): array
{
    $rows = [];
    if ($fieldId <= 0) {
        return $rows;
    }
    $stmt = $m->prepare('SELECT * FROM current_affairs_field_options WHERE field_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->bind_param('i', $fieldId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res !== null) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return $rows;
}

/** It is not safe to change the type of a field that already has values. */
function ca_field_has_values(mysqli $m, int $fieldId): bool
{
    $stmt = $m->prepare('SELECT id FROM current_affairs_values WHERE field_id = ? LIMIT 1');
    $stmt->bind_param('i', $fieldId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $has = $res !== null && $res->fetch_assoc() !== null;
    if ($res !== null) {
        $res->free();
    }
    return (bool) $has;
}

/**
 * The valid, active Reporting Date field of a category, or null.
 * Never guesses/infers among multiple Date fields.
 */
function ca_reporting_field(mysqli $m, int $categoryId): ?array
{
    $stmt = $m->prepare(
        'SELECT * FROM current_affairs_fields '
        . 'WHERE category_id = ? AND is_reporting_date = 1 AND is_active = 1 AND field_type = \'date\' '
        . 'LIMIT 1'
    );
    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res === null) {
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return $row !== null ? $row : null;
}

/**
 * Validate + normalize select options coming from the client.
 * Options are plain strings; duplicates (case-insensitive) are rejected.
 */
function ca_validate_options($raw): array
{
    if (!is_array($raw) || count($raw) > 50) {
        return ['ok' => false, 'message' => 'گزینه‌های فیلد انتخابی نامعتبر است.'];
    }
    $options = [];
    $seen = [];
    foreach ($raw as $optRaw) {
        $value = trim((string) ($optRaw['value'] ?? ($optRaw['option_value'] ?? $optRaw)));
        if ($value === '') {
            continue;
        }
        if (mb_strlen($value, 'UTF-8') > 255) {
            return ['ok' => false, 'message' => 'هر گزینه حداکثر ۲۵۵ کاراکتر می‌تواند.'];
        }
        $key = mb_strtolower($value, 'UTF-8');
        if (isset($seen[$key])) {
            return ['ok' => false, 'message' => 'گزینه تکراری «' . $value . '» مجاز نیست.'];
        }
        $seen[$key] = true;
        $options[] = $value;
    }
    if (count($options) === 0) {
        return ['ok' => false, 'message' => 'فیلد انتخابی حداقل یک گزینه لازم دارد.'];
    }
    return ['ok' => true, 'options' => $options];
}

/** Convert a validated meta boolean into a MySQL 0/1 int (never NULL). */
function ca_int_flag(bool $b): int
{
    return $b ? 1 : 0;
}

/**
 * Create or update a custom field.
 * Reporting Date semantics (transactional): only an active Date field can be
 * designated; a new designation removes the previous one first; deactivating a
 * field or changing its type away from Date clears the designation in the same
 * transaction (enforced at DB level by CHECK chk_caf_rep_date + unique key
 * uq_caf_rep_date). Type changes are rejected once values exist.
 */
function ca_field_save(mysqli $m, ?int $id, int $categoryId, string $label, string $fieldType,
                       bool $isRequired, bool $showInTable, bool $isFilterable, bool $includeInTotals,
                       int $sortOrder, bool $isActive, bool $isReportingDate, array $options): array
{
    $label = trim($label);
    if (!in_array($fieldType, ca_allowed_field_types(), true)) {
        return ['ok' => false, 'message' => 'نوع فیلد نامعتبر است.'];
    }
    if ($label === '') {
        return ['ok' => false, 'message' => 'نام نمایشی فیلد الزامی است.'];
    }
    if (mb_strlen($label, 'UTF-8') > 255) {
        return ['ok' => false, 'message' => 'نام نمایشی فیلد نمی‌تواند بیشتر از ۲۵۵ کاراکتر باشد.'];
    }
    if ($categoryId <= 0 || ca_cat_get($m, $categoryId) === null) {
        return ['ok' => false, 'message' => 'دسته‌بندی انتخابی یافت نشد.'];
    }
    if ($includeInTotals && !ca_type_can_total($fieldType)) {
        return ['ok' => false, 'message' => 'گزینه «شرکت در مجموع‌ها» فقط برای فیلدهای عددی و مبلغ مجاز است.'];
    }
    if ($isReportingDate && (!ca_type_is_date($fieldType) || !$isActive)) {
        return ['ok' => false, 'message' => 'تاریخ گزارشگیری فقط روی یک فیلد تاریخ فعال قابل تعیین است.'];
    }

    $optionValues = [];
    if ($fieldType === 'select') {
        $optRes = ca_validate_options($options);
        if (!$optRes['ok']) {
            return $optRes;
        }
        $optionValues = (array) $optRes['options'];
    }

    $isUpdate = $id !== null && $id > 0;
    if ($isUpdate) {
        $existing = ca_field_get($m, $id);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'فیلد یافت نشد.'];
        }
        if ((int) $existing['category_id'] !== $categoryId) {
            return ['ok' => false, 'message' => 'فیلد انتخابی متعلق به این دسته‌بندی نیست.'];
        }
        if ((string) $existing['field_type'] !== $fieldType && ca_field_has_values($m, $id)) {
            return [
                'ok' => false,
                'message' => 'این فیلد دارای مقادیر تاریخی است و تغییر نوع آن مجاز نیست. فیلد جدید بسازید و فیلد فعلی را غیرفعال کنید.',
            ];
        }
    }

    $m->begin_transaction();
    try {
        $fieldId = 0;

        if ($isUpdate) {
            /* Clear own designation first so the CHECK never fires. */
            $stmt = $m->prepare('UPDATE current_affairs_fields SET is_reporting_date = NULL WHERE id = ? AND category_id = ?');
            $stmt->bind_param('ii', $id, $categoryId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('rep_clear_failed');
            }
            $stmt->close();
        }

        if ($isReportingDate) {
            /* At most one per category: remove any previous designation. */
            $stmt = $m->prepare('UPDATE current_affairs_fields SET is_reporting_date = NULL WHERE category_id = ? AND is_reporting_date = 1');
            $stmt->bind_param('i', $categoryId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('rep_clear_all_failed');
            }
            $stmt->close();
        }

        if ($isUpdate) {
            $params = [];
            $params[] = $label;
            $params[] = $fieldType;
            $params[] = ca_int_flag($isRequired);
            $params[] = ca_int_flag($showInTable);
            $params[] = ca_int_flag($isFilterable);
            $params[] = ca_int_flag($includeInTotals);
            $params[] = $sortOrder;
            $params[] = ca_int_flag($isActive);
            $params[] = $id;
            $params[] = $categoryId;
            $stmt = $m->prepare(
                'UPDATE current_affairs_fields SET label = ?, field_type = ?, is_required = ?, '
                . 'show_in_table = ?, is_filterable = ?, include_in_totals = ?, sort_order = ?, is_active = ? '
                . 'WHERE id = ? AND category_id = ?'
            );
            $stmt->bind_param('ssiiiiiiii', ...$params);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('field_update_failed');
            }
            $stmt->close();
            $fieldId = $id;
        } else {
            $name = ca_field_name_from_label($m, $categoryId, $label);
            $params = [];
            $params[] = $categoryId;
            $params[] = $name;
            $params[] = $label;
            $params[] = $fieldType;
            $params[] = ca_int_flag($isRequired);
            $params[] = ca_int_flag($showInTable);
            $params[] = ca_int_flag($isFilterable);
            $params[] = ca_int_flag($includeInTotals);
            $params[] = $sortOrder;
            $params[] = ca_int_flag($isActive);
            $stmt = $m->prepare(
                'INSERT INTO current_affairs_fields '
                . '(category_id, name, label, field_type, is_required, show_in_table, is_filterable, include_in_totals, sort_order, is_active) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('isssiiiiii', ...$params);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('field_insert_failed');
            }
            $fieldId = (int) $stmt->insert_id;
            $stmt->close();
        }

        if ($isReportingDate) {
            /* Re-apply the designation with DB-side guards (Date + active). */
            $stmt = $m->prepare(
                'UPDATE current_affairs_fields SET is_reporting_date = 1 '
                . 'WHERE id = ? AND category_id = ? AND field_type = \'date\' AND is_active = 1'
            );
            $params = [];
            $params[] = $fieldId;
            $params[] = $categoryId;
            $stmt->bind_param('ii', ...$params);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new Exception('rep_apply_failed');
            }
            $stmt->close();
        } else if ($isUpdate) {
            $stmt = $m->prepare('UPDATE current_affairs_fields SET is_reporting_date = NULL WHERE id = ? AND category_id = ?');
            $stmt->bind_param('ii', $fieldId, $categoryId);
            $stmt->execute();
            $stmt->close();
        }

        if ($fieldType === 'select') {
            $del = $m->prepare('DELETE FROM current_affairs_field_options WHERE field_id = ?');
            $params = [];
            $params[] = $fieldId;
            $del->bind_param('i', ...$params);
            $del->execute();
            $del->close();
            $i = 0;
            foreach ($optionValues as $optVal) {
                $i++;
                $ins = $m->prepare(
                    'INSERT INTO current_affairs_field_options (field_id, option_value, sort_order, is_active) VALUES (?, ?, ?, 1)'
                );
                $params = [];
                $params[] = $fieldId;
                $params[] = $optVal;
                $params[] = $i;
                $ins->bind_param('isi', ...$params);
                if (!$ins->execute()) {
                    $ins->close();
                    throw new Exception('option_insert_failed');
                }
                $ins->close();
            }
        }

        $m->commit();
    } catch (Exception $e) {
        $m->rollback();
        $msg = (string) $e->getMessage();
        if (str_contains(strtolower($msg), 'duplicate')) {
            return ['ok' => false, 'message' => 'تنظیم تاریخ گزارش‌گیری همزمان توسط کاربر دیگری تغییر کرد؛ لطفاً صفحه را تازه‌سازی کنید.'];
        }
        return ['ok' => false, 'message' => 'خطا در ذخیره‌سازی فیلد.'];
    }

    $data = ca_field_get($m, $fieldId);
    if ($data !== null) {
        $data['options'] = ca_option_list($m, $fieldId);
    }
    return [
        'ok' => true,
        'message' => $isUpdate ? 'فیلد بروزرسانی شد.' : 'فیلد ایجاد شد.',
        'data' => $data,
    ];
}

/**
 * Activate / deactivate a field. Deactivating the designated Reporting Date
 * clears its designation inside the same transaction (Monthly Reporting then
 * stays blocked until another Date field is explicitly designated).
 */
function ca_field_set_active(mysqli $m, int $id, bool $active): array
{
    $existing = ca_field_get($m, $id);
    if ($existing === null) {
        return ['ok' => false, 'message' => 'فیلد یافت نشد.'];
    }
    $wasReporting = (int) ($existing['is_reporting_date'] ?? 0) === 1;

    $m->begin_transaction();
    try {
        if (!$active && $wasReporting) {
            $stmt = $m->prepare('UPDATE current_affairs_fields SET is_active = 0, is_reporting_date = NULL WHERE id = ?');
            $stmt->bind_param('i', $id);
        } else {
            $flag = $active ? 1 : 0;
            $stmt = $m->prepare('UPDATE current_affairs_fields SET is_active = ? WHERE id = ?');
            $stmt->bind_param('ii', $flag, $id);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('set_active_failed');
        }
        $stmt->close();
        $m->commit();
    } catch (Exception $e) {
        $m->rollback();
        return ['ok' => false, 'message' => 'خطا در تغییر وضعیت فیلد.'];
    }

    if (!$active && $wasReporting) {
        return [
            'ok' => true,
            'message' => 'فیلد غیرفعال شد. از آن‌جا که این فیلد تاریخ گزارش‌گیری بود، تعیین آن حذف شد و گزارش ماهانه تا تعیین یک تاریخ گزارش‌گیری جدید مسدود است.',
            'data' => ['id' => $id, 'is_active' => 0, 'is_reporting_date' => null],
        ];
    }
    return ['ok' => true, 'message' => $active ? 'فیلد فعال شد.' : 'فیلد غیرفعال شد.', 'data' => ['id' => $id, 'is_active' => $active ? 1 : 0]];
}

/**
 * Delete a field only when it has no values (safe deletion rule). Historical
 * values force the administrator to deactivate instead, which preserves data.
 */
function ca_field_delete(mysqli $m, int $id): array
{
    $existing = ca_field_get($m, $id);
    if ($existing === null) {
        return ['ok' => false, 'message' => 'فیلد یافت نشد.'];
    }
    if (ca_field_has_values($m, $id)) {
        return [
            'ok' => false,
            'message' => 'این فیلد دارای مقادیر تاریخی است و قابل حذف نیست. برای حفظ داده‌ها آن را غیرفعال کنید.',
        ];
    }
    $m->begin_transaction();
    try {
        $delOpts = $m->prepare('DELETE FROM current_affairs_field_options WHERE field_id = ?');
        $delOpts->bind_param('i', $id);
        $delOpts->execute();
        $delOpts->close();
        $del = $m->prepare('DELETE FROM current_affairs_fields WHERE id = ?');
        $del->bind_param('i', $id);
        if (!$del->execute()) {
            $del->close();
            throw new Exception('delete_failed');
        }
        $del->close();
        $m->commit();
    } catch (Exception $e) {
        $m->rollback();
        return ['ok' => false, 'message' => 'خطا در حذف فیلد.'];
    }
    return ['ok' => true, 'message' => 'فیلد حذف شد.', 'data' => ['id' => $id]];
}

/**
 * Explicitly designate a Date field as the Reporting Date (transactional).
 * Rejects: non-Date fields, cross-category field ids, inactive fields. The
 * database unique key uq_caf_rep_date guarantees one designation per category.
 */
function ca_set_reporting_date(mysqli $m, int $categoryId, int $fieldId): array
{
    $field = ca_field_get($m, $fieldId);
    if ($field === null || (int) $field['category_id'] !== $categoryId) {
        return ['ok' => false, 'message' => 'فیلد انتخابی متعلق به این دسته‌بندی نیست.'];
    }
    if ((string) $field['field_type'] !== 'date') {
        return ['ok' => false, 'message' => 'فقط یک فیلد از نوع «تاریخ» می‌تواند تاریخ گزارش‌گیری باشد.'];
    }
    if ((int) ($field['is_active'] ?? 0) !== 1) {
        return ['ok' => false, 'message' => 'فیلد تاریخ گزارش‌گیری باید فعال باشد.'];
    }

    $m->begin_transaction();
    try {
        $stmt = $m->prepare('UPDATE current_affairs_fields SET is_reporting_date = NULL WHERE category_id = ? AND is_reporting_date = 1');
        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        $stmt->close();

        $stmt = $m->prepare(
            'UPDATE current_affairs_fields SET is_reporting_date = 1 '
            . 'WHERE id = ? AND category_id = ? AND field_type = \'date\' AND is_active = 1'
        );
        $params = [];
        $params[] = $fieldId;
        $params[] = $categoryId;
        $stmt->bind_param('ii', ...$params);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new Exception('apply_failed');
        }
        $stmt->close();
        $m->commit();
    } catch (Exception $e) {
        $m->rollback();
        $msg = (string) $e->getMessage();
        if (str_contains(strtolower($msg), 'duplicate')) {
            return ['ok' => false, 'message' => 'تنظیم تاریخ گزارش‌گیری همزمان توسط کاربر دیگری تغییر کرد؛ لطفاً صفحه را تازه‌سازی کنید.'];
        }
        return ['ok' => false, 'message' => 'خطا در تعیین تاریخ گزارش‌گیری.'];
    }
    return ['ok' => true, 'message' => 'این فیلد به‌عنوان تاریخ گزارش‌گیری تعیین شد.', 'data' => ['id' => $fieldId, 'is_reporting_date' => 1]];
}

/** Remove the Reporting Date designation from a field. */
function ca_clear_reporting_date(mysqli $m, int $categoryId, int $fieldId): array
{
    $field = ca_field_get($m, $fieldId);
    if ($field === null || (int) $field['category_id'] !== $categoryId) {
        return ['ok' => false, 'message' => 'فیلد انتخابی متعلق به این دسته‌بندی نیست.'];
    }
    $stmt = $m->prepare('UPDATE current_affairs_fields SET is_reporting_date = NULL WHERE id = ? AND category_id = ?');
    $stmt->bind_param('ii', $fieldId, $categoryId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'message' => 'خطا در حذف تعیین تاریخ گزارش‌گیری.'];
    }
    $stmt->close();
    return ['ok' => true, 'message' => 'تعیین تاریخ گزارش‌گیری حذف شد.', 'data' => ['id' => $fieldId, 'is_reporting_date' => null]];
}

/* ============================================================
 * Records
 * ========================================================== */

/**
 * Fields of a category with their select options attached.
 *
 * @param bool $onlyActive When true, inactive fields are excluded (use for
 *        the dynamic record forms and listings; historical values are never
 *        touched by hiding a field).
 */
function ca_record_fields(mysqli $m, int $categoryId, bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM current_affairs_fields WHERE category_id = ?';
    if ($onlyActive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';

    $fields = [];
    $stmt = $m->prepare($sql);
    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res === null) {
        return $fields;
    }
    while ($row = $res->fetch_assoc()) {
        $fields[] = $row;
    }
    $res->free();
    foreach ($fields as &$f) {
        $f['options'] = ca_option_list($m, (int) $f['id']);
    }
    return $fields;
}

/** Map of all category fields: field id => field row (with options). */
function ca_fields_map(mysqli $m, int $categoryId): array
{
    $map = [];
    foreach (ca_record_fields($m, $categoryId, false) as $f) {
        $map[(int) $f['id']] = $f;
    }
    return $map;
}

/** Load one record together with its values (field id => value row). */
function ca_record_get(mysqli $m, int $recordId): ?array
{
    $stmt = $m->prepare('SELECT * FROM current_affairs_records WHERE id = ?');
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res === null) {
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();
    if ($row === null) {
        return null;
    }

    $values = [];
    $stmt = $m->prepare('SELECT * FROM current_affairs_values WHERE record_id = ?');
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res !== null) {
        while ($v = $res->fetch_assoc()) {
            $values[(int) $v['field_id']] = $v;
        }
        $res->free();
    }
    $row['values'] = $values;
    return $row;
}

/** Delete one record (values cascade through the FK). */
function ca_record_delete(mysqli $m, int $recordId): array
{
    $stmt = $m->prepare('SELECT id FROM current_affairs_records WHERE id = ?');
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $exists = $res !== null && $res->fetch_assoc() !== null;
    if ($res !== null) {
        $res->free();
    }
    if (!$exists) {
        return ['ok' => false, 'message' => 'رکورد یافت نشد.'];
    }
    $stmt = $m->prepare('DELETE FROM current_affairs_records WHERE id = ?');
    $stmt->bind_param('i', $recordId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'message' => 'خطا در حذف رکورد.'];
    }
    $stmt->close();
    return ['ok' => true, 'message' => 'رکورد حذف شد.', 'data' => ['id' => $recordId]];
}

/**
 * Create or update a record from dynamic values.
 *
 * Only active fields of the category participate (deactivated fields keep
 * their historical values untouched). All values are validated server-side
 * against the field metadata and stored in the canonical typed column:
 * text/textarea/select/yesno → value_text, number/money → value_number,
 * date → value_date. No value is ever trusted from the client.
 *
 * @param array $submitted Map of fieldId => raw value (from JSON).
 */
function ca_record_save(mysqli $m, ?int $id, int $categoryId, array $submitted): array
{
    if ($categoryId <= 0 || ca_cat_get($m, $categoryId) === null) {
        return ['ok' => false, 'message' => 'دسته‌بندی انتخابی یافت نشد.'];
    }

    $isUpdate = $id !== null && $id > 0;
    $existing = null;
    if ($isUpdate) {
        $existing = ca_record_get($m, $id);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'رکورد یافت نشد.'];
        }
        if ((int) $existing['category_id'] !== $categoryId) {
            return ['ok' => false, 'message' => 'رکورد انتخابی متعلق به این دسته‌بندی نیست.'];
        }
    }

    $recordsFields = ca_record_fields($m, $categoryId, true);
    $validated = [];
    foreach ($recordsFields as $f) {
        $fid = (int) $f['id'];
        $raw = $submitted[(string) $fid] ?? $submitted[$fid] ?? '';
        $vr = ca_validate_field_value($f, $raw);
        if (!$vr['ok'] && $isUpdate && (string) $f['field_type'] === 'select' && $existing !== null) {
            /* Safe legacy rule: when editing, a Select value that exactly
             * matches the record's own previously-stored value is accepted
             * even if that option was later removed/renamed. This lets the
             * user save an unchanged record without reinterpreting history. */
            $existingRow = ($existing['values'][$fid] ?? null);
            if ($existingRow !== null
                && trim((string) ($existingRow['value_text'] ?? '')) === trim((string) $raw)) {
                $vr = ['ok' => true, 'text' => (string) $raw, 'number' => null, 'date' => null];
            }
        }
        if (!$vr['ok']) {
            return ['ok' => false, 'message' => $vr['message']];
        }
        $validated[] = [
            'field' => $f,
            'text' => $vr['text'],
            'number' => $vr['number'],
            'date' => $vr['date'],
        ];
    }

    $m->begin_transaction();
    try {
        $recordId = 0;
        if ($isUpdate) {
            $stmt = $m->prepare('UPDATE current_affairs_records SET category_id = ? WHERE id = ?');
            $stmt->bind_param('ii', $categoryId, $id);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('record_update_failed');
            }
            $stmt->close();
            $recordId = $id;
        } else {
            $stmt = $m->prepare('INSERT INTO current_affairs_records (category_id) VALUES (?)');
            $stmt->bind_param('i', $categoryId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('record_insert_failed');
            }
            $recordId = (int) $stmt->insert_id;
            $stmt->close();
        }

        /* Preserve historical values of deactivated fields: only the values
         * of the currently-active fields are removed and rewritten. */
        if ($isUpdate && $recordsFields !== []) {
            $activeFieldIds = [];
            foreach ($recordsFields as $f) {
                $activeFieldIds[] = (int) $f['id'];
            }
            $placeholders = '';
            $typesIn = 'i';
            for ($i = 0; $i < count($activeFieldIds); $i++) {
                if ($placeholders !== '') {
                    $placeholders .= ',';
                }
                $placeholders .= '?';
                $typesIn .= 'i';
            }
            $params = [];
            $params[] = $recordId;
            foreach ($activeFieldIds as $aid) {
                $params[] = $aid;
            }
            $stmt = $m->prepare(
                'DELETE FROM current_affairs_values WHERE record_id = ? AND field_id IN (' . $placeholders . ')'
            );
            $stmt->bind_param($typesIn, ...$params);
            $stmt->execute();
            $stmt->close();
        }

        foreach ($validated as $item) {
            $fid = (int) $item['field']['id'];
            $vText = $item['text'] !== null ? (string) $item['text'] : '';
            $vNumber = $item['number'] !== null ? (string) $item['number'] : '';
            $vDate = $item['date'] !== null ? (string) $item['date'] : '';
            if ($item['text'] !== null) {
                $stmt = $m->prepare('INSERT INTO current_affairs_values (record_id, field_id, value_text) VALUES (?, ?, ?)');
                $stmt->bind_param('iis', $recordId, $fid, $vText);
            } elseif ($item['number'] !== null) {
                $stmt = $m->prepare('INSERT INTO current_affairs_values (record_id, field_id, value_number) VALUES (?, ?, ?)');
                $stmt->bind_param('iis', $recordId, $fid, $vNumber);
            } elseif ($item['date'] !== null) {
                $stmt = $m->prepare('INSERT INTO current_affairs_values (record_id, field_id, value_date) VALUES (?, ?, ?)');
                $stmt->bind_param('iis', $recordId, $fid, $vDate);
            } else {
                continue;
            }
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('value_insert_failed');
            }
            $stmt->close();
        }

        $m->commit();
    } catch (Exception $e) {
        $m->rollback();
        return ['ok' => false, 'message' => 'خطا در ذخیره‌سازی رکورد.'];
    }

    return [
        'ok' => true,
        'message' => $isUpdate ? 'رکورد بروزرسانی شد.' : 'رکورد ثبت شد.',
        'data' => ca_record_get($m, $recordId),
    ];
}

/* ============================================================
 * Record query builder (search / filters / sort / pagination)
 * ========================================================== */

/**
 * Build parameterized SQL fragments for listing records.
 * Dynamic identifiers (join aliases like `v12`, ORDER BY columns) come only
 * from trusted server-side metadata: integer field ids are validated against
 * the category's own fields and passed as bound parameters, never
 * interpolated. Sort keys are an explicit whitelist.
 *
 * @param int   $repFieldId When > 0 adds the Reporting Date restriction
 *        (Monthly Reporting): row must belong to the month range.
 * @param array $dateRange  ['from' => 'Y-m-d', 'to' => 'Y-m-d'].
 */
function ca_build_record_query_parts(mysqli $m, int $categoryId, string $search, array $filters,
                                     string $sort, string $dir, array $fieldsById,
                                     int $repFieldId = 0, array $dateRange = []): array
{
    $joins = [];
    $joinTypes = '';
    $joinParams = [];
    $joined = [];

    $where = [];
    $types = 'i';
    $params = [];
    $params[] = $categoryId;
    $where[] = 'r.category_id = ?';

    /* General word-based search across all text values of the category. */
    if ($search !== '') {
        $tokens = preg_split('/\\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            $tokens = [(string) $search];
        }
        foreach ($tokens as $token) {
            $likeVal = '%' . (string) $token . '%';
            $where[] = 'EXISTS (SELECT 1 FROM current_affairs_values sv '
                . 'JOIN current_affairs_fields sf ON sf.id = sv.field_id '
                . 'WHERE sv.record_id = r.id AND sf.category_id = ? AND sv.value_text LIKE ?)';
            $types .= 'is';
            $params[] = $categoryId;
            $params[] = $likeVal;
        }
    }

    /* Per-field filters — only is_filterable fields of this category. */
    if (is_array($filters)) {
        foreach ($filters as $fidRaw => $spec) {
            $fid = ca_parse_id($fidRaw);
            if ($fid <= 0) {
                continue;
            }
            $f = $fieldsById[$fid] ?? null;
            if ($f === null || !ca_flag_to_bool($f['is_filterable'] ?? null)) {
                continue;
            }
            if (!isset($joined[$fid])) {
                $joined[$fid] = true;
                $joins[] = 'LEFT JOIN current_affairs_values v' . $fid . ' ON v' . $fid . '.record_id = r.id AND v' . $fid . '.field_id = ?';
                $joinTypes .= 'i';
                $joinParams[] = $fid;
            }
            $alias = 'v' . $fid;
            $type = (string) $f['field_type'];
            if (!is_array($spec)) {
                $spec = [];
            }
            if ($type === 'text' || $type === 'textarea') {
                $val = trim((string) ($spec['value'] ?? ''));
                if ($val !== '') {
                    $where[] = $alias . '.value_text LIKE ?';
                    $types .= 's';
                    $params[] = '%' . $val . '%';
                }
            } elseif ($type === 'number' || $type === 'money') {
                $from = trim((string) ($spec['from'] ?? ''));
                $to = trim((string) ($spec['to'] ?? ''));
                if ($from !== '' && preg_match('/^-?\\d+(\\.\\d{1,2})?$/', $from)) {
                    $where[] = $alias . '.value_number >= CAST(? AS DECIMAL(20,2))';
                    $types .= 's';
                    $params[] = $from;
                }
                if ($to !== '' && preg_match('/^-?\\d+(\\.\\d{1,2})?$/', $to)) {
                    $where[] = $alias . '.value_number <= CAST(? AS DECIMAL(20,2))';
                    $types .= 's';
                    $params[] = $to;
                }
            } elseif ($type === 'date') {
                $from = trim((string) ($spec['from'] ?? ''));
                $to = trim((string) ($spec['to'] ?? ''));
                if (ca_valid_iso_date($from)) {
                    $where[] = $alias . '.value_date >= ?';
                    $types .= 's';
                    $params[] = $from;
                }
                if (ca_valid_iso_date($to)) {
                    $where[] = $alias . '.value_date <= ?';
                    $types .= 's';
                    $params[] = $to;
                }
            } elseif ($type === 'select' || $type === 'yesno') {
                $val = trim((string) ($spec['value'] ?? ''));
                if ($val !== '') {
                    $where[] = $alias . '.value_text = ?';
                    $types .= 's';
                    $params[] = $val;
                }
            }
        }
    }

    /* Monthly Reporting restriction (explicitly designated Date field only). */
    /* The vr alias is always used here so the restriction stays valid even
     * when the same Date field was already joined for a filter/sort. */
    if ($repFieldId > 0) {
        $joins[] = 'LEFT JOIN current_affairs_values vr ON vr.record_id = r.id AND vr.field_id = ?';
        $joinTypes .= 'i';
        $joinParams[] = $repFieldId;
        $from = trim((string) ($dateRange['from'] ?? ''));
        $to = trim((string) ($dateRange['to'] ?? ''));
        if (ca_valid_iso_date($from) && ca_valid_iso_date($to)) {
            $where[] = 'vr.value_date BETWEEN ? AND ?';
            $types .= 'ss';
            $params[] = $from;
            $params[] = $to;
        }
    }

    /* Sort — from a whitelist of trusted server-side keys only. */
    $dirSql = $dir === 'desc' ? 'DESC' : 'ASC';
    $orderExpr = '';
    if ($sort === '__id') {
        $orderExpr = 'r.id';
    } elseif ($sort === '__created') {
        $orderExpr = 'r.created_at';
    } elseif ($sort === '__updated') {
        $orderExpr = 'r.updated_at';
    } else {
        /* sort keys look like "f<fieldId>" from the client; strip the prefix
         * and validate the integer against the category's own fields. */
        $sortIdRaw = $sort;
        if (preg_match('/^f\\d+$/', $sortIdRaw)) {
            $sortIdRaw = (string) substr($sortIdRaw, 1);
        }
        $sortId = ca_parse_id($sortIdRaw);
        $sortField = $sortId > 0 ? ($fieldsById[$sortId] ?? null) : null;
        if ($sortField !== null && ca_flag_to_bool($sortField['is_active'] ?? null)) {
            if (!isset($joined[$sortId])) {
                $joined[$sortId] = true;
                $joins[] = 'LEFT JOIN current_affairs_values v' . $sortId . ' ON v' . $sortId . '.record_id = r.id AND v' . $sortId . '.field_id = ?';
                $joinTypes .= 'i';
                $joinParams[] = $sortId;
            }
            $orderExpr = 'v' . $sortId . '.' . ca_type_sort_expr((string) $sortField['field_type']);
        } else {
            $orderExpr = 'r.id';
        }
    }

    return [
        'joins' => implode(' ', $joins),
        'types' => $joinTypes . $types,
        'params' => array_merge($joinParams, $params),
        'where' => $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '',
        'order' => 'ORDER BY ' . $orderExpr . ' ' . $dirSql . ', r.id ' . $dirSql,
    ];
}

/* ============================================================
 * Cell rendering
 * ========================================================== */

/**
 * Render one value row as ['v' => raw-for-edit, 'd' => display].
 * All values are canonical; display strings are escaped on the client.
 */
function ca_cell_render(array $f, ?array $v): array
{
    $type = (string) $f['field_type'];
    if ($v === null) {
        return ['v' => '', 'd' => ''];
    }
    $text = $v['value_text'] ?? null;
    $num = $v['value_number'] ?? null;
    $dt = $v['value_date'] ?? null;

    if ($type === 'number') {
        return ['v' => $num !== null ? ca_strip_decimals((string) $num) : '', 'd' => $num !== null ? ca_format_number($num) : ''];
    }
    if ($type === 'money') {
        return ['v' => $num !== null ? ca_strip_decimals((string) $num) : '', 'd' => $num !== null ? ca_format_money($num) : ''];
    }
    if ($type === 'date') {
        return ['v' => $dt !== null ? (string) $dt : '', 'd' => $dt !== null ? ca_jalali_display($dt) : ''];
    }
    if ($type === 'yesno') {
        return [
            'v' => $text !== null ? (string) $text : '',
            'd' => $text === '1' ? 'بله' : ($text === '0' ? 'خیر' : ''),
        ];
    }
    return ['v' => $text !== null ? (string) $text : '', 'd' => $text !== null ? (string) $text : ''];
}

/** Canonical numeric string without insignificant decimals ("125.00" → "125"). */
function ca_strip_decimals(string $s): string
{
    $parts = explode('.', $s);
    if (count($parts) === 2) {
        $intPart = $parts[0];
        $frac = $parts[1];
        $frac = preg_replace('/0+$/', '', $frac);
        if ($frac === '') {
            return $intPart;
        }
        return $intPart . '.' . $frac;
    }
    return $s;
}

/**
 * Paginated, filtered, sorted record list with totals.
 *
 * The totals always cover the ENTIRE filtered result set (never only the
 * current page). Only Number/Money fields with include_in_totals = 1 take
 * part. Filters and sort keys are validated against server-side metadata.
 *
 * opts keys: category_id, page, per_page, search, filters (map), sort, dir,
 *            rep_field_id (0 = none), date_from, date_to (monthly range).
 */
function ca_records_result(mysqli $m, array $opts): array
{
    $categoryId = ca_parse_id($opts['category_id'] ?? 0);
    $category = ca_cat_get($m, $categoryId);
    if ($category === null) {
        return ['ok' => false, 'message' => 'دسته‌بندی یافت نشد.'];
    }

    $page = ca_int_clamp($opts['page'] ?? 1, 1, 2000000, 1);
    $perPage = ca_int_clamp($opts['per_page'] ?? 10, 1, 200, 10);
    $search = trim((string) ($opts['search'] ?? ''));
    $filters = is_array($opts['filters'] ?? null) ? (array) $opts['filters'] : [];
    $sort = trim((string) ($opts['sort'] ?? '__id'));
    $dir = trim((string) ($opts['dir'] ?? 'desc')) === 'desc' ? 'desc' : 'asc';
    $repFieldId = ca_parse_id($opts['rep_field_id'] ?? 0);
    $dateRange = [];
    if (ca_valid_iso_date($opts['date_from'] ?? null) && ca_valid_iso_date($opts['date_to'] ?? null)) {
        $dateRange = ['from' => (string) $opts['date_from'], 'to' => (string) $opts['date_to']];
    }

    $fieldsMap = ca_fields_map($m, $categoryId);
    $parts = ca_build_record_query_parts($m, $categoryId, $search, $filters, $sort, $dir, $fieldsMap, $repFieldId, $dateRange);
    $types = (string) $parts['types'];
    $params = (array) $parts['params'];

    /* ---- total matching records (whole filtered set) ---- */
    $total = 0;
    $stmt = $m->prepare('SELECT COUNT(*) AS c FROM current_affairs_records r ' . $parts['joins'] . ' ' . $parts['where']);
    if ($stmt !== null) {
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if ($res !== null && ($row = $res->fetch_assoc())) {
            $total = (int) $row['c'];
            $res->free();
        }
    }

    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    /* ---- page rows ---- */
    $pageIds = [];
    $createdById = [];
    $sql = 'SELECT r.id, r.created_at, r.updated_at FROM current_affairs_records r '
        . $parts['joins'] . ' ' . $parts['where'] . ' ' . $parts['order'] . ' LIMIT ? OFFSET ?';
    $stmt = $m->prepare($sql);
    if ($stmt !== null) {
        $dataParams = $params;
        $dataParams[] = $perPage;
        $dataParams[] = $offset;
        $stmt->bind_param($types . 'ii', ...$dataParams);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if ($res !== null) {
            while ($row = $res->fetch_assoc()) {
                $rid = (int) $row['id'];
                $pageIds[] = $rid;
                $createdById[$rid] = ['created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
            }
            $res->free();
        }
    }

/* ---- values of the page records ---- */
    $valuesByRecord = [];
    if ($pageIds !== []) {
        $placeholders = '';
        $typesIn = '';
        for ($vi = 0; $vi < count($pageIds); $vi++) {
            if ($placeholders !== '') {
                $placeholders .= ',';
            }
            $placeholders .= '?';
            $typesIn .= 'i';
        }
        $stmt = $m->prepare(
            'SELECT record_id, field_id, value_text, value_number, value_date '
            . 'FROM current_affairs_values WHERE record_id IN (' . $placeholders . ')'
        );
        $stmt->bind_param($typesIn, ...$pageIds);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if ($res !== null) {
            while ($v = $res->fetch_assoc()) {
                $rid = (int) $v['record_id'];
                if (!is_array($valuesByRecord[$rid] ?? null)) {
                    $valuesByRecord[$rid] = [];
                }
                $valuesByRecord[$rid][(int) $v['field_id']] = $v;
            }
            $res->free();
        }
    }

    /* ---- totals over the ENTIRE filtered set (not just the page) ---- */
    $totals = [];
    $totalsSql = 'SELECT v.field_id AS fid, f.label AS flabel, COALESCE(SUM(v.value_number), 0) AS s '
        . 'FROM current_affairs_records r ' . $parts['joins']
        . ' JOIN current_affairs_values v ON v.record_id = r.id '
        . ' JOIN current_affairs_fields f ON f.id = v.field_id '
        . $parts['where']
        . ' AND f.category_id = ? AND f.include_in_totals = 1 '
        . ' AND f.field_type IN (\'number\',\'money\') AND v.value_number IS NOT NULL '
        . ' GROUP BY v.field_id, f.label ORDER BY f.sort_order ASC, f.id ASC';
    $stmt = $m->prepare($totalsSql);
    if ($stmt !== null) {
        $totalsParams = $params;
        $totalsParams[] = $categoryId;
        $stmt->bind_param($types . 'i', ...$totalsParams);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if ($res !== null) {
            while ($row = $res->fetch_assoc()) {
                $totals[] = [
                    'field_id' => (int) $row['fid'],
                    'label' => (string) $row['flabel'],
                    'value' => ca_format_number($row['s']),
                ];
            }
            $res->free();
        }
    }

    /* ---- display columns (show_in_table active fields; fallback: all
     * active fields when none is marked for the table) ---- */
    $activeFields = [];
    foreach ($fieldsMap as $fid => $f) {
        if (ca_flag_to_bool($f['is_active'] ?? null)) {
            $activeFields[] = $f;
        }
    }
    $showFields = [];
    foreach ($activeFields as $f) {
        if (ca_flag_to_bool($f['show_in_table'] ?? null)) {
            $showFields[] = $f;
        }
    }
    if ($showFields === []) {
        $showFields = $activeFields;
    }

    $columns = [
        ['key' => '__no', 'label' => 'ردیف', 'sortable' => false, 'type' => 'no'],
        ['key' => '__created', 'label' => 'تاریخ ثبت', 'sortable' => true, 'type' => 'created'],
    ];
    foreach ($showFields as $f) {
        $columns[] = ['key' => 'f' . (int) $f['id'], 'label' => (string) $f['label'], 'sortable' => true, 'type' => (string) $f['field_type']];
    }
    $columns[] = ['key' => '__actions', 'label' => 'عملیات', 'sortable' => false, 'type' => 'actions'];

    /* ---- page rows ---- */
    $rows = [];
    $idx = 0;
    foreach ($pageIds as $rid) {
        $idx++;
        $created = $createdById[$rid]['created_at'] ?? '';
        $rowArr = [];
        $rowArr['id'] = $rid;
        $rowArr['__no'] = $offset + $idx;
        $rowArr['__created'] = ca_dt_display($created);
        $rowArr['__created_raw'] = (string) $created;
        foreach ($showFields as $f) {
            $fid = (int) $f['id'];
            $vrow = is_array($valuesByRecord[$rid] ?? null) ? ($valuesByRecord[$rid][$fid] ?? null) : null;
            $rowArr['f' . $fid] = ca_cell_render($f, $vrow);
        }
        $rows[] = $rowArr;
    }

    /* ---- filter metadata (active filterable fields only) ---- */
    $filtersMeta = [];
    foreach ($fieldsMap as $fid => $f) {
        if (!ca_flag_to_bool($f['is_active'] ?? null) || !ca_flag_to_bool($f['is_filterable'] ?? null)) {
            continue;
        }
        $meta = ['id' => $fid, 'label' => (string) $f['label'], 'type' => (string) $f['field_type']];
        if ((string) $f['field_type'] === 'select') {
            $opts = [];
            if (is_array($f['options'] ?? null)) {
                foreach ($f['options'] as $o) {
                    $opts[] = (string) $o['option_value'];
                }
            }
            $meta['options'] = $opts;
        }
        $filtersMeta[] = $meta;
    }

    /* ---- active fields for the dynamic record form ---- */
    $formFields = [];
    foreach ($activeFields as $f) {
        $opts = [];
        if ((string) $f['field_type'] === 'select' && is_array($f['options'] ?? null)) {
            foreach ($f['options'] as $o) {
                $opts[] = (string) $o['option_value'];
            }
        }
        $formFields[] = [
            'id' => (int) $f['id'],
            'name' => (string) $f['name'],
            'label' => (string) $f['label'],
            'field_type' => (string) $f['field_type'],
            'is_required' => ca_flag_to_bool($f['is_required'] ?? null) ? 1 : 0,
            'options' => $opts,
        ];
    }

    $rep = ca_reporting_field($m, $categoryId);

    return [
        'ok' => true,
        'category_id' => $categoryId,
        'category_name' => (string) $category['name'],
        'columns' => $columns,
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'range_start' => $total === 0 ? 0 : $offset + 1,
        'range_end' => min($page * $perPage, $total),
        'sort' => $sort,
        'dir' => $dir,
        'totals' => $totals,
        'filters' => $filtersMeta,
        'fields' => $formFields,
        'reporting' => $rep !== null ? ['id' => (int) $rep['id'], 'label' => (string) $rep['label']] : null,
    ];
}

/** Persian date-time display for a MySQL datetime column. */
function ca_dt_display($dt): string
{
    if ($dt === null || $dt === '') {
        return '-';
    }
    $ts = strtotime((string) $dt);
    if ($ts === false) {
        return '-';
    }
    return jdate('Y/m/d', $ts) . ' ' . date('H:i', $ts);
}

/* ============================================================
 * Monthly Reporting
 * ========================================================== */

/** Persian month names (index 1..12). */
function ca_jalali_month_names(): array
{
    return [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ];
}

/**
 * Monthly report. Requires exactly one valid, active, explicitly designated
 * Reporting Date field for the category — it is never guessed or inferred.
 * The count and totals cover the WHOLE month; only the detail list is
 * server-side paginated (10 records per page by default).
 */
function ca_monthly_report(mysqli $m, array $opts): array
{
    $categoryId = ca_parse_id($opts['category_id'] ?? 0);
    $category = ca_cat_get($m, $categoryId);
    if ($category === null) {
        return ['ok' => false, 'message' => 'دسته‌بندی یافت نشد.'];
    }

    list($curJY, $curJM, $curJD) = gregorian_to_jalali((int) date('Y'), (int) date('m'), (int) date('d'));
    $jy = ca_int_clamp($opts['year'] ?? $curJY, 1300, 1600, $curJY);
    $jm = ca_int_clamp($opts['month'] ?? $curJM, 1, 12, $curJM);

    $repField = ca_reporting_field($m, $categoryId);
    if ($repField === null) {
        return [
            'ok' => false,
            'no_reporting_date' => true,
            'message' => 'برای این دسته‌بندی هیچ فیلد تاریخ فعالی به‌عنوان «تاریخ گزارش‌گیری» تعیین نشده است. ابتدا از بخش «فیلدها» یک فیلد تاریخ فعال را به‌عنوان تاریخ گزارش‌گیری تعیین کنید.',
        ];
    }

    $bounds = ca_jalali_month_bounds($jy, $jm);
    $months = ca_jalali_month_names();

    $result = ca_records_result($m, [
        'category_id' => $categoryId,
        'page' => (int) ($opts['page'] ?? 1),
        'per_page' => (int) ($opts['per_page'] ?? 10),
        'search' => '',
        'filters' => [],
        'sort' => (string) ($opts['sort'] ?? '__id'),
        'dir' => (string) ($opts['dir'] ?? 'desc'),
        'rep_field_id' => (int) $repField['id'],
        'date_from' => $bounds['start'],
        'date_to' => $bounds['end'],
    ]);

    $result['report_mode'] = 'monthly';
    $result['year'] = $jy;
    $result['month'] = $jm;
    $result['month_name'] = (string) ($months[$jm] ?? '');
    $result['reporting_label'] = (string) $repField['label'];
    $result['range_start_iso'] = $bounds['start'];
    $result['range_end_iso'] = $bounds['end'];
    $result['range_display'] = jdate('j F Y', strtotime($bounds['start']))
        . ' تا ' . jdate('j F Y', strtotime($bounds['end']));

    return $result;
}

/* ============================================================
 * Excel export helpers (complete datasets, never just the page)
 * ========================================================== */

/**
 * Complete export dataset for one category (optionally restricted by the
 * Monthly Reporting range). Includes every field — even inactive ones — so
 * historical values are never lost. Numeric/money cells are raw numeric
 * strings; money column labels get ' (ریال)' appended.
 */
function ca_export_category(mysqli $m, array $opts): array
{
    $categoryId = ca_parse_id($opts['category_id'] ?? 0);
    $category = ca_cat_get($m, $categoryId);
    if ($category === null) {
        return ['ok' => false, 'message' => 'دسته‌بندی یافت نشد.'];
    }

    $search = trim((string) ($opts['search'] ?? ''));
    $filters = is_array($opts['filters'] ?? null) ? (array) $opts['filters'] : [];
    $repFieldId = ca_parse_id($opts['rep_field_id'] ?? 0);
    $dateRange = [];
    if (ca_valid_iso_date($opts['date_from'] ?? null) && ca_valid_iso_date($opts['date_to'] ?? null)) {
        $dateRange = ['from' => (string) $opts['date_from'], 'to' => (string) $opts['date_to']];
    }

    $fieldsOrdered = ca_record_fields($m, $categoryId, false);
    $fieldsMap = [];
    foreach ($fieldsOrdered as $f) {
        $fieldsMap[(int) $f['id']] = $f;
    }

    $parts = ca_build_record_query_parts($m, $categoryId, $search, $filters, '__id', 'asc', $fieldsMap, $repFieldId, $dateRange);
    $types = (string) $parts['types'];
    $params = (array) $parts['params'];

    /* All matching ids (no pagination — the export is always complete). */
    $ids = [];
    $createdById = [];
    $sql = 'SELECT r.id, r.created_at, r.updated_at FROM current_affairs_records r '
        . $parts['joins'] . ' ' . $parts['where'] . ' ' . $parts['order'];
    $stmt = $m->prepare($sql);
    if ($stmt !== null) {
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if ($res !== null) {
            while ($row = $res->fetch_assoc()) {
                $rid = (int) $row['id'];
                $ids[] = $rid;
                $createdById[$rid] = $row['created_at'] ?? '';
            }
            $res->free();
        }
    }

    $valuesByRecord = [];
    if ($ids !== []) {
        $placeholders = '';
        $typesIn = '';
        for ($vi = 0; $vi < count($ids); $vi++) {
            if ($placeholders !== '') {
                $placeholders .= ',';
            }
            $placeholders .= '?';
            $typesIn .= 'i';
        }
        $stmt = $m->prepare(
            'SELECT record_id, field_id, value_text, value_number, value_date '
            . 'FROM current_affairs_values WHERE record_id IN (' . $placeholders . ')'
        );
        $stmt->bind_param($typesIn, ...$ids);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if ($res !== null) {
            while ($v = $res->fetch_assoc()) {
                $rid = (int) $v['record_id'];
                if (!is_array($valuesByRecord[$rid] ?? null)) {
                    $valuesByRecord[$rid] = [];
                }
                $valuesByRecord[$rid][(int) $v['field_id']] = $v;
            }
            $res->free();
        }
    }

    /* Columns: row / id / created + every field (name key → Persian label). */
    $columns = [
        'row' => 'ردیف',
        '_id' => 'شناسه رکورد',
        '_created' => 'تاریخ ثبت',
    ];
    $numberKeys = [];
    foreach ($fieldsOrdered as $f) {
        $name = (string) $f['name'];
        $label = (string) $f['label'];
        if ((string) $f['field_type'] === 'money') {
            $label .= ' (ریال)';
        }
        $columns[$name] = $label;
        if (ca_type_is_numeric((string) $f['field_type'])) {
            $numberKeys[] = $name;
        }
    }

    $rows = [];
    $rowNo = 0;
    foreach ($ids as $rid) {
        $rowNo++;
        $out = [];
        $out['row'] = $rowNo;
        $out['_id'] = $rid;
        $out['_created'] = ca_jalali_display($createdById[$rid] ?? '');
        foreach ($fieldsOrdered as $f) {
            $vrow = is_array($valuesByRecord[$rid] ?? null) ? ($valuesByRecord[$rid][(int) $f['id']] ?? null) : null;
            $out[(string) $f['name']] = ca_export_value($f, $vrow);
        }
        $rows[] = $out;
    }

    $title = 'امور جاری — ' . (string) $category['name'];
    if ($repFieldId > 0 && $dateRange !== []) {
        $title .= ' (' . ca_jalali_display((string) $dateRange['from']) . ' تا ' . ca_jalali_display((string) $dateRange['to']) . ')';
    }

    return ['ok' => true, 'title' => $title, 'columns' => $columns, 'rows' => $rows, 'number_keys' => $numberKeys];
}

/** Single cell value for an export row. */
function ca_field_values_counts(mysqli $m, array $fieldIds): array
{
    $counts = [];
    if ($fieldIds === []) {
        return $counts;
    }
    $fieldIds = array_values(array_map('intval', $fieldIds));
    $placeholders = '';
    $typesIn = '';
    for ($vi = 0; $vi < count($fieldIds); $vi++) {
        if ($placeholders !== '') {
            $placeholders .= ',';
        }
        $placeholders .= '?';
        $typesIn .= 'i';
    }
    $stmt = $m->prepare(
        'SELECT field_id, COUNT(*) AS c FROM current_affairs_values '
        . 'WHERE field_id IN (' . $placeholders . ') GROUP BY field_id'
    );
    $stmt->bind_param($typesIn, ...$fieldIds);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res !== null) {
        while ($row = $res->fetch_assoc()) {
            $counts[(int) $row['field_id']] = (int) $row['c'];
        }
        $res->free();
    }
    return $counts;
}

function ca_export_value(array $f, ?array $v): string
{
    $type = (string) $f['field_type'];
    if ($v === null) {
        return '';
    }
    if ($type === 'number' || $type === 'money') {
        return $v['value_number'] !== null ? ca_strip_decimals((string) $v['value_number']) : '';
    }
    if ($type === 'date') {
        return $v['value_date'] !== null ? ca_jalali_display($v['value_date']) : '';
    }
    if ($type === 'yesno') {
        $t = $v['value_text'] ?? null;
        return $t === '1' ? 'بله' : ($t === '0' ? 'خیر' : '');
    }
    return $v['value_text'] !== null ? (string) $v['value_text'] : '';
}

/**
 * All-category export: a matrix whose columns are the union of every field
 * (keyed by the unique internal field name); records of categories lacking a
 * column are left blank. Numeric/money columns keep raw numeric values.
 */
function ca_export_all(mysqli $m): array
{
    $categories = [];
    $catRes = $m->query('SELECT id, name FROM current_affairs_categories ORDER BY is_active DESC, id ASC');
    if ($catRes !== null) {
        while ($row = $catRes->fetch_assoc()) {
            $categories[] = $row;
        }
        $catRes->free();
    }

    $columns = [
        'row' => 'ردیف',
        '_category' => 'دسته‌بندی',
        '_id' => 'شناسه رکورد',
        '_created' => 'تاریخ ثبت',
    ];
    $numberKeys = [];
    $colByFieldName = [];
    $fieldsByCat = [];
    foreach ($categories as $cat) {
        $fieldsOrdered = ca_record_fields($m, (int) $cat['id'], false);
        $fieldsByCat[(int) $cat['id']] = $fieldsOrdered;
        foreach ($fieldsOrdered as $f) {
            $name = (string) $f['name'];
            if (isset($colByFieldName[$name])) {
                continue;
            }
            $colByFieldName[$name] = true;
            $label = (string) $f['label'];
            if ((string) $f['field_type'] === 'money') {
                $label .= ' (ریال)';
            }
            $columns[$name] = $label;
            if (ca_type_is_numeric((string) $f['field_type'])) {
                $numberKeys[] = $name;
            }
        }
    }

    $rows = [];
    $rowNo = 0;
    foreach ($categories as $cat) {
        $catId = (int) $cat['id'];
        $fieldsMap = [];
        foreach ($fieldsByCat[$catId] as $f) {
            $fieldsMap[(int) $f['id']] = $f;
        }
        $parts = ca_build_record_query_parts($m, $catId, '', [], '__id', 'asc', $fieldsMap);
        $types = (string) $parts['types'];
        $params = (array) $parts['params'];
        $sql = 'SELECT r.id, r.created_at FROM current_affairs_records r ' . $parts['joins'] . ' ' . $parts['where'] . ' ' . $parts['order'];
        $ids = [];
        $createdById = [];
        $stmt = $m->prepare($sql);
        if ($stmt !== null) {
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();
            if ($res !== null) {
                while ($row = $res->fetch_assoc()) {
                    $rid = (int) $row['id'];
                    $ids[] = $rid;
                    $createdById[$rid] = $row['created_at'] ?? '';
                }
                $res->free();
            }
        }
        foreach ($ids as $rid) {
            $stmt = $m->prepare('SELECT field_id, value_text, value_number, value_date FROM current_affairs_values WHERE record_id = ?');
            $stmt->bind_param('i', $rid);
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();
            $valMap = [];
            if ($res !== null) {
                while ($v = $res->fetch_assoc()) {
                    $valMap[(int) $v['field_id']] = $v;
                }
                $res->free();
            }
            $rowNo++;
            $out = [];
            $out['row'] = $rowNo;
            $out['_category'] = (string) $cat['name'];
            $out['_id'] = $rid;
            $out['_created'] = ca_jalali_display($createdById[$rid] ?? '');
            foreach ($fieldsByCat[$catId] as $f) {
                $out[(string) $f['name']] = ca_export_value($f, $valMap[(int) $f['id']] ?? null);
            }
            $rows[] = $out;
        }
    }

    return ['ok' => true, 'title' => 'امور جاری — تمام دسته‌بندی‌ها', 'columns' => $columns, 'rows' => $rows, 'number_keys' => $numberKeys];
}

/**
 * Monthly export: the complete monthly matching dataset (never just the
 * current detail page). Requires a valid Reporting Date.
 */
function ca_export_monthly(mysqli $m, int $categoryId, int $jy, int $jm): array
{
    $repField = ca_reporting_field($m, $categoryId);
    if ($repField === null) {
        return [
            'ok' => false,
            'no_reporting_date' => true,
            'message' => 'برای این دسته‌بندی تاریخ گزارش‌گیری معتبر تنظیم نشده است.',
        ];
    }
    $bounds = ca_jalali_month_bounds($jy, $jm);
    $res = ca_export_category($m, [
        'category_id' => $categoryId,
        'search' => '',
        'filters' => [],
        'rep_field_id' => (int) $repField['id'],
        'date_from' => $bounds['start'],
        'date_to' => $bounds['end'],
    ]);
    if (!$res['ok']) {
        return $res;
    }
    $months = ca_jalali_month_names();
    $res['title'] = 'گزارش ماهانه امور جاری — ' . $jy . '/' . str_pad((string) $jm, 2, '0', STR_PAD_LEFT) . ' '
        . ($months[$jm] ?? '') . ' — ' . (string) $res['title'];
    return $res;
}