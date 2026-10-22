<?php
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/media_common.php';
require_once __DIR__ . '/media_backup.php';

header('Content-Type: application/json; charset=UTF-8');

function respond($success, $message = '', $data = null)
{
    $payload = ['success' => $success];
    if ($message !== '') {
        $payload['message'] = $message;
    }
    if ($data !== null) {
        $payload['data'] = $data;
    }
    echo json_encode($payload);
    exit;
}

$settings = null;
$result = $mysqli->query('SELECT * FROM db_backup_settings WHERE id = 1 LIMIT 1');
if ($result) {
    $settings = $result->fetch_assoc();
    $result->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(true, '', ['backup_dir' => $settings['backup_dir'] ?? '']);
}

$action = $_POST['action'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(false, 'روش درخواست نامعتبر است.');
require_csrf_or_fail();

/**
 * Resolve a server-side directory path.
 * Absolute paths (Windows / Unix) are used as-is; otherwise the value is
 * treated as a folder name inside the project's "backups" directory.
 */
function resolveBackupDir($dir)
{
    $dir = trim((string)$dir);
    if ($dir === '') return '';
    $webroot = realpath(dirname(__DIR__, 2));
    if (!$webroot) throw new RuntimeException('پوشه سایت در دسترس نیست.');

    // Relative backup names live OUTSIDE the document root, even on Apache
    // hosts where .htaccess is disabled. Block .. traversal and weird names.
    $absolute = preg_match('#^(?:[A-Za-z]:[\\\\/]|/|\\\\)#', $dir) === 1;
    if (!$absolute) {
        if (!preg_match('/^[a-zA-Z0-9_\\-]{1,80}$/D', $dir)) {
            throw new InvalidArgumentException('نام پوشه پشتیبان باید کوتاه و بدون مسیر پیمایش باشد.');
        }
        $dir = dirname($webroot) . DIRECTORY_SEPARATOR . 'memar_private_backups'
            . DIRECTORY_SEPARATOR . $dir;
    }

    $normalized = str_replace('\\\\', '/', $dir);
    $real = realpath($dir);
    // For a path not yet created, check its existing ancestor and lexical
    // segments to avoid symlinks that resolve into the webroot.
    $candidate = $real ?: $normalized;
    if (preg_match('#(?:^|/)\\.\\.(?:/|$)#', str_replace('\\\\', '/', $candidate))) {
        throw new InvalidArgumentException('مسیر پشتیبان شامل پیمایش غیرمجاز است.');
    }
    $base = str_replace('\\\\', '/', $webroot);
    $check = str_replace('\\\\', '/', $candidate);
    if ($check === $base || str_starts_with($check, $base . '/')) {
        throw new InvalidArgumentException('مسیر پشتیبان باید خارج از پوشه عمومی سایت باشد.');
    }
    $ancestor = $candidate;
    while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) $ancestor = dirname($ancestor);
    if (($resolvedAncestor = realpath($ancestor)) !== false) {
        $a = str_replace('\\\\', '/', $resolvedAncestor);
        if ($a === $base || str_starts_with($a, $base . '/')) {
            throw new InvalidArgumentException('مسیر پشتیبان به داخل پوشه عمومی سایت اشاره می‌کند.');
        }
    }
    return $dir;
}

/* ---------- ذخیره آدرس پوشه پشتیبان ---------- */
if ($action === 'save_dir') {
    $backup_dir = trim($_POST['backup_dir'] ?? '');
    if ($backup_dir === '') {
        respond(false, 'آدرس پوشه پشتیبان نمی‌تواند خالی باشد.');
    }

    if ($settings) {
        $stmt = $mysqli->prepare('UPDATE db_backup_settings SET backup_dir = ? WHERE id = 1');
        $stmt->bind_param('s', $backup_dir);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            respond(false, 'خطا در ذخیره‌سازی آدرس پشتیبان.');
        }
        respond(true, 'آدرس پوشه پشتیبان ذخیره شد.', ['backup_dir' => $backup_dir]);
    }

    $stmt = $mysqli->prepare('INSERT INTO db_backup_settings (id, backup_dir) VALUES (1, ?)');
    $stmt->bind_param('s', $backup_dir);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        respond(false, 'خطا در ذخیره‌سازی آدرس پشتیبان.');
    }
    respond(true, 'آدرس پوشه پشتیبان ذخیره شد.', ['backup_dir' => $backup_dir]);
}


