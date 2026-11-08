<?php
function db_column_exists(mysqli $mysqli, string $table, string $column): bool
{
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $row = $res ? $res->fetch_assoc() : null;
    return $row !== null && (int) $row['c'] > 0;
}

/**
 * بررسی وجود ایندکس در جدول
 */
function db_index_exists(mysqli $mysqli, string $table, string $index): bool
{
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $row = $res ? $res->fetch_assoc() : null;
    return $row !== null && (int) $row['c'] > 0;
}

/**
 * افزودن ستون در صورت نبودن (idempotent)
 */
function db_ensure_column(mysqli $mysqli, string $table, string $column, string $definition): bool
{
    if (db_column_exists($mysqli, $table, $column)) {
        return true;
    }
    $sql = "ALTER TABLE `{$table}` ADD COLUMN {$definition}";
    return (bool) $mysqli->query($sql);
}

