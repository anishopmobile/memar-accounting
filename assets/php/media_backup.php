<?php
/** Full account + private media backup. No untrusted ZIP entry is extracted verbatim. */
declare(strict_types=1);

/** Ignore the dump generator's timestamp *comment* only; all SQL statements remain significant. */
function media_sql_content_digest(string $sql): string
{
    $normalized = preg_replace('/^-- تاریخ و ساعت تهیه: [^\\r\\n]*$/mu',
        '-- تاریخ و ساعت تهیه: [normalized]', $sql, 1);
    return hash('sha256', is_string($normalized) ? $normalized : $sql);
}

function media_full_backup_create(mysqli $db, string $destination, string $sql): string
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZIP extension is missing');
    $root = media_storage_root();
    $archivePath = rtrim($destination, '/\\\\') . '/full_' . date('Ymd_His')
        . '_' . bin2hex(random_bytes(4)) . '.zip';
    $temporary = $archivePath . '.part';
    $zip = new ZipArchive();
    if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Cannot create archive');
    }
    $manifest = ['version'=>1,'created_at'=>gmdate('c'),'files'=>[],
        'sql_sha256'=>hash('sha256', $sql),'sql_bytes'=>strlen($sql)];
    try {
        if (!$zip->addFromString('database.sql', $sql)) throw new RuntimeException('ZIP SQL failure');
        foreach (['original', 'thumb'] as $kind) {
            $dir = $root . '/' . $kind;
            if (!is_dir($dir)) continue;
            $it = new DirectoryIterator($dir);
            foreach ($it as $file) {
                if (!$file->isFile() || $file->isLink()) continue;
                $relative = $kind . '/' . $file->getFilename();
                if (!preg_match('/^(original|thumb)\\/[a-f0-9]{32}\\.(jpg|png|webp)$/D', $relative)) continue;
                $absolute = media_absolute_path($relative);
                if (!$absolute) continue;
                $hash = hash_file('sha256', $absolute);
                $manifest['files'][$relative] = ['sha256'=>$hash,'bytes'=>filesize($absolute)];
                if (!$zip->addFile($absolute, 'media/' . $relative)) {
                    throw new RuntimeException('Cannot add media file');
                }
            }
        }
        // A successful full backup must contain every DB-referenced media file.
        $registered = $db->query('SELECT original_path, thumbnail_path FROM media');
        while ($registered && ($record = $registered->fetch_assoc())) {
            foreach (['original_path','thumbnail_path'] as $column) {
                $ref = (string)($record[$column] ?? '');
                if ($ref === '' || !isset($manifest['files'][$ref])) {
                    throw new RuntimeException('Missing registered media during full backup: ' . $ref);
                }
            }
        }
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!$zip->addFromString('manifest.json', $manifestJson)) throw new RuntimeException('ZIP manifest failure');
        if (!$zip->close()) throw new RuntimeException('Cannot close ZIP');
        if (!@rename($temporary, $archivePath)) throw new RuntimeException('Cannot publish ZIP');
        @chmod($archivePath, 0600);
        return $archivePath;
    } catch (Throwable $e) {
        $zip->close();
        @unlink($temporary);
        throw $e;
    }
}

/**
 * Strict validation before changing either database or disk. Restoring SQL is
 * destructive in the legacy app; preserve a local SQL rollback snapshot.
 *
 * @param callable(string):?string $restoreSql legacy restoreDatabase()
 * @param callable():string $dumpSql legacy exportDatabase()
 */
