<?php
require_once __DIR__ . '/db.php';

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

/**
 * Resolve a server-side directory path.
 * Absolute paths (Windows / Unix) are used as-is; otherwise the value is
 * treated as a folder name inside the project's "backups" directory.
 */
function resolveBackupDir($dir)
{
    $dir = trim($dir);
    if ($dir === '') {
        return '';
    }
    $webroot = realpath(__DIR__ . '/../..');
    if (preg_match('#^[A-Za-z]:[\\\\/]#', $dir) || $dir[0] === '/' || $dir[0] === '\\') {
        return rtrim($dir, '/\\');
    }
    return $webroot . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $dir;
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

