<?php
/** Authenticated AJAX Media Library endpoints (all mutations require CSRF). */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/media_common.php';

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'list');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Release the session lock so thumbnail streams and search requests run concurrently.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if ($action === 'folders') {
            $folders = [];
            $result = $mysqli->query('SELECT f.id, f.name,
                (SELECT COUNT(*) FROM media m WHERE m.folder_id=f.id) AS media_count
                FROM media_folders f ORDER BY f.name, f.id');
            while ($result && ($row = $result->fetch_assoc())) $folders[] = $row;
            respond_json(true, '', ['folders'=>$folders]);
        }
        if ($action === 'defective') {
            $id = filter_var($_GET['defective_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if (!$id) respond_error('شناسه ثبت معیوب نامعتبر است.', 422);
            respond_json(true, '', ['items'=>media_defective_list($mysqli, (int)$id)]);
        }
        if ($action === 'product') {
            $id = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if (!$id) respond_error('شناسه محصول نامعتبر است.', 422);
            respond_json(true, '', ['items'=>media_product_list($mysqli, (int)$id)]);
        }
        if ($action === 'category') {
            $id = filter_var($_GET['category_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if (!$id) respond_error('شناسه دسته‌بندی نامعتبر است.', 422);
            $stmt = $mysqli->prepare('SELECT media_id FROM category_media WHERE category_id=? LIMIT 1');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            respond_json(true, '', ['media_id'=>(int)($row['media_id'] ?? 0)]);
        }
        if ($action !== 'list') respond_error('عملیات نامعتبر است.', 400);
        $page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
        $perPage = 24;
        $term = trim((string)($_GET['search'] ?? ''));
        if (mb_strlen($term) > 120) $term = mb_substr($term, 0, 120);
        $usage = (string)($_GET['usage'] ?? 'all');
        if (!in_array($usage, ['all', 'used', 'unused'], true)) $usage = 'all';
        $folder = (string)($_GET['folder'] ?? 'all');
        if ($folder !== 'all' && (!ctype_digit($folder) || strlen($folder) > 10)) {
            respond_error('پوشه نامعتبر است.', 422);
        }
        $conditions = [];
        $types = '';
        $values = [];
        if ($folder === '0') {
            $conditions[] = 'm.folder_id IS NULL';
        } elseif ($folder !== 'all') {
            $conditions[] = 'm.folder_id = ?';
            $types .= 'i';
            $values[] = (int)$folder;
        }
        if ($term !== '') {
            $conditions[] = 'm.original_filename LIKE ?';
            $types .= 's';
            $values[] = '%' . $term . '%';
        }
        $usageSql = '(EXISTS (SELECT 1 FROM product_media pm WHERE pm.media_id=m.id)
                         OR EXISTS (SELECT 1 FROM category_media cm WHERE cm.media_id=m.id)
                         OR EXISTS (SELECT 1 FROM defective_media dm WHERE dm.media_id=m.id))';
        if ($usage === 'used') $conditions[] = $usageSql;
        if ($usage === 'unused') $conditions[] = 'NOT ' . $usageSql;
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $count = $mysqli->prepare('SELECT COUNT(*) AS n FROM media m' . $where);
        if ($types !== '') $count->bind_param($types, ...$values);
        $count->execute();
        $total = (int)($count->get_result()->fetch_assoc()['n'] ?? 0);
        $count->close();
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT m.id,m.original_filename,m.folder_id,m.file_size,m.width,m.height,m.created_at,
                (SELECT COUNT(*) FROM product_media pm WHERE pm.media_id=m.id) AS products_count,
                (SELECT COUNT(*) FROM category_media cm WHERE cm.media_id=m.id) AS categories_count,
                (SELECT COUNT(*) FROM defective_media dm WHERE dm.media_id=m.id) AS defective_count
                FROM media m' . $where . ' ORDER BY m.id DESC LIMIT ? OFFSET ?';
        $stmt = $mysqli->prepare($sql);
        $bind = array_merge($values, [$perPage, $offset]);
        $stmt->bind_param($types . 'ii', ...$bind);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $row['url'] = media_image_url((int)$row['id']);
            $row['original_url'] = media_image_url((int)$row['id'], 'original');
            $items[] = $row;
        }
        $stmt->close();
        respond_json(true, '', ['items'=>$items, 'page'=>$page, 'total'=>$total,
            'pages'=>max(1, (int)ceil($total / $perPage))]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond_error('روش درخواست نامعتبر است.', 405);
    require_csrf_or_fail();

    if ($action === 'folder_create') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 140 || preg_match('/[<>\\x00-\\x1F]/u', $name)) {
            respond_error('نام پوشه باید بین ۱ تا ۱۴۰ کاراکتر و بدون کاراکتر غیرمجاز باشد.', 422);
        }
        $stmt = $mysqli->prepare('INSERT INTO media_folders(name) VALUES (?)');
        try {
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
        } catch (mysqli_sql_exception $e) {
            respond_error('این نام پوشه قبلاً استفاده شده یا مجاز نیست.', 409);
        } finally { $stmt->close(); }
        respond_json(true, 'پوشه ایجاد شد.', ['id'=>$id, 'name'=>$name]);
    }
    if ($action === 'media_move') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $folderId = filter_var($_POST['folder_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if (!$id || $folderId === false || $folderId === null) respond_error('تصویر یا پوشه نامعتبر است.', 422);
        if ($folderId > 0) {
            $f = $mysqli->prepare('SELECT id FROM media_folders WHERE id=?');
            $f->bind_param('i',$folderId); $f->execute();
            $found = $f->get_result()->fetch_assoc(); $f->close();
            if (!$found) respond_error('پوشه موردنظر وجود ندارد.', 404);
        }
        $target = $folderId > 0 ? (int)$folderId : null;
        $stmt = $mysqli->prepare('UPDATE media SET folder_id=? WHERE id=?');
        $stmt->bind_param('ii', $target, $id);
        $stmt->execute(); $changed = $stmt->affected_rows; $stmt->close();
        if (!media_get($mysqli,(int)$id)) respond_error('تصویر موردنظر یافت نشد.',404);
        respond_json(true, 'پوشه تصویر تغییر کرد.', ['changed'=>$changed]);
    }
    if ($action === 'folder_delete') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$id) respond_error('شناسه پوشه نامعتبر است.', 422);
        $stmt = $mysqli->prepare('DELETE FROM media_folders WHERE id=? AND NOT EXISTS
            (SELECT 1 FROM media m WHERE m.folder_id=media_folders.id)');
        $stmt->bind_param('i',$id); $stmt->execute();
        $changed = $stmt->affected_rows; $stmt->close();
        if ($changed !== 1) respond_error('پوشه خالی نیست یا وجود ندارد.', 409);
        respond_json(true, 'پوشه خالی حذف شد.');
    }
    if ($action === 'upload') {
        $folderId = filter_var($_POST['folder_id'] ?? '0', FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if ($folderId === false || $folderId === null) respond_error('شناسه پوشه نامعتبر است.',422);
        if ($folderId > 0) {
            $f = $mysqli->prepare('SELECT id FROM media_folders WHERE id=?');
            $f->bind_param('i',$folderId); $f->execute();
            $found = $f->get_result()->fetch_assoc(); $f->close();
            if (!$found) respond_error('پوشه انتخاب‌شده وجود ندارد.',404);
        }
        $folderId = $folderId > 0 ? (int)$folderId : null;
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])
            || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string)($_FILES['file']['tmp_name'] ?? ''))) {
            respond_error('بارگذاری فایل ناموفق بود.', 422);
        }
        $file = $_FILES['file'];
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > 5 * 1024 * 1024) {
            respond_error('حجم هر تصویر باید کمتر از پنج مگابایت باشد.', 422);
        }
        $tmp = (string)$file['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $formats = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $info = @getimagesize($tmp);
        if (!isset($formats[$mime]) || !is_array($info) || ($info['mime'] ?? '') !== $mime) {
            respond_error('فقط تصویر واقعی JPEG، PNG یا WebP پذیرفته می‌شود.', 422);
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w < 1 || $h < 1 || $w > 3000 || $h > 3000 || $w * $h > 9000000) {
            respond_error('ابعاد تصویر مجاز نیست (حداکثر ۳۰۰۰ پیکسل در هر ضلع).', 422);
        }
        if (!extension_loaded('gd')) respond_error('کتابخانه GD سرور برای تصاویر فعال نیست.', 503);
        $hash = hash_file('sha256', $tmp);
        $existing = $mysqli->prepare('SELECT id,original_path,thumbnail_path FROM media WHERE sha256=? LIMIT 1');
        $existing->bind_param('s', $hash);
        $existing->execute();
        $found = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($found) {
            if (!media_absolute_path((string)$found['original_path'])
                || !media_absolute_path((string)$found['thumbnail_path'])) {
                respond_error('این تصویر در کتابخانه ثبت شده اما فایل اصلی یا بندانگشتی آن پیدا نشد؛ پشتیبان رسانه‌ها را بررسی کنید.', 409);
            }
            $id = (int)$found['id'];
            respond_json(true, 'این تصویر قبلاً در کتابخانه وجود دارد.', [
                'id'=>$id, 'url'=>media_image_url($id), 'reused'=>true
            ]);
        }
        $bytes = @file_get_contents($tmp);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        unset($bytes);
        if (!$image) respond_error('تصویر قابل پردازش نیست.', 422);
        $name = bin2hex(random_bytes(16));
        $ext = $formats[$mime];
        $root = media_directory(true);
        foreach (['original','thumb'] as $sub) {
            if (!is_dir($root . '/' . $sub) && !@mkdir($root . '/' . $sub, 0700, true)
                && !is_dir($root . '/' . $sub)) {
                imagedestroy($image);
                throw new RuntimeException('پوشه ذخیره‌سازی تصویر قابل ایجاد نیست.');
            }
        }
        $original = 'original/' . $name . '.' . $ext;
        $thumb = 'thumb/' . $name . '.jpg';
        $originalAbs = $root . '/' . $original;
        $thumbAbs = $root . '/' . $thumb;
        $written = match ($mime) {
            'image/jpeg' => @imagejpeg($image, $originalAbs, 88),
            'image/png' => @imagepng($image, $originalAbs, 6),
            'image/webp' => @imagewebp($image, $originalAbs, 88),
            default => false
        };
        $side = 240;
        $ratio = min($side / $w, $side / $h);
        $tw = max(1, (int)round($w * $ratio));
        $th = max(1, (int)round($h * $ratio));
        $small = imagecreatetruecolor($side, $side);
        $white = imagecolorallocate($small, 255, 255, 255);
        imagefilledrectangle($small, 0, 0, $side, $side, $white);
        imagecopyresampled($small, $image, (int)(($side-$tw)/2), (int)(($side-$th)/2),
            0, 0, $tw, $th, $w, $h);
        $thumbWritten = @imagejpeg($small, $thumbAbs, 82);
        imagedestroy($small);
        imagedestroy($image);
        if (!$written || !$thumbWritten) {
            @unlink($originalAbs);
            @unlink($thumbAbs);
            throw new RuntimeException('ذخیره تصویر پردازش‌شده ناموفق بود.');
        }
        @chmod($originalAbs, 0600);
        @chmod($thumbAbs, 0600);
        $fileSize = (int)filesize($originalAbs);
        if ($fileSize < 1 || $fileSize > 10 * 1024 * 1024) {
            @unlink($originalAbs); @unlink($thumbAbs);
            throw new RuntimeException('حجم تصویر پس از پردازش بیش از حد مجاز است.');
        }
        $filename = basename(str_replace('\\', '/', (string)($file['name'] ?? 'image')));
        $filename = mb_substr($filename, 0, 250);
        $userId = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : null;
        try {
            $stmt = $mysqli->prepare(
                'INSERT INTO media(original_filename,stored_filename,original_path,thumbnail_path,mime_type,
                file_size,width,height,sha256,uploaded_by,folder_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->bind_param('sssssiiisii', $filename, $name, $original, $thumb, $mime,
                $fileSize, $w, $h, $hash, $userId, $folderId);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            @unlink($originalAbs);
            @unlink($thumbAbs);
            // Race: another upload inserted identical sha256 during image processing.
            $stmt = $mysqli->prepare('SELECT id FROM media WHERE sha256=? LIMIT 1');
            $stmt->bind_param('s', $hash);
            $stmt->execute();
            $duplicate = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$duplicate) throw $e;
            if (!media_absolute_path((string)$duplicate['original_path'])
                || !media_absolute_path((string)$duplicate['thumbnail_path'])) {
                throw new RuntimeException('نسخه موجود تصویر در کتابخانه آسیب دیده است.');
            }
            $id = (int)$duplicate['id'];
        }
        respond_json(true, 'تصویر در کتابخانه ذخیره شد.', [
            'id'=>$id,'url'=>media_image_url($id),'reused'=>false
        ]);
    }

    if ($action === 'category_set') {
        $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $mediaId = filter_var($_POST['media_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if (!$categoryId || $mediaId === false || $mediaId === null) {
            respond_error('شناسه دسته‌بندی یا تصویر نامعتبر است.', 422);
        }
        $mysqli->begin_transaction();
        try {
            $stmt = $mysqli->prepare('DELETE FROM category_media WHERE category_id=?');
            $stmt->bind_param('i', $categoryId);
            $stmt->execute();
            $stmt->close();
            if ($mediaId > 0) {
                if (!media_get($mysqli, (int)$mediaId)) throw new InvalidArgumentException('رسانه انتخابی وجود ندارد.');
                $stmt = $mysqli->prepare('INSERT INTO category_media(category_id,media_id) VALUES (?,?)');
                $stmt->bind_param('ii', $categoryId, $mediaId);
                $stmt->execute();
                $stmt->close();
            }
            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            throw $e;
        }
        respond_json(true, 'تصویر دسته‌بندی ثبت شد.');
    }

    if ($action === 'delete') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$id) respond_error('شناسه تصویر نامعتبر است.', 422);
        $mysqli->begin_transaction();
        try {
            $stmt = $mysqli->prepare('SELECT * FROM media WHERE id=? FOR UPDATE');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $media = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$media) throw new InvalidArgumentException('تصویر پیدا نشد.');
            $stmt = $mysqli->prepare('SELECT (
                (SELECT COUNT(*) FROM product_media WHERE media_id=?) +
                (SELECT COUNT(*) FROM category_media WHERE media_id=?) +
                (SELECT COUNT(*) FROM defective_media WHERE media_id=?)) AS n');
            $stmt->bind_param('iii', $id, $id, $id);
            $stmt->execute();
            $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
            $stmt->close();
            if ($n !== 0) throw new InvalidArgumentException('تصویر در محصولات یا دسته‌بندی‌ها استفاده می‌شود و قابل حذف نیست.');
            $stmt = $mysqli->prepare('DELETE FROM media WHERE id=?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            throw $e;
        }
        // Post-commit cleanup: a crash can leave an orphan file, never a broken DB link.
        foreach (['original_path','thumbnail_path'] as $key) {
            $path = media_absolute_path((string)$media[$key]);
            if ($path) @unlink($path);
        }
        respond_json(true, 'تصویر بدون استفاده از کتابخانه حذف شد.');
    }
    respond_error('عملیات نامعتبر است.', 400);
} catch (InvalidArgumentException $e) {
    respond_error($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('media_api: ' . $e->getMessage());
    respond_error('خطای سرویس رسانه؛ تنظیمات هاست و گزارش سرور را بررسی کنید.', 500);
}