function media_full_backup_restore(mysqli $db, string $archive, callable $restoreSql, callable $dumpSql): array
{
    if (!class_exists('ZipArchive') || !is_file($archive) || filesize($archive) > 524288000) {
        throw new RuntimeException('Invalid archive / ZIP unavailable');
    }
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::RDONLY) !== true) throw new RuntimeException('Unreadable ZIP');
    $staged = [];
    try {
        if ($zip->numFiles > 30000) throw new RuntimeException('Excessive ZIP entries');
        $seen = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) throw new RuntimeException('Invalid ZIP entry');
            $name = $stat['name'];
            if (isset($seen[$name]) || !(
                $name === 'database.sql' || $name === 'manifest.json' ||
                preg_match('#^media/(original|thumb)/[a-f0-9]{32}\\.(?:jpg|png|webp)$#D', $name)
            )) throw new RuntimeException('Unsafe, duplicate or unexpected ZIP entry');
            $seen[$name] = true;
            $total += (int)$stat['size'];
            if ($total > 800 * 1024 * 1024) throw new RuntimeException('Oversized ZIP data');
        }
        $manifestRaw = $zip->getFromName('manifest.json');
        $sql = $zip->getFromName('database.sql');
        if (!is_string($manifestRaw) || strlen($manifestRaw) > 4000000
            || !is_string($sql) || strlen($sql) > 200 * 1024 * 1024) {
            throw new RuntimeException('Invalid ZIP manifest/SQL');
        }
        $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1
            || !is_array($manifest['files'] ?? null)
            || !hash_equals((string)($manifest['sql_sha256'] ?? ''), hash('sha256', $sql))) {
            throw new RuntimeException('Corrupted backup manifest');
        }
        $keys = array_keys($manifest['files']);
        if (count($keys) !== $zip->numFiles - 2) throw new RuntimeException('Media count mismatch');

        $root = media_directory(true);
        $stageRoot = $root . '/.staging_' . bin2hex(random_bytes(8));
        if (!@mkdir($stageRoot, 0700)) throw new RuntimeException('Cannot stage media');
        foreach (['original','thumb'] as $kind) {
            if (!@mkdir($stageRoot . '/' . $kind, 0700)) throw new RuntimeException('Cannot stage folder');
            if (!is_dir($root . '/' . $kind) && !@mkdir($root . '/' . $kind, 0700)) {
                throw new RuntimeException('Cannot create media folder');
            }
        }
        foreach ($manifest['files'] as $relative=>$item) {
            if (!is_string($relative) || !preg_match('#^(original|thumb)/[a-f0-9]{32}\\.(?:jpg|png|webp)$#D', $relative)
                || !isset($seen['media/' . $relative]) || !is_array($item)
                || !preg_match('/^[a-f0-9]{64}$/D', (string)($item['sha256'] ?? ''))) {
                throw new RuntimeException('Invalid media manifest item');
            }
            $dest = $root . '/' . $relative;
            $expectedHash = (string)$item['sha256'];
            $expectedBytes = (int)($item['bytes'] ?? -1);
            if ($expectedBytes < 0) throw new RuntimeException('Missing media byte count');
            $existing = is_file($dest);
            if ($existing && (filesize($dest) !== $expectedBytes
                || !hash_equals($expectedHash, hash_file('sha256',$dest)))) {
                throw new RuntimeException('Conflicting existing media file');
            }
            if ($existing) {
                // The destination itself was verified against the signed-format
                // manifest (SHA-256+byte count). The ZIP copy is not used, so
                // avoid re-inflating and hashing thousands of identical files.
                // Missing files always get streamed and checksum-verified.
                continue;
            }
            $stream = $zip->getStream('media/' . $relative);
            if (!$stream) throw new RuntimeException('Missing ZIP media data');
            $context = hash_init('sha256');
            $count = 0;
            $stagedPath = $stageRoot . '/' . $relative;
            $out = fopen($stagedPath, 'wb');
            if (!$out) { fclose($stream); throw new RuntimeException('Cannot write staged file'); }
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false) throw new RuntimeException('ZIP read failed');
                    if ($chunk === '') break;
                    hash_update($context, $chunk);
                    $length = strlen($chunk);
                    $written = 0;
                    while ($written < $length) {
                        $n = fwrite($out, substr($chunk,$written));
                        if ($n === false || $n === 0) throw new RuntimeException('Cannot write staged file');
                        $written += $n;
                    }
                    $count += $length;
                }
            } finally {
                fclose($out); fclose($stream);
            }
            if ($count !== $expectedBytes || !hash_equals($expectedHash, hash_final($context))) {
                throw new RuntimeException('Media checksum mismatch');
            }
            $staged[] = [$stagedPath, $dest];
        }
        // Keep pre-restore SQL in a private temporary file for recovery.
        $priorSql = $dumpSql();
        if (!is_string($priorSql) || $priorSql === '') throw new RuntimeException('Current database backup failed');
        $sqlRecovery = $stageRoot . '/pre-restore.sql';
        // A same-version backup may be restored repeatedly while many photos
        // already exist. When *both* SQL bytes and every media SHA already
        // match, doing destructive DROP/CREATE SQL is unnecessary.
        $unchangedSql = hash_equals(media_sql_content_digest($sql),
            media_sql_content_digest($priorSql));
        if (!$staged && $unchangedSql) {
            return ['files'=>count($manifest['files']),'new_media_files'=>0,
                'database_restored'=>true,'sql_skipped_identical'=>true];
        }
        if (file_put_contents($sqlRecovery, $priorSql, LOCK_EX) === false) throw new RuntimeException('Cannot stage rollback');
        unset($priorSql);
        foreach ($staged as [$source,$dest]) {
            if (!is_file($dest)) {
                if (!@copy($source, $dest)) throw new RuntimeException('Copy to media store failed');
                @chmod($dest,0600);
            }
        }
        $error = $restoreSql($sql);
        if ($error !== null) {
            $fallback = @file_get_contents($sqlRecovery);
            if (is_string($fallback) && $fallback !== '') {
                $rollbackError = $restoreSql($fallback);
                if ($rollbackError !== null) error_log('Media restore SQL rollback failed: ' . $rollbackError);
            }
            throw new RuntimeException('Database restore failed: ' . $error);
        }
        return ['files'=>count($manifest['files']),'new_media_files'=>count($staged),'database_restored'=>true,
            'sql_skipped_identical'=>false];
    } finally {
        $zip->close();
        if (isset($stageRoot) && is_dir($stageRoot)) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stageRoot,
                FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iter as $entry) {
                if ($entry->isDir()) @rmdir($entry->getPathname());
                else @unlink($entry->getPathname());
            }
            @rmdir($stageRoot);
        }
    }
}
