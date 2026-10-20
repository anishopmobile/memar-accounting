<?php
/**
 * account_settings.php — عملیات حساب کاربری (POST + CSRF + ورود)
 *
 * اعمال:
 *  - update_profile      به‌روزرسانی نام و نام خانوادگی
 *  - change_password     تغییر رمز عبور (نیازمند رمز فعلی)
 *  - upload_avatar       آپلود تصویر پروفایل (بررسی امنیتی کامل)
 *  - remove_avatar       حذف تصویر پروفایل
 */

declare(strict_types=1);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';

require_csrf_or_fail();

$userId = auth_user_id();
if ($userId === null) {
    respond_error('ابتدا باید وارد حساب کاربری شوید.', 401);
}

$action = (string) ($_POST['action'] ?? '');

if ($action === 'update_profile') {
    $firstName = (string) ($_POST['first_name'] ?? '');
    $lastName = (string) ($_POST['last_name'] ?? '');
    $result = auth_user_update_profile($userId, $firstName, $lastName);
    if (!$result['ok']) {
        respond_error($result['message'], 422);
    }
    respond_json(true, $result['message']);
}

if ($action === 'change_password') {
    $result = auth_user_change_password(
        $userId,
        (string) ($_POST['current_password'] ?? ''),
        (string) ($_POST['new_password'] ?? ''),
        (string) ($_POST['new_password_confirmation'] ?? '')
    );
    if (!$result['ok']) {
        respond_error($result['message'], 422);
    }
    respond_json(true, $result['message']);
}

if ($action === 'upload_avatar') {
    $user = auth_user_by_id($userId);
    $currentPath = $user !== null ? (string) ($user['avatar_path'] ?? '') : '';

    $result = auth_avatar_process($_FILES['avatar'] ?? []);
    if (!$result['ok']) {
        respond_error($result['message'], 422);
    }

    $newPath = $result['path'];
    if (!auth_user_set_avatar($userId, $newPath)) {
        auth_avatar_delete($newPath);
        respond_error('خطا در ذخیره‌سازی تصویر پروفایل.', 500);
    }

    if ($currentPath !== '' && $currentPath !== $newPath) {
        auth_avatar_delete($currentPath);
    }

    respond_json(true, 'تصویر پروفایل به‌روزرسانی شد.', ['avatar_path' => $newPath]);
}

if ($action === 'remove_avatar') {
    $user = auth_user_by_id($userId);
    $currentPath = $user !== null ? (string) ($user['avatar_path'] ?? '') : '';

    if (!auth_user_set_avatar($userId, null)) {
        respond_error('خطا در حذف تصویر پروفایل.', 500);
    }

    if ($currentPath !== '') {
        auth_avatar_delete($currentPath);
    }

    respond_json(true, 'تصویر پروفایل حذف شد.');
}

respond_error('عملیات نامعتبر است.');
