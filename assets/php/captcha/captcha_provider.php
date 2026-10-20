<?php
/**
 * captcha_provider.php — مرز انتزاعی ارائه‌دهنده‌ی کپچا
 *
 * منطق ورود/ثبت‌نام فقط به این واسط وابسته است تا بتوان در آینده
 * ارائه‌دهنده‌ی دیگری را بدون بازنویسی احراز هویت اضافه کرد.
 */

declare(strict_types=1);

interface CaptchaProvider
{
    /** تولید کد امنیتی جدید و ذخیره‌سازی امن آن (سمت سرور). */
    public function generate(): string;

    /** اعتبارسنجی یک‌بارمصرف ورودی کاربر. */
    public function validate(string $input): bool;

    /** رندر تصویر PNG به خروجی (با هدرهای no-cache). */
    public function renderImage(): void;

    /** HTML ویجت رسمی ارائه‌دهنده. */
    public function html(string $imageUrl = 'captcha_image.php', string $inputName = 'captcha', ?string $lang = null): string;

    /** CSS ویجت ارائه‌دهنده. */
    public function styles(): string;
}
