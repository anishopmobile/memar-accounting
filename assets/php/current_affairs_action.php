<?php
/**
 * current_affairs_action.php — Current Affairs AJAX endpoint.
 *
 * READ actions are GET (authentication via boot.php; no CSRF needed — they
 * change nothing). All mutations are POST and require the existing CSRF
 * token. Every id/flag/filter/sort is validated server-side; all SQL uses
 * prepared statements. Errors never leak SQL/stack details to the browser.
 */
declare(strict_types=1);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/current_affairs/ca_loader.php';

header('Content-Type: application/json; charset=UTF-8');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = trim((string) ($_GET['action'] ?? ($_POST['action'] ?? '')));

/* ============================================================
 * READ actions (GET)
 * ========================================================== */
if ($method === 'GET') {
    if ($action === 'cats') {
        respond_json(true, '', ['categories' => ca_cat_list($mysqli)]);
    }

    if ($action === 'fields') {
        $categoryId = ca_parse_id($_GET['category_id'] ?? '');
        $fields = $categoryId > 0 ? ca_record_fields($mysqli, $categoryId, false) : [];

        /* Values-per-field (for the UI: a field with historical values can
         * no longer change its type). Single grouped query, never trusted
         * from the client. */
        $fieldIds = [];
        foreach ($fields as $f) {
            $fieldIds[] = (int) $f['id'];
        }
        $countByField = ca_field_values_counts($mysqli, $fieldIds);
        foreach ($fields as &$f) {
            $f['values_count'] = (int) ($countByField[(int) $f['id']] ?? 0);
        }

        respond_json(true, '', [
            'categories' => ca_cat_list($mysqli),
            'category_id' => $categoryId,
            'fields' => $fields,
        ]);
    }

    if ($action === 'record_get') {
        $recordId = ca_parse_id($_GET['id'] ?? '');
        if ($recordId <= 0) {
            respond_error('شناسه رکورد نامعتبر است.');
        }
        $record = ca_record_get($mysqli, $recordId);
        if ($record === null) {
            respond_error('رکورد یافت نشد.', 404);
        }
        respond_json(true, '', ['record' => $record]);
    }

    if ($action === 'records') {
        $filters = [];
        $filtersRaw = (string) ($_GET['filters'] ?? '');
        if ($filtersRaw !== '') {
            $decoded = json_decode($filtersRaw, true);
            if (is_array($decoded)) {
                $filters = (array) $decoded;
            }
        }
        $res = ca_records_result($mysqli, [
            'category_id' => ca_parse_id($_GET['category_id'] ?? ''),
            'page' => (int) ($_GET['page'] ?? 1),
            'per_page' => (int) ($_GET['per_page'] ?? 10),
            'search' => (string) ($_GET['search'] ?? ''),
            'filters' => $filters,
            'sort' => (string) ($_GET['sort'] ?? '__id'),
            'dir' => (string) ($_GET['dir'] ?? 'desc'),
        ]);
        if (!$res['ok']) {
            respond_error($res['message'], 400);
        }
        respond_json(true, '', $res);
    }

    if ($action === 'monthly') {
        $res = ca_monthly_report($mysqli, [
            'category_id' => ca_parse_id($_GET['category_id'] ?? ''),
            'year' => (int) ($_GET['year'] ?? 0),
            'month' => (int) ($_GET['month'] ?? 0),
            'page' => (int) ($_GET['page'] ?? 1),
            'per_page' => (int) ($_GET['per_page'] ?? 10),
            'sort' => (string) ($_GET['sort'] ?? '__id'),
            'dir' => (string) ($_GET['dir'] ?? 'desc'),
        ]);
        if (!$res['ok']) {
            respond_json(false, $res['message'], [
                'no_reporting_date' => (bool) ($res['no_reporting_date'] ?? false),
            ]);
        }
        respond_json(true, '', $res);
    }

    respond_error('عملیات نامعتبر است.', 404);
}

/* ============================================================
 * Mutations (POST + CSRF)
 * ========================================================== */
require_csrf_or_fail();

if ($action === 'cat_save') {
    $id = ca_parse_id($_POST['id'] ?? '');
    if ($id === 0 && trim((string) ($_POST['id'] ?? '')) !== '') {
        respond_error('شناسه دسته‌بندی نامعتبر است.');
    }
    $res = ca_cat_save(
        $mysqli,
        $id > 0 ? $id : null,
        trim((string) ($_POST['name'] ?? '')),
        trim((string) ($_POST['description'] ?? ''))
    );
    if (!$res['ok']) {
        respond_json(false, $res['message'], null, 422);
    }
    respond_json(true, $res['message'], $res['data']);
}

