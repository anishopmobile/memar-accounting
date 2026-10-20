<?php

namespace AminArjmand\PureCaptcha;

class Captcha
{
    private static array $config = [];
    private static bool  $loaded = false;

    /* ─── ثابت‌ها ─── */
    private const CHAR_SETS = [
        'mixed'      => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789~!@#$%^&*()-+',
        'lettersB'    => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'lettersS'    => 'abcdefghijklmnopqrstuvwxyz',
        'numbers'    => '0123456789',
        'characters' => '~!@#$%^&*()-+',
    ];

    private const NOISE = [
        'low'       => ['dots' => 1000, 'arcs' => 10,  'lines' => 1, 'angle' => 15, 'grid' => false, 'wave' => false, 'ghosts' => 2],
        'medium'    => ['dots' => 2000, 'arcs' => 20,  'lines' => 2, 'angle' => 20, 'grid' => false, 'wave' => false, 'ghosts' => 4],
        'high'      => ['dots' => 3000, 'arcs' => 30,  'lines' => 4, 'angle' => 25, 'grid' => true,  'wave' => false, 'ghosts' => 6],
        'extreme'   => ['dots' => 4000, 'arcs' => 40,  'lines' => 6, 'angle' => 35, 'grid' => true,  'wave' => false, 'ghosts' => 8],
        'nightmare' => ['dots' => 5000, 'arcs' => 50,  'lines' => 8, 'angle' => 40, 'grid' => true,  'wave' => true,  'ghosts' => 10],
    ];

    private const LABELS = [
        'fa' => [
            'placeholder' => 'کد امنیتی را وارد کنید',
            'refresh'     => 'تصویر جدید',
            'dir'         => 'rtl',
        ],
        'en' => [
            'placeholder' => 'Enter security code',
            'refresh'     => 'New image',
            'dir'         => 'ltr',
        ],
    ];

    /* ─── مسیر ریشه پکیج ─── */

    private static function packageRoot(): string
    {
        // src/Captcha.php → یک سطح بالاتر = ریشه پکیج
        return dirname(__DIR__);
    }

    /* ─── مسیر ریشه پروژه کاربر ─── */

    private static function projectRoot(): string
    {
        // وقتی با کامپوزر نصب شده:
        // project-root/vendor/aminarjmand/pure-captcha/src/Captcha.php
        // پس از src چهار سطح بالا = project-root
        $guess = dirname(__DIR__, 4);

        if (file_exists($guess . '/composer.json')) {
            return $guess;
        }

        // اگه بدون کامپوزر استفاده شده، همون ریشه پکیج
        return self::packageRoot();
    }

    /* ─── بارگذاری کانفیگ ─── */

    private static function load(): void
    {
        if (self::$loaded) return;

        $defaults = [
            'lang'           => 'fa',
            'font'           => '',
            'expiry'         => 300,
            'length'         => 5,
            'width'          => 170,
            'height'         => 55,
            'font_size'      => 22,
            'char_type'      => 'mixed',
            'case_sensitive' => false,
            'noise_level'    => 'medium',
            'session_key'    => '__captcha_sys',
        ];

        // ۱. کانفیگ پیش‌فرض پکیج
        $packageConfig = self::packageRoot() . '/config/captcha.php';
        if (file_exists($packageConfig)) {
            $pkg = require $packageConfig;
            if (is_array($pkg)) {
                $defaults = array_merge($defaults, $pkg);
            }
        }

        // ۲. کانفیگ کاربر در روت پروژه (اولویت بالاتر)
        $userConfig = self::projectRoot() . '/captcha_config.php';
        if (file_exists($userConfig)) {
            $user = require $userConfig;
            if (is_array($user)) {
                $defaults = array_merge($defaults, $user);
            }
        }

        self::$config = $defaults;
        self::$loaded = true;
    }

    /* ─── پیکربندی دستی (اختیاری) ─── */

    public static function configure(array $options): void
    {
        self::load();
        self::$config = array_merge(self::$config, $options);
    }

