<?php
/**
 * Central product media: filesystem paths, validation, read-only lookup and
 * transactional relationships. Never store an uploaded client path in SQL.
 */
declare(strict_types=1);

function media_storage_root(): string
{
    $configured = getenv('MEMAR_MEDIA_STORAGE');
    $root = is_string($configured) && $configured !== ''
        ? $configured
        : dirname(__DIR__, 3) . '/memar_media';
    if ($root[0] !== '/' && preg_match('/^[A-Za-z]:[\\\\\\/]/', $root) !== 1) {
        throw new RuntimeException('پوشه ذخیره رسانه باید مسیر مطلق سرور باشد.');
    }
    return rtrim($root, '/\\\\');
}

function media_directory(bool $create = false): string
{
    $dir = media_storage_root();
    if ($create && !is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('ایجاد پوشه خصوصی رسانه‌ها ممکن نیست؛ تنظیمات هاست را بررسی کنید.');
    }
    if ($create && !is_writable($dir)) {
        throw new RuntimeException('پوشه خصوصی رسانه‌ها قابل نوشتن نیست.');
    }
    return $dir;
}

function media_absolute_path(string $path): ?string
{
    // Restrict *all* storage lookups to server-generated relative basenames.
    if (!preg_match('/^(original|thumb)\\/[a-f0-9]{32}\\.(?:jpg|png|webp)$/D', $path)) {
        return null;
    }
    $root = media_directory();
    $full = $root . '/' . $path;
    $resolved = realpath($full);
    $realRoot = realpath($root);
    if ($resolved === false || $realRoot === false || !is_file($resolved)
        || !str_starts_with($resolved, $realRoot . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $resolved;
}

function media_image_url(int $id, string $size = 'thumb'): string
{
    return 'assets/php/media_file.php?id=' . $id . '&size=' . ($size === 'original' ? 'original' : 'thumb');
}

function media_default_url(): string
{
    return 'assets/img/product-placeholder.svg';
}

function media_get(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM media WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $r = $stmt->get_result();
    $item = $r ? $r->fetch_assoc() : null;
    $stmt->close();
    return $item ?: null;
}

/** Single batch query; first item by explicit sort order is always primary. */
function media_primary_map(mysqli $db, array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn($x) => $x > 0)));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT pm.product_id, m.id, m.thumbnail_path, m.original_path
           FROM product_media pm JOIN media m ON m.id = pm.media_id
          WHERE pm.product_id IN ($placeholders)
          ORDER BY pm.product_id, pm.sort_order, pm.media_id"
    );
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $map = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $pid = (int) $row['product_id'];
        if (!isset($map[$pid])) {
            $map[$pid] = $row;
        }
    }
    $stmt->close();
    return $map;
}

function media_product_list(mysqli $db, int $productId): array
{
    $stmt = $db->prepare(
        'SELECT m.id, m.original_filename, m.file_size, m.width, m.height,
                pm.sort_order
         FROM product_media pm JOIN media m ON m.id=pm.media_id
         WHERE pm.product_id=? ORDER BY pm.sort_order,pm.media_id'
    );
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $row['url'] = media_image_url((int) $row['id']);
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function media_decode_ids($raw): array
{
    if (!is_string($raw)) {
        throw new InvalidArgumentException('فهرست تصاویر نامعتبر است.');
    }
    $ids = json_decode($raw, true);
    if (!is_array($ids) || count($ids) > 10 || array_values($ids) !== $ids) {
        throw new InvalidArgumentException('حداکثر ۱۰ تصویر برای هر کالا مجاز است.');
    }
    $normalized = [];
    foreach ($ids as $id) {
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || (string) (int) $id !== (string) $id) {
            throw new InvalidArgumentException('شناسه تصویر نامعتبر است.');
        }
        $id = (int) $id;
        if (in_array($id, $normalized, true)) {
            throw new InvalidArgumentException('تصویر تکراری انتخاب شده است.');
        }
        $normalized[] = $id;
    }
    return $normalized;
}

/** Caller MUST have an active transaction. No writes to products/prices/stock. */
function media_sync_product(mysqli $db, int $productId, string $json): void
{
    $ids = media_decode_ids($json);
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM media WHERE id IN ($in)");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int) ($row['c'] ?? 0) !== count($ids)) {
            throw new InvalidArgumentException('یک یا چند تصویر انتخابی در کتابخانه موجود نیست.');
        }
    }
    $stmt = $db->prepare('DELETE FROM product_media WHERE product_id=?');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO product_media(product_id,media_id,sort_order) VALUES(?,?,?)');
    foreach ($ids as $order => $id) {
        $stmt->bind_param('iii', $productId, $id, $order);
        $stmt->execute();
    }
    $stmt->close();
}

function media_settings(mysqli $db): array
{
    $defaults = [
        'products_list'=>1, 'sales_ui'=>1, 'purchase_ui'=>1,
        'sales_returns_ui'=>1, 'defective_ui'=>1, 'reports_ui'=>0,
        'category_images'=>1, 'pdf_sales'=>0, 'pdf_sales_proforma'=>0,
        'pdf_purchase'=>0, 'pdf_purchase_proforma'=>0, 'pdf_sales_return'=>0
    ];
    $res = $db->query('SELECT * FROM media_display_settings WHERE id=1');
    return array_replace($defaults, $res ? ($res->fetch_assoc() ?: []) : []);
}

function media_pdf_enabled(array $settings, string $type): bool
{
    $fields = [
        'sales_invoice'=>'pdf_sales',
        'sales_proforma'=>'pdf_sales_proforma',
        'purchase_invoice'=>'pdf_purchase',
        'purchase_proforma'=>'pdf_purchase_proforma',
        'sales_return'=>'pdf_sales_return'
    ];
    return isset($fields[$type]) && (int) ($settings[$fields[$type]] ?? 0) === 1;
}

/** Always read from our private disk; never accept a URL for mPDF. */
function media_pdf_items(mysqli $db, array $items, bool $enabled): array
{
    if (!$enabled || !$items) {
        return $items;
    }
    $map = media_primary_map($db, array_column($items, 'product_id'));
    foreach ($items as &$item) {
        $m = $map[(int) ($item['product_id'] ?? 0)] ?? null;
        $item['media_print_path'] = $m ? media_absolute_path((string) $m['thumbnail_path']) : null;
    }
    unset($item);
    return $items;
}