if ($action === 'cat_toggle') {
    $id = ca_parse_id($_POST['id'] ?? '');
    $active = ca_flag_to_bool($_POST['is_active'] ?? '');
    $res = ca_cat_set_active($mysqli, $id, $active);
    if (!$res['ok']) {
        respond_json(false, $res['message'], null, 422);
    }
    respond_json(true, $res['message'], $res['data']);
}

if ($action === 'cat_delete') {
    $id = ca_parse_id($_POST['id'] ?? '');
    $res = ca_cat_delete($mysqli, $id);
    if (!$res['ok']) {
        respond_json(false, $res['message'], null, 422);
    }
    respond_json(true, $res['message'], $res['data']);
}

if ($action === 'field_save') {
    $options = [];
    $optionsRaw = (string) ($_POST['options'] ?? '[]');
    if ($optionsRaw !== '') {
        $decoded = json_decode($optionsRaw, true);
        if (is_array($decoded)) {
            $options = (array) $decoded;
        }
    }
    $id = ca_parse_id($_POST['id'] ?? '');
    if ($id === 0 && trim((string) ($_POST['id'] ?? '')) !== '') {
        respond_error('شناسه فیلد نامعتبر است.');
    }
    $res = ca_field_save(
        $mysqli,
        $id > 0 ? $id : null,
        ca_parse_id($_POST['category_id'] ?? ''),
        trim((string) ($_POST['label'] ?? '')),
        trim((string) ($_POST['field_type'] ?? 'text')),
        ca_flag_to_bool($_POST['is_required'] ?? ''),
        ca_flag_to_bool($_POST['show_in_table'] ?? ''),
        ca_flag_to_bool($_POST['is_filterable'] ?? ''),
        ca_flag_to_bool($_POST['include_in_totals'] ?? ''),
        ca_int_clamp($_POST['sort_order'] ?? 0, -100000, 100000, 0),
        ca_flag_to_bool($_POST['is_active'] ?? ''),
        ca_flag_to_bool($_POST['is_reporting_date'] ?? ''),
        $options
    );
    if (!$res['ok']) {
        respond_json(false, $res['message'], null, 422);
    }
    respond_json(true, $res['message'], $res['data']);
}
if ($action === 'field_toggle') {
    $id = ca_parse_id($_POST['id'] ?? '');
    $active = ca_flag_to_bool($_POST['is_active'] ?? '');
    $res = ca_field_set_active($mysqli, $id, $active);
    if (!$res['ok']) {
        respond_json(false, $res['message'], null, 422);
    }
    respond_json(true, $res['message'], $res['data']);
}

if ($action === 'field_delete') {
    $id = ca_parse_id($_POST['id'] ?? '');
    $res = ca_field_delete($mysqli, $id);
    if (!$res['ok']) {
        respond_json(false, $res['message'], null, 422);
    }
    respond_json(true, $res['message'], $res['data']);
}

if ($action === 'set_reporting_date') {
    $categoryId = ca_parse_id($_POST['category_id'] ?? '');
    $fieldId = ca_parse_id($_POST['field_id'] ?? '');
    $res = ca_set_reporting_date($mysqli, $categoryId, $fieldId);
    if (!$res['ok']) {
        respond_json(false, $res['message'], null, 422);
    }
    respond_json(true, $res['message'], $res['data']);
}

if ($action === 'clear_reporting_date') {
    $categoryId = ca_parse_id($_POST['category_id'] ?? '');
    $fieldId = ca_parse_id($_POST['field_id'] ?? '');
    $res = ca_clear_reporting_date($mysqli, $categoryId, $fieldId);
    if (!$res['ok']) {
        respond_json(false, $res['message'], null, 422);
    }
    respond_json(true, $res['message'], $res['data']);
}

if ($action === 'record_save') {
    $values = [];
    $valuesRaw = (string) ($_POST['values'] ?? '{}');
    if ($valuesRaw !== '') {
        $decoded = json_decode($valuesRaw, true);
        if (is_array($decoded)) {
            $values = (array) $decoded;
        }
    }
    $id = ca_parse_id($_POST['id'] ?? '');
    if ($id === 0 && trim((string) ($_POST['id'] ?? '')) !== '') {
        respond_error('شناسه رکورد نامعتبر است.');
    }
    $res = ca_record_save(
        $mysqli,
        $id > 0 ? $id : null,
        ca_parse_id($_POST['category_id'] ?? ''),
        $values
    );
    if (!$res['ok']) {
        respond_json(false, $res['message'], null, 422);
    }
    respond_json(true, $res['message'], ['id' => (int) ($res['data']['id'] ?? 0)]);
}

if ($action === 'record_delete') {
    $id = ca_parse_id($_POST['id'] ?? '');
    $res = ca_record_delete($mysqli, $id);
    if (!$res['ok']) {
        respond_json(false, $res['message'], null, 422);
    }
    respond_json(true, $res['message'], $res['data']);
}

respond_error('عملیات نامعتبر است.', 404);