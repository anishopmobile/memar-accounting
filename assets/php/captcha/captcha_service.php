<?php
/**
 * captcha_service.php — لایه‌ی بالاسری کپچا؛ فصل مشترک بین احراز هویت و ارائه‌دهنده
 *
 * انتخاب ارائه‌دهنده از تنظیمات برنامه انجام می‌شود (فعلاً pure-captcha) و
 * کلیدهای «ورود» و «ثبت‌نام» به‌صورت مستقل کنترل می‌شوند.
 */

declare(strict_types=1);

require_once __DIR__ . '/captcha_provider.php';
require_once __DIR__ . '/pure_captcha_provider.php';

final class CaptchaService
{
    private static ?CaptchaService $instance = null;

    private array $settings;
    private CaptchaProvider $provider;

    private function __construct()
    {
        $this->settings = auth_settings_get();
        $this->provider = self::buildProvider($this->settings);
    }

    public static function instance(): CaptchaService
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * ساخت ارائه‌دهنده بر اساس تنظیمات ذخیره‌شده.
     * فقط گزینه‌های تأییدشده در سورس نصب‌شده‌ی pure-captcha پاس داده می‌شوند.
     */
    public static function buildProvider(array $settings): CaptchaProvider
    {
        $lang = in_array((string) ($settings['captcha_lang'] ?? 'fa'), AUTH_CAPTCHA_LANGS, true)
            ? (string) $settings['captcha_lang']
            : 'fa';

        $charType = in_array((string) ($settings['captcha_char_type'] ?? 'mixed'), AUTH_CAPTCHA_CHAR_TYPES, true)
            ? (string) $settings['captcha_char_type']
            : 'mixed';

        $noise = in_array((string) ($settings['captcha_noise_level'] ?? 'medium'), AUTH_CAPTCHA_NOISE_LEVELS, true)
            ? (string) $settings['captcha_noise_level']
            : 'medium';

        $options = [
            'lang'           => $lang,
            'font'           => 'Vazirmatn.ttf',
            'expiry'         => (int) ($settings['captcha_expiry'] ?? 180),
            'length'         => (int) ($settings['captcha_length'] ?? 5),
            'width'          => (int) ($settings['captcha_width'] ?? 170),
            'height'         => (int) ($settings['captcha_height'] ?? 55),
            'font_size'      => (int) ($settings['captcha_font_size'] ?? 22),
            'char_type'      => $charType,
            'case_sensitive' => (bool) ($settings['captcha_case_sensitive'] ?? false),
            'noise_level'    => $noise,
            'session_key'    => '__captcha_sys',
        ];

        $provider = (string) ($settings['captcha_provider'] ?? 'pure');
        if ($provider === 'pure') {
            return new PureCaptchaProvider($options);
        }

        // فروپاشی امن: اگر مقدار نامعتبری ذخیره شده باشد به pure برمی‌گردیم.
        return new PureCaptchaProvider($options);
    }

    /** آیا کپچا برای این قالب (login / registration) فعال است؟ */
    public function enabledFor(string $context): bool
    {
        if ($context === 'login') {
            return (bool) ($this->settings['login_captcha_enabled'] ?? false);
        }
        if ($context === 'registration') {
            return (bool) ($this->settings['registration_captcha_enabled'] ?? false);
        }
        return false;
    }

    public function settings(): array
    {
        return $this->settings;
    }

    public function validate(string $input): bool
    {
        return $this->provider->validate($input);
    }

    public function renderImage(): void
    {
        $this->provider->renderImage();
    }

    public function html(string $imageUrl, string $inputName = 'captcha', ?string $lang = null): string
    {
        return $this->provider->html($imageUrl, $inputName, $lang);
    }

    public function styles(): string
    {
        return $this->provider->styles();
    }
}