/* ---------- Full archive: SQL + private media files (not Excel or financial writes) ---------- */
if ($action === 'backup_full') {
    $storedDir = $settings['backup_dir'] ?? '';
    if (trim($storedDir) === '') respond(false, 'آدرس پشتیبان را ابتدا ذخیره کنید.');
    $targetDir = resolveBackupDir($storedDir);
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
        respond(false, 'ایجاد پوشه پشتیبان ممکن نیست.');
    }
    try {
        $path = media_full_backup_create($mysqli, $targetDir, exportDatabase($mysqli));
        respond(true, 'نسخه کامل دیتابیس و تصاویر تهیه شد.', [
            'filename'=>basename($path), 'path'=>$path, 'size'=>filesize($path)
        ]);
    } catch (Throwable $e) {
        error_log('media backup failed: ' . $e->getMessage());
        respond(false, 'پشتیبان کامل ناموفق بود؛ دسترسی پوشه و افزونه ZIP را بررسی کنید.');
    }
}
if ($action === 'restore_full') {
    if (empty($_FILES['backup_file']) || ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file((string)$_FILES['backup_file']['tmp_name'])) {
        respond(false, 'فایل ZIP پشتیبان کامل انتخاب نشده است.');
    }
    try {
        $result = media_full_backup_restore($mysqli, $_FILES['backup_file']['tmp_name'],
            static fn($sql) => restoreDatabase($mysqli, $sql),
            static fn() => exportDatabase($mysqli));
        respond(true, 'پشتیبان کامل بازیابی شد.', $result);
    } catch (Throwable $e) {
        error_log('media restore failed: ' . $e->getMessage());
        respond(false, 'بازگردانی نسخه کامل به دلیل خطا یا عدم اعتبار بسته متوقف شد. لاگ سرور را بررسی کنید.');
    }
}

/* ---------- تهیه نسخه پشتیبان ---------- */
if ($action === 'backup') {
    $storedDir = $settings['backup_dir'] ?? '';
    if (trim($storedDir) === '') {
        respond(false, 'ابتدا باید آدرس پوشه پشتیبان را ذخیره کنید.');
    }

    $targetDir = resolveBackupDir($storedDir);
    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            respond(false, 'پوشه مقصد قابل ایجاد نیست. آدرس را بررسی کنید.');
        }
    }
    if (!is_writable($targetDir)) {
        respond(false, 'پوشه مقصد قابل نوشتن نیست. دسترسی پوشه را بررسی کنید.');
    }

    // نام فایل با تاریخ و ساعت تهیه نسخه (برای غیرتکراری و قابل فهم بودن)
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $targetDir . DIRECTORY_SEPARATOR . $filename;

    $dump = exportDatabase($mysqli);
    if (@file_put_contents($filepath, $dump) === false) {
        respond(false, 'خطا در نوشتن فایل پشتیبان. دسترسی پوشه را بررسی کنید.');
    }

    respond(true, 'نسخه پشتیبان با موفقیت تهیه شد.', [
        'filename' => $filename,
        'path' => $filepath,
        'size' => filesize($filepath),
    ]);
}

/* ---------- تهیه نسخه پشتیبان از تمام فایل‌ها ---------- */
if ($action === 'backup_files') {
    $storedDir = $settings['backup_dir'] ?? '';
    if (trim($storedDir) === '') {
        respond(false, 'ابتدا باید آدرس پوشه پشتیبان را ذخیره کنید.');
    }

    $backupRoot = resolveBackupDir($storedDir);
    if (!is_dir($backupRoot)) {
        if (!@mkdir($backupRoot, 0755, true) && !is_dir($backupRoot)) {
            respond(false, 'پوشه مقصد قابل ایجاد نیست. آدرس را بررسی کنید.');
        }
    }
    if (!is_writable($backupRoot)) {
        respond(false, 'پوشه مقصد قابل نوشتن نیست. دسترسی پوشه را بررسی کنید.');
    }

    $webroot = realpath(__DIR__ . '/../..');
    $dest = $backupRoot . DIRECTORY_SEPARATOR . 'files';

    // حذف پوشه files قبلی تا کپی همیشه تازه و کامل باشد
    if (is_dir($dest)) {
        deleteDirectory($dest);
    }
    if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
        respond(false, 'ایجاد پوشه files ناموفق بود.');
    }

    copyDirectory($webroot, $dest, $backupRoot, $webroot);

    respond(true, 'پشتیبان‌گیری از تمام فایل‌ها با موفقیت انجام شد.', [
        'path' => $dest,
    ]);
}