    /* ─── خواندن یک تنظیم ─── */

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$config[$key] ?? $default;
    }

    /* ─── سشن ─── */

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /* ─── پیدا کردن فونت ─── */

    private static function findFont(): ?string
    {
        self::load();

        if (!empty(self::$config['font'])) {
            // پوشه fonts داخل پکیج
            $inPackage = self::packageRoot() . '/fonts/' . self::$config['font'];
            if (file_exists($inPackage)) return $inPackage;

            // شاید مسیر کامل داده
            if (file_exists(self::$config['font'])) return self::$config['font'];
        }

        // فونت‌های سیستمی
        $candidates = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\tahoma.ttf',
            '/Library/Fonts/Arial.ttf',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) return $path;
        }

        return null;
    }

    /* ─── تولید کد ─── */

    public static function generate(): string
    {
        self::load();
        self::startSession();

        $chars  = self::CHAR_SETS[self::$config['char_type']] ?? self::CHAR_SETS['mixed'];
        $maxIdx = strlen($chars) - 1;
        $code   = '';

        for ($i = 0; $i < self::$config['length']; $i++) {
            $code .= $chars[random_int(0, $maxIdx)];
        }

        $_SESSION[self::$config['session_key']] = [
            'code' => $code,
            'time' => time(),
        ];

        return $code;
    }

    /* ─── اعتبارسنجی ─── */

    public static function validate(string $input): bool
    {
        self::load();
        self::startSession();

        $key = self::$config['session_key'];

        if (empty($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            return false;
        }

        $data = $_SESSION[$key];
        unset($_SESSION[$key]);

        if ((time() - $data['time']) > self::$config['expiry']) {
            return false;
        }

        $stored = $data['code'];
        $input  = trim($input);

        if (!self::$config['case_sensitive']) {
            $stored = strtoupper($stored);
            $input  = strtoupper($input);
        }

        return hash_equals($stored, $input);
    }

    /* ─── رندر تصویر ─── */

    public static function renderImage(): void
    {
        self::load();

        if (!extension_loaded('gd')) {
            throw new \RuntimeException('PHP GD extension is required.');
        }

        $code   = self::generate();
        $width  = self::$config['width'];
        $height = self::$config['height'];
        $len    = strlen($code);
        $noise  = self::NOISE[self::$config['noise_level']] ?? self::NOISE['medium'];

        $img = imagecreatetruecolor($width, $height);
        imageantialias($img, true);

        // ── پس‌زمینه گرادیانت ──
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / $height;
            $r = (int)(248 - 18 * $ratio);
            $g = (int)(250 - 15 * $ratio);
            $b = (int)(255 - 10 * $ratio);
            $c = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $width, $y, $c);
        }

        // ── شبکه توری ──
        if ($noise['grid']) {
            $gridColor = imagecolorallocate($img, 200, 200, 200);
            $step = 20;
            for ($gx = 0; $gx < $width; $gx += $step) {
                imageline($img, $gx, 0, $gx, $height, $gridColor);
            }
            for ($gy = 0; $gy < $height; $gy += $step) {
                imageline($img, 0, $gy, $width, $gy, $gridColor);
            }
        }

        // ── نویز: نقاط ──
        for ($i = 0; $i < $noise['dots']; $i++) {
            $c = imagecolorallocate($img,
                random_int(190, 230), random_int(190, 230), random_int(190, 230));
            imagesetpixel($img, random_int(0, $width - 1), random_int(0, $height - 1), $c);
        }

        // ── نویز: کمان‌ها ──
        for ($i = 0; $i < $noise['arcs']; $i++) {
            $c = imagecolorallocate($img,
                random_int(170, 215), random_int(170, 215), random_int(170, 215));
            imagearc($img,
                random_int(0, $width), random_int(0, $height),
                random_int((int)($width * 0.2), (int)($width * 0.6)),
                random_int((int)($height * 0.2), (int)($height * 0.6)),
                random_int(0, 180), random_int(180, 360), $c);
        }

        // ── پالت رنگ متن ──
        $palette = [
            [44,  62,  80 ],
            [192, 57,  43 ],
            [41,  128, 185],
            [39,  174, 96 ],
            [142, 68,  173],
            [211, 84,  0  ],
            [22,  160, 133],
        ];

        // ── فونت (قبل از هر استفاده) ──
        $font = self::findFont();

        // ── حروف شبح پس‌زمینه ──
        if ($noise['ghosts'] > 0) {
            self::drawGhostChars($img, $width, $height, $noise['ghosts'], $font);
        }

        // ── رسم حروف اصلی ──
        $padLeft  = (int)($width * 0.22);
        $padRight = (int)($width * 0.05);
        $charStep = ($width - $padLeft - $padRight) / $len;

        for ($i = 0; $i < $len; $i++) {
            $rgb   = $palette[array_rand($palette)];
            $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
            $x     = (int)($padLeft + $i * $charStep + random_int(-2, 2));
            $maxAngle = $noise['angle'];
            $angle    = random_int(-$maxAngle, $maxAngle);

            if ($font !== null) {
                $size = self::$config['font_size'] + random_int(-1, 2);
                $bbox = imagettfbbox($size, 0, $font, $code[$i]);
                $ch   = abs($bbox[7] - $bbox[1]);
                $y    = (int)(($height + $ch) / 2) + random_int(-3, 3);

                $shadow = imagecolorallocate($img,
                    min(255, $rgb[0] + 120),
                    min(255, $rgb[1] + 120),
                    min(255, $rgb[2] + 120));
                imagettftext($img, $size, $angle, $x + 1, $y + 1, $shadow, $font, $code[$i]);
                imagettftext($img, $size, $angle, $x, $y, $color, $font, $code[$i]);
            } else {
                self::drawBuiltinChar($img, $code[$i], $x, $height, $rgb);
            }
        }

        // ── نویز: خطوط روی متن ──
        for ($i = 0; $i < $noise['lines']; $i++) {
            $c = imagecolorallocate($img,
                random_int(120, 190), random_int(120, 190), random_int(120, 190));
            imagesetthickness($img, 1);
            imageline($img,
                random_int(0, (int)($width * 0.15)), random_int(0, $height),
                random_int((int)($width * 0.85), $width), random_int(0, $height), $c);
        }

        // ── اعمال موج ──
        if ($noise['wave']) {
            self::applyWave($img, $width, $height);
        }

        // ── خروجی ──
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        imagepng($img, null, 9);
        imagedestroy($img);
        exit;
    }

    /* ─── رسم کاراکتر بدون فونت ─── */

    private static function drawBuiltinChar($img, string $char, int $x, int $canvasH, array $rgb): void
    {
        $font  = 5;
        $fw    = imagefontwidth($font);
        $fh    = imagefontheight($font);
        $scale = 3;

        $tmp = imagecreatetruecolor($fw, $fh);
        $bg  = imagecolorallocate($tmp, 245, 247, 252);
        imagefill($tmp, 0, 0, $bg);
        $tc = imagecolorallocate($tmp, $rgb[0], $rgb[1], $rgb[2]);
        imagechar($tmp, $font, 0, 0, $char, $tc);

        $dstW = $fw * $scale;
        $dstH = $fh * $scale;
        $y    = (int)(($canvasH - $dstH) / 2) + random_int(-2, 2);

        imagecopyresampled($img, $tmp, $x, $y, 0, 0, $dstW, $dstH, $fw, $fh);
        imagedestroy($tmp);
    }

    /* ─── افکت موج ─── */

    private static function applyWave($img, int $width, int $height): void
    {
        $bgColor = imagecolorallocate($img, 240, 243, 250);

        // ── موج افقی ──
        $amp1    = random_int(3, 5);
        $period1 = random_int(20, 40);

        $tmp = imagecreatetruecolor($width, $height);
        imagefill($tmp, 0, 0, $bgColor);
        imagecopy($tmp, $img, 0, 0, 0, 0, $width, $height);

        for ($y = 0; $y < $height; $y++) {
            $shift = (int)($amp1 * sin($y / $period1 * 2 * M_PI));
            imagecopy($img, $tmp, max(0, $shift), $y, max(0, -$shift), $y, $width - abs($shift), 1);
        }
        imagedestroy($tmp);

        // ── موج عمودی ──
        $amp2    = random_int(2, 4);
        $period2 = random_int(30, 50);

        $tmp2 = imagecreatetruecolor($width, $height);
        imagefill($tmp2, 0, 0, $bgColor);
        imagecopy($tmp2, $img, 0, 0, 0, 0, $width, $height);

        for ($x = 0; $x < $width; $x++) {
            $shift = (int)($amp2 * sin($x / $period2 * 2 * M_PI));
            imagecopy($img, $tmp2, $x, max(0, $shift), $x, max(0, -$shift), 1, $height - abs($shift));
        }
        imagedestroy($tmp2);
    }

    /* ─── حروف شبح ─── */

    private static function drawGhostChars($img, int $width, int $height, int $count, ?string $font): void
    {
        $chars  = 'ABCDEFGHJKLMNPRSTUVWXYZ23456789';
        $maxIdx = strlen($chars) - 1;

        for ($i = 0; $i < $count; $i++) {
            $char  = $chars[random_int(0, $maxIdx)];
            $x     = random_int(5, $width - 25);
            $y     = random_int(15, $height - 5);
            $color = imagecolorallocate($img,
                random_int(180, 210), random_int(180, 210), random_int(180, 210));

            if ($font !== null) {
                imagettftext($img, random_int(14, 20), random_int(-40, 40), $x, $y, $color, $font, $char);
            } else {
                imagechar($img, 5, $x, $y, $char, $color);
            }
        }
    }

    /* ─── HTML ─── */

    public static function html(
        string  $imageUrl  = 'captcha_image.php',
        string  $inputName = 'captcha',
        ?string $lang      = null
    ): string {
        self::load();

        $lang   = $lang ?? self::$config['lang'];
        $labels = self::LABELS[$lang] ?? self::LABELS['fa'];
        $uid    = bin2hex(random_bytes(4));
        $w      = self::$config['width'];
        $h      = self::$config['height'];

        return <<<HTML
<div class="captcha-container" style="direction:{$labels['dir']}" id="captcha-{$uid}">
  <div class="captcha-box">
    <div class="captcha-image-wrap">
      <img src="{$imageUrl}" class="captcha-img" id="captcha-img-{$uid}"
           alt="CAPTCHA" width="{$w}" height="{$h}" draggable="false">
      <button type="button" class="captcha-refresh-btn"
        onclick="(function(b){
          var img=document.getElementById('captcha-img-{$uid}');
          img.src='{$imageUrl}?_='+Date.now();
          b.classList.add('captcha-spinning');
          setTimeout(function(){b.classList.remove('captcha-spinning')},700);
        })(this)" title="{$labels['refresh']}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="23 4 23 10 17 10"></polyline>
          <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
        </svg>
      </button>
    </div>
    <input type="text" name="{$inputName}" class="captcha-input"
           placeholder="{$labels['placeholder']}" autocomplete="off"
           required maxlength="10" dir="ltr" spellcheck="false">
  </div>
