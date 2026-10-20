<?php
require_once __DIR__ . '/../assets/php/db.php';

$accUser = current_user();
if ($accUser === null) {
    echo '<div class="card"><p class="empty-state">حساب کاربری یافت نشد.</p></div>';
    return;
}

$csrf = csrf_token();
$accAvatar = (string) ($accUser['avatar_path'] ?? '');
?>

<input type="hidden" id="accountCsrfToken" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

<div class="card">
    <div class="page-actions">
        <h2 style="margin:0;">حساب کاربری</h2>
        <span class="setting-note">اطلاعات حساب مدیریت</span>
    </div>

    <div class="form-grid">
        <div class="form-row">
            <label for="accUsername">نام کاربری</label>
            <input type="text" id="accUsername" value="<?php echo htmlspecialchars((string) $accUser['username'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
        </div>
        <div class="form-row">
            <label for="accFirstName">نام</label>
            <input type="text" id="accFirstName" value="<?php echo htmlspecialchars((string) $accUser['first_name'], ENT_QUOTES, 'UTF-8'); ?>" autocomplete="given-name">
        </div>
        <div class="form-row">
            <label for="accLastName">نام خانوادگی</label>
            <input type="text" id="accLastName" value="<?php echo htmlspecialchars((string) $accUser['last_name'], ENT_QUOTES, 'UTF-8'); ?>" autocomplete="family-name">
        </div>
    </div>

    <div class="form-actions">
        <button type="button" class="button" id="accSaveProfile">ذخیره مشخصات</button>
        <span class="account-message" aria-live="polite"></span>
    </div>
</div>

<div class="card">
    <div class="page-actions">
        <h2 style="margin:0;">تصویر پروفایل</h2>
        <span class="setting-note">فرمت‌های مجاز: PNG / JPEG / GIF / WEBP — حداکثر ۲ مگابایت</span>
    </div>
    <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
        <div class="account-avatar-preview">
            <?php if ($accAvatar !== ''): ?>
                <img id="accAvatarPreview" src="<?php echo htmlspecialchars($accAvatar, ENT_QUOTES, 'UTF-8'); ?>" alt="تصویر پروفایل">
            <?php else: ?>
                <i class="fas fa-user" id="accAvatarPlaceholder"></i>
            <?php endif; ?>
        </div>
        <div style="flex:1; min-width:220px;">
            <label class="file-pick-card" style="display:flex; align-items:center; gap:10px; cursor:pointer; justify-content:space-between;">
                <span class="file-pick-meta">
                    <span class="file-pick-icon">🖼️</span>
                    <span>
                        <span class="file-pick-name" id="accAvatarFileName">انتخاب تصویر...</span>
                        <small>فایل تصویر جدید انتخاب کنید</small>
                    </span>
                </span>
                <span class="db-browse" style="pointer-events:none;">انتخاب فایل</span>
                <input type="file" id="accAvatarInput" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
            </label>
            <div class="form-actions" style="padding-top:12px; gap:12px;">
                <button type="button" class="button" id="accSaveAvatar">ذخیره تصویر</button>
                <button type="button" class="button-danger" id="accRemoveAvatar">حذف تصویر</button>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="page-actions">
        <h2 style="margin:0;">تغییر رمز عبور</h2>
        <span class="setting-note">رمز جدید باید حداقل ۸ کاراکتر و شامل حروف و عدد باشد</span>
    </div>

    <div class="form-grid">
        <div class="form-row">
            <label for="accCurrentPassword">رمز عبور فعلی</label>
            <input type="password" id="accCurrentPassword" autocomplete="current-password">
        </div>
        <div class="form-row">
            <label for="accNewPassword">رمز عبور جدید</label>
            <input type="password" id="accNewPassword" autocomplete="new-password">
        </div>
        <div class="form-row">
            <label for="accNewPasswordConfirm">تکرار رمز عبور جدید</label>
            <input type="password" id="accNewPasswordConfirm" autocomplete="new-password">
        </div>
    </div>

    <div class="form-actions">
        <button type="button" class="button" id="accChangePassword">تغییر رمز عبور</button>
    </div>
</div>

<script src="assets/js/auth.js" defer></script>