/* ---------- بازیابی نسخه پشتیبان ---------- */
if ($action === 'restore') {
    if (empty($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        respond(false, 'لطفاً یک فایل SQL برای بازیابی انتخاب کنید.');
    }

    $file = $_FILES['backup_file'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'sql') {
        respond(false, 'فایل انتخاب شده باید با پسوند .sql باشد.');
    }

    $content = @file_get_contents($file['tmp_name']);
    if ($content === false || trim($content) === '') {
        respond(false, 'فایل انتخاب شده خالی یا غیرقابل خواندن است.');
    }

    $error = restoreDatabase($mysqli, $content);
    if ($error !== null) {
        respond(false, 'بازیابی ناموفق بود: ' . $error);
    }
    respond(true, 'بازیابی نسخه پشتیبان با موفقیت انجام شد.');
}

respond(false, 'عملیات نامعتبر است.');

/* ==================== توابع کمکی ==================== */

function exportDatabase($mysqli)
{
    date_default_timezone_set('Asia/Tehran');
    $output = "-- نسخه پشتیبان پایگاه داده\n";
    $output .= "-- تاریخ و ساعت تهیه: " . date('Y-m-d H:i:s') . "\n\n";
    $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tablesResult = $mysqli->query('SHOW TABLES');
    $tables = [];
    if ($tablesResult) {
        while ($row = $tablesResult->fetch_row()) {
            $tables[] = $row[0];
        }
        $tablesResult->free();
    }

    foreach ($tables as $table) {
        $createResult = $mysqli->query("SHOW CREATE TABLE `$table`");
        if (!$createResult || !$createResult->fetch_row()) {
            continue;
        }
        $createResult->data_seek(0);
        $createRow = $createResult->fetch_row();
        $createSql = $createRow[1];

        $output .= "DROP TABLE IF EXISTS `$table`;\n";
        $output .= $createSql . ";\n\n";

        $dataResult = $mysqli->query("SELECT * FROM `$table`");
        if ($dataResult) {
            while ($row = $dataResult->fetch_assoc()) {
                $cols = array_map(function ($c) {
                    return "`$c`";
                }, array_keys($row));
                $vals = array_map(function ($v) use ($mysqli) {
                    if ($v === null) {
                        return 'NULL';
                    }
                    return "'" . $mysqli->real_escape_string($v) . "'";
                }, array_values($row));
                $output .= "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
            }
            $dataResult->free();
        }
        $output .= "\n";
    }

    $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $output;
}

function splitSqlStatements($sql)
{
    // حذف کامنت‌های تک‌خطی
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $statements = [];
    $buffer = '';
    foreach (explode("\n", $sql) as $line) {
        $buffer .= $line . "\n";
        if (substr(trim($line), -1) === ';') {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

function restoreDatabase($mysqli, $content)
{
    $mysqli->query('SET FOREIGN_KEY_CHECKS=0');

    // پاک‌سازی کامل جداول موجود قبل از بازیابی
    $tablesResult = $mysqli->query('SHOW TABLES');
    $tables = [];
    if ($tablesResult) {
        while ($row = $tablesResult->fetch_row()) {
            $tables[] = $row[0];
        }
        $tablesResult->free();
    }
    foreach ($tables as $table) {
        $mysqli->query("DROP TABLE IF EXISTS `$table`");
    }

    foreach (splitSqlStatements($content) as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        if (!$mysqli->query($statement)) {
            return strip_tags($mysqli->error) . ' (دستور ناقص: ' . mb_substr($statement, 0, 120) . '...)';
        }
    }

    $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
    return null;
}


function deleteDirectory($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * کپی بازگشتی همه محتویات src به dest به‌جز خود پوشه پشتیبان (برای جلوگیری
 * از کپی بی‌نهایت در صورتی که مسیر پشتیبان داخل خود پروژه باشد).
 */
function copyDirectory($src, $dest, $backupRoot, $webroot)
{
    $items = @scandir($src);
    if ($items === false) {
        return;
    }
    $backupRootReal = strtolower(realpath($backupRoot) ?: $backupRoot);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $srcPath = $src . DIRECTORY_SEPARATOR . $item;
        $destPath = $dest . DIRECTORY_SEPARATOR . $item;

        // جلوگیری از کپی بازگشتی: رد کردن مسیر مقصد، خود پوشه پشتیبان و
        // هر پوشه‌ای که پوشه پشتیبان داخل آن قرار دارد
        $realSrc = realpath($srcPath);
        if ($realSrc) {
            $realSrcLower = strtolower($realSrc);
            $brRealLower = $backupRootReal;
            if ($realSrcLower === $brRealLower
                || strpos($brRealLower, $realSrcLower . DIRECTORY_SEPARATOR) === 0
                || strpos($realSrcLower, $brRealLower . DIRECTORY_SEPARATOR) === 0) {
                continue;
            }
        }

        if (is_dir($srcPath)) {
            if (!@mkdir($destPath, 0755, true) && !is_dir($destPath)) {
                continue;
            }
            copyDirectory($srcPath, $destPath, $backupRoot, $webroot);
        } else {
            @copy($srcPath, $destPath);
        }
    }
}

