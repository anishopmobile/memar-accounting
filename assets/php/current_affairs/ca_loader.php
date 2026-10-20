<?php
/**
 * current_affairs/ca_loader.php — Current Affairs module loader.
 *
 * Includes the shared module files in dependency order. Expects `$mysqli`
 * (already connected) and the existing boot/auth/session when used inside the
 * admin application.
 */
declare(strict_types=1);

require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/migration.php';
require_once __DIR__ . '/ca_core.php';
require_once __DIR__ . '/ca_repository.php';