</div>
HTML;
    }

    /* ─── CSS ─── */

    public static function styles(): string
    {
        self::load();
        $w = self::$config['width'];
        $h = self::$config['height'];

        return <<<CSS
<style>
.captcha-container {
    font-family: 'Vazirmatn', 'Segoe UI', Tahoma, sans-serif;
    text-align: center;
    display: block;
}
.captcha-box {
    display: inline-flex;
    flex-direction: column;
    gap: 10px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 14px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    transition: box-shadow 0.3s ease;
}
.captcha-box:hover {
    box-shadow: 0 6px 24px rgba(0,0,0,0.07);
}
.captcha-image-wrap {
    position: relative;
    display: inline-block;
    width: {$w}px;
    height: {$h}px;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #e5e9f0;
    background: #f0f4f8;
    line-height: 0;
}
.captcha-img {
    display: block;
    width: {$w}px;
    height: {$h}px;
    user-select: none;
    -webkit-user-select: none;
    pointer-events: none;
}
.captcha-refresh-btn {
    position: absolute;
    left: 5px;
    top: 5px;
    width: 26px;
    height: 26px;
    border: none;
    border-radius: 7px;
    background: rgba(255,255,255,0.88);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    color: #64748b;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    box-shadow: 0 1px 5px rgba(0,0,0,0.06);
    padding: 0;
}
.captcha-refresh-btn:hover {
    background: #ffffff;
    color: #3b82f6;
    transform: scale(1.1);
}
.captcha-refresh-btn:active {
    transform: scale(0.92);
}
.captcha-refresh-btn svg {
    width: 14px;
    height: 14px;
}
@keyframes captcha-spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
.captcha-spinning svg {
    animation: captcha-spin 0.7s cubic-bezier(0.4,0,0.2,1);
}
.captcha-input {
    width: {$w}px;
    padding: 10px 12px;
    font-size: 16px;
    font-family: 'Courier New', Consolas, monospace;
    font-weight: bold;
    letter-spacing: 5px;
    text-align: center;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    color: #334155;
    outline: none;
    transition: all 0.2s ease;
    box-sizing: border-box;
}
.captcha-input:focus {
    border-color: #3b82f6;
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}
.captcha-input::placeholder {
    letter-spacing: normal;
    font-family: 'Vazirmatn', 'Segoe UI', Tahoma, sans-serif;
    font-size: 12px;
    font-weight: normal;
    color: #94a3b8;
}
</style>
CSS;
    }
}
