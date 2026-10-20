<?php
/**
 * pure_captcha_provider.php — ارائه‌دهنده‌ی محلی pure-captcha
 *
 * کتابخانهِ (aminarjmand/pure-captcha) به‌صورت کامل و محلی در
 * assets/php/captcha/lib قرار دارد؛ هیچ وابستگی اجرایی خارجی وجود ندارد.
 * فقط گزینه‌های تأییدشده در سورس نصب‌شده پاس داده می‌شوند.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/src/Captcha.php';
require_once __DIR__ . '/captcha_provider.php';

final class PureCaptchaProvider implements CaptchaProvider
{
    public function __construct(array $options = [])
    {
        \AminArjmand\PureCaptcha\Captcha::configure($options);
    }

    public function generate(): string
    {
        return \AminArjmand\PureCaptcha\Captcha::generate();
    }

    public function validate(string $input): bool
    {
        return \AminArjmand\PureCaptcha\Captcha::validate($input);
    }

    public function renderImage(): void
    {
        \AminArjmand\PureCaptcha\Captcha::renderImage();
    }

    public function html(string $imageUrl = 'captcha_image.php', string $inputName = 'captcha', ?string $lang = null): string
    {
        return \AminArjmand\PureCaptcha\Captcha::html($imageUrl, $inputName, $lang);
    }

    public function styles(): string
    {
        return \AminArjmand\PureCaptcha\Captcha::styles();
    }
}
