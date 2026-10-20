<div align="center">

# 🔒 Pure Captcha

**A secure, customizable and lightweight PHP CAPTCHA package**

**یک پکیج کپچای امن، قابل تنظیم و سبک برای PHP**

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Packagist](https://img.shields.io/packagist/v/aminarjmand/pure-captcha.svg)](https://packagist.org/packages/aminarjmand/pure-captcha)

[English](#english) | [فارسی](#فارسی)

</div>

---

<a name="english"></a>

# 🇬🇧 English

## 📖 About

**Pure Captcha** is a lightweight, dependency-free PHP CAPTCHA package that generates secure CAPTCHA images using only PHP's built-in GD extension. No external services, no JavaScript dependencies, no API keys required.

## ✨ Features

- 🎨 **Beautiful UI** — Modern, responsive design with gradient backgrounds and shadow effects
- 🔧 **Fully Customizable** — Configure length, size, fonts, colors, noise, and more
- 🛡️ **5 Difficulty Levels** — From `low` to `nightmare`
- 👻 **Ghost Characters** — Fake background characters to confuse bots
- 🌊 **Wave Distortion** — Sinusoidal warping to defeat OCR
- 🔤 **Multiple Character Sets** — Letters, numbers, special characters, or mixed
- 🌐 **Multi-language** — Built-in support for Persian (fa) and English (en)
- 🔄 **Refresh Button** — AJAX-powered image refresh with spin animation
- 📦 **Zero Dependencies** — Only requires PHP GD extension
- 🎯 **PSR-4 Autoloading** — Composer-ready with proper namespacing
- ⏱️ **Expiry Control** — Auto-expire CAPTCHA codes after configurable time
- 🔑 **Case Sensitivity** — Optional case-sensitive validation

## 📋 Requirements

- PHP >= 7.4
- GD Extension (`ext-gd`)
- Session Extension (`ext-session`)

## 📥 Installation

```bash
composer require aminarjmand/pure-captcha
```

## 🚀 Quick Start

### Step 1: Create the image endpoint

Create a file called `captcha_image.php`:

```php
<?php
require 'vendor/autoload.php';
use AminArjmand\PureCaptcha\Captcha;

Captcha::renderImage();
```

### Step 2: Add CAPTCHA to your form

```php
<?php
require 'vendor/autoload.php';
use AminArjmand\PureCaptcha\Captcha;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Form</title>
    <?= Captcha::styles() ?>
</head>
<body>
    <form method="POST" action="submit.php">

        <!-- Your other form fields -->
        <input type="text" name="name" placeholder="Your name">

        <!-- CAPTCHA -->
        <?= Captcha::html() ?>

        <button type="submit">Submit</button>
    </form>
</body>
</html>
```

### Step 3: Validate the CAPTCHA

Create `submit.php`:

```php
<?php
require 'vendor/autoload.php';
use AminArjmand\PureCaptcha\Captcha;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $captchaInput = $_POST['captcha'] ?? '';

    if (Captcha::validate($captchaInput)) {
        echo '✅ CAPTCHA is valid!';
        // Process your form...
    } else {
        echo '❌ Invalid CAPTCHA. Please try again.';
    }
}
```

## ⚙️ Configuration

### Method 1: Configuration File

Create `captcha_config.php` in your project root:

```php
<?php
return [
    'lang'           => 'en',
    'font'           => 'Vazirmatn.ttf',
    'expiry'         => 300,
    'length'         => 5,
    'width'          => 170,
    'height'         => 55,
    'font_size'      => 22,
    'char_type'      => 'mixed',
    'case_sensitive' => false,
    'noise_level'    => 'medium',
];
```

### Method 2: Runtime Configuration

```php
Captcha::configure([
    'noise_level' => 'extreme',
    'length'      => 6,
    'lang'        => 'en',
    'char_type'   => 'letters',
]);
```

> ⚠️ **Note:** When using `configure()`, you must call it in **both** your form file and `captcha_image.php`, because each HTTP request is independent.

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `lang` | string | `'fa'` | Language: `'fa'` or `'en'` |
| `font` | string | `''` | Font filename (place in `fonts/` directory) or full path |
| `expiry` | int | `300` | CAPTCHA expiry time in seconds |
| `length` | int | `5` | Number of characters |
| `width` | int | `170` | Image width in pixels |
| `height` | int | `55` | Image height in pixels |
| `font_size` | int | `22` | Font size in points |
| `char_type` | string | `'mixed'` | Character set: `'mixed'`, `'letters'`, `'numbers'`, `'characters'` |
| `case_sensitive` | bool | `false` | Enable case-sensitive validation |
| `noise_level` | string | `'medium'` | Difficulty level (see below) |

### Character Sets
        
| Type | Characters |
|------|------------|
| `mixed` | `ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789~!@#$%^&*()-+` |
| `lettersB` | `ABCDEFGHJKLMNPRSTUVWXYZ` |
| `lettersS` | `abcdefghijklmnopqrstuvwxyz` |
| `numbers` | `0123456789` |
| `characters` | `~!@#$%^&*()-+` |

## 🎯 Difficulty Levels
        
| Level | Dots | Arcs | Lines | Rotation | Grid | Ghosts | Wave | Difficulty |
|-------|------|------|-------|----------|------|--------|------|------------|
| `low` | 1000 | 10 | 1 | ±15° | ❌ | ✅ (2) | ❌ | ⭐ |
| `medium` | 2000 | 20 | 2 | ±20° | ❌ | ✅ (4) | ❌ | ⭐⭐ |
| `high` | 3000 | 30 | 4 | ±25° | ✅ | ✅ (6) | ❌ | ⭐⭐⭐ |
| `extreme` | 4000 | 40 | 6 | ±35° | ✅ | ✅ (8) | ❌ | ⭐⭐⭐⭐ |
| `nightmare` | 5000 | 50 | 8 | ±40° | ✅ | ✅ (10) | ✅ | ⭐⭐⭐⭐⭐ |

### What each feature does:

- **Dots** — Random colored pixels scattered across the image
- **Arcs** — Curved lines in the background
- **Lines** — Straight lines drawn over the text
- **Rotation** — Random angle applied to each character
- **Grid** — A mesh grid overlay on the background
- **Ghosts** — Faint fake characters in the background to confuse OCR
- **Wave** — Sinusoidal pixel displacement that warps the entire image

## 📚 API Reference

### `Captcha::configure(array $options): void`

Set configuration options at runtime.

```php
Captcha::configure(['length' => 6, 'noise_level' => 'high']);
```

### `Captcha::get(string $key, mixed $default = null): mixed`

Read a configuration value.

```php
$lang = Captcha::get('lang'); // 'fa'
```

### `Captcha::generate(): string`

Generate a new CAPTCHA code and store it in the session. Returns the generated code.

```php
$code = Captcha::generate();
```

### `Captcha::validate(string $input): bool`

Validate user input against the stored CAPTCHA code. Returns `true` if valid. The stored code is destroyed after validation (one-time use).

```php
if (Captcha::validate($_POST['captcha'])) {
    // Valid!
}
```

### `Captcha::renderImage(): void`

Generate and output a CAPTCHA PNG image. Sends headers and exits.

```php
Captcha::renderImage();
```

### `Captcha::html(string $imageUrl, string $inputName, ?string $lang): string`

Generate the HTML markup for the CAPTCHA widget.

```php
echo Captcha::html('captcha_image.php', 'captcha', 'en');
```

| Parameter | Default | Description |
|-----------|---------|-------------|
| `$imageUrl` | `'captcha_image.php'` | URL of the image endpoint |
| `$inputName` | `'captcha'` | Name attribute of the input field |
| `$lang` | `null` (uses config) | Override language |

### `Captcha::styles(): string`

Generate the CSS styles for the CAPTCHA widget.

```php
echo Captcha::styles();
```

## 🎨 Custom Fonts

1. Place your `.ttf` font file in the `fonts/` directory inside the package, or provide a full path.
2. Set the font in config:

```php
// Filename only (looks in package's fonts/ directory)
'font' => 'MyCustomFont.ttf'

// Or full path
'font' => '/path/to/fonts/MyCustomFont.ttf'
```

If no font is configured or found, the package falls back to system fonts, and then to PHP's built-in bitmap font as a last resort.

## 🔧 Usage Examples

### Simple number-only CAPTCHA

```php
Captcha::configure([
    'char_type'   => 'numbers',
    'length'      => 4,
    'noise_level' => 'low',
]);
```

### High-security CAPTCHA

```php
Captcha::configure([
    'char_type'      => 'mixed',
    'length'         => 7,
    'noise_level'    => 'extreme',
    'case_sensitive' => true,
    'expiry'         => 120,
]);
```

### English CAPTCHA

```php
Captcha::configure(['lang' => 'en']);

// Or override in html() only
echo Captcha::html('captcha_image.php', 'captcha', 'en');
```

---

<a name="فارسی"></a>

<div dir="rtl" align="right">

# 🇮🇷 فارسی

## 📖 درباره پروژه



**Pure Captcha**

 یک پکیج سبک و بدون وابستگی برای تولید تصاویر کپچای امن در PHP است. فقط از اکستنشن GD خود PHP استفاده می‌کند. نیازی به سرویس خارجی، جاوااسکریپت اضافه یا کلید API ندارد.

</div>

## ✨ امکانات

- 🎨 **رابط کاربری زیبا** — طراحی مدرن با پس‌زمینه گرادیانت و افکت سایه
- 🔧 **کاملاً قابل تنظیم** — تعداد کاراکتر، اندازه، فونت، رنگ، نویز و ...
- 🛡️ **۵ سطح سختی** — از `low` تا `nightmare`
- 👻 **حروف شبح** — کاراکترهای جعلی در پس‌زمینه برای گیج کردن ربات‌ها
- 🌊 **اعوجاج موجی** — تاب دادن تصویر برای شکست OCR
- 🔤 **چند مجموعه کاراکتر** — حروف، اعداد، کاراکترهای خاص یا ترکیبی
- 🌐 **چندزبانه** — پشتیبانی داخلی از فارسی و انگلیسی
- 🔄 **دکمه رفرش** — بارگذاری مجدد تصویر با انیمیشن چرخشی
- 📦 **بدون وابستگی** — فقط نیاز به اکستنشن GD
- 🎯 **PSR-4** — آماده استفاده با Composer
- ⏱️ **انقضای خودکار** — کد کپچا بعد از زمان مشخصی منقضی می‌شود
- 🔑 **حساسیت به حروف** — امکان فعال‌سازی تشخیص بزرگ/کوچک بودن حروف




## 📋 پیش‌نیازها


- PHP نسخه ۷.۴ به بالا
- اکستنشن GD
- اکستنشن Session


## 📥 نصب

```bash
composer require aminarjmand/pure-captcha
```

## 🚀 شروع سریع

### مرحله ۱: ساخت فایل تصویر کپچا

فایلی به نام `captcha_image.php` بسازید:

```php
<?php
require 'vendor/autoload.php';
use AminArjmand\PureCaptcha\Captcha;

Captcha::renderImage();
```

### مرحله ۲: اضافه کردن کپچا به فرم

```php
<?php
require 'vendor/autoload.php';
use AminArjmand\PureCaptcha\Captcha;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فرم من</title>
    <?= Captcha::styles() ?>
</head>
<body>
    <form method="POST" action="submit.php">

        <!-- فیلدهای دیگر فرم -->
        <input type="text" name="name" placeholder="نام شما">

        <!-- کپچا -->
        <?= Captcha::html() ?>

        <button type="submit">ارسال</button>
    </form>
</body>
</html>
```

### مرحله ۳: اعتبارسنجی کپچا

فایل `submit.php` بسازید:

```php
<?php
require 'vendor/autoload.php';
use AminArjmand\PureCaptcha\Captcha;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $captchaInput = $_POST['captcha'] ?? '';

    if (Captcha::validate($captchaInput)) {
        echo '✅ کد امنیتی صحیح است!';
        // پردازش فرم...
    } else {
        echo '❌ کد امنیتی اشتباه است. لطفاً دوباره تلاش کنید.';
    }
}
```

## ⚙️ پیکربندی

### روش ۱: فایل کانفیگ

فایل `captcha_config.php` را در روت پروژه بسازید:

```php
<?php
return [
    'lang'           => 'fa',
    'font'           => 'Vazirmatn.ttf',
    'expiry'         => 300,
    'length'         => 5,
    'width'          => 170,
    'height'         => 55,
    'font_size'      => 22,
    'char_type'      => 'mixed',
    'case_sensitive' => false,
    'noise_level'    => 'medium',
];
```

### روش ۲: پیکربندی در زمان اجرا

```php
Captcha::configure([
    'noise_level' => 'extreme',
    'length'      => 6,
    'lang'        => 'fa',
    'char_type'   => 'letters',
]);
```

> ⚠️ **توجه:** اگر از `configure()` استفاده می‌کنید، باید آن را هم در فایل فرم و هم در `captcha_image.php` فراخوانی کنید، زیرا هر درخواست HTTP مستقل است.

### جدول تنظیمات

| تنظیم | نوع | پیش‌فرض | توضیح |
|--------|------|---------|--------|
| `lang` | string | `'fa'` | زبان: `'fa'` یا `'en'` |
| `font` | string | `''` | نام فایل فونت (در پوشه `fonts/`) یا مسیر کامل |
| `expiry` | int | `300` | زمان انقضای کپچا (ثانیه) |
| `length` | int | `5` | تعداد کاراکترها |
| `width` | int | `170` | عرض تصویر (پیکسل) |
| `height` | int | `55` | ارتفاع تصویر (پیکسل) |
| `font_size` | int | `22` | اندازه فونت |
| `char_type` | string | `'mixed'` | مجموعه کاراکتر |
| `case_sensitive` | bool | `false` | حساسیت به بزرگ/کوچکی حروف |
| `noise_level` | string | `'medium'` | سطح سختی |

### مجموعه کاراکترها

| نوع | کاراکترها |
|------|-----------|
| `mixed` | `ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789~!@#$%^&*()-+` |
| `lettersB` | `ABCDEFGHJKLMNPRSTUVWXYZ` |
| `lettersS` | `abcdefghijklmnopqrstuvwxyz` |
| `numbers` | `0123456789` |
| `characters` | `~!@#$%^&*()-+` |

## 🎯 سطوح سختی

| سطح | نقاط | کمان | خطوط | چرخش | شبکه | شبح | موج | سختی |
|------|------|------|-------|--------|-------|------|------|--------|
| `low` | ۱۰۰۰ | ۱۰ | ۱ | ±۱۵° | ❌ | ✅ (۲) | ❌ | ⭐ |
| `medium` | ۲۰۰۰ | ۲۰ | ۲ | ±۲۰° | ❌ | ✅ (۴) | ❌ | ⭐⭐ |
| `high` | ۳۰۰۰ | ۳۰ | ۴ | ±۲۵° | ✅ | ❌ | ✅ (۶) | ⭐⭐⭐ |
| `extreme` | ۴۰۰۰ | ۴۰ | ۶ | ±۳۵° | ✅ | ✅ (۸) | ❌ | ⭐⭐⭐⭐ |
| `nightmare` | ۵۰۰۰ | ۵۰ | ۸ | ±۴۰° | ✅ | ✅ (۱۰) | ✅ | ⭐⭐⭐⭐⭐ |


### هر ویژگی چه کاری انجام می‌دهد:

- **نقاط (Dots)** — پیکسل‌های رنگی تصادفی در سطح تصویر
- **کمان‌ها (Arcs)** — خطوط منحنی در پس‌زمینه
- **خطوط (Lines)** — خطوط مستقیم روی متن
- **چرخش (Rotation)** — زاویه تصادفی برای هر کاراکتر
- **شبکه (Grid)** — شبکه توری روی پس‌زمینه
- **شبح (Ghosts)** — کاراکترهای کمرنگ جعلی در پس‌زمینه برای گیج کردن OCR
- **موج (Wave)** — جابجایی سینوسی پیکسل‌ها که کل تصویر را تاب می‌دهد

## 📚 مرجع API

### `Captcha::configure(array $options): void`

تنظیمات را در زمان اجرا تغییر می‌دهد.

```php
Captcha::configure(['length' => 6, 'noise_level' => 'high']);
```

### `Captcha::get(string $key, mixed $default = null): mixed`

مقدار یک تنظیم را برمی‌گرداند.

```php
$lang = Captcha::get('lang'); // 'fa'
```

### `Captcha::generate(): string`

یک کد کپچای جدید تولید و در سشن ذخیره می‌کند. کد تولیدشده را برمی‌گرداند.

```php
$code = Captcha::generate();
```

### `Captcha::validate(string $input): bool`

ورودی کاربر را با کد ذخیره‌شده مقایسه می‌کند. در صورت صحت `true` برمی‌گرداند. کد ذخیره‌شده بعد از اعتبارسنجی حذف می‌شود (یکبار مصرف).

```php
if (Captcha::validate($_POST['captcha'])) {
    // معتبر است!
}
```

### `Captcha::renderImage(): void`

تصویر PNG کپچا را تولید و به خروجی ارسال می‌کند.

```php
Captcha::renderImage();
```

### `Captcha::html(string $imageUrl, string $inputName, ?string $lang): string`

کد HTML ویجت کپچا را تولید می‌کند.

```php
echo Captcha::html('captcha_image.php', 'captcha', 'fa');
```

| پارامتر | پیش‌فرض | توضیح |
|----------|---------|--------|
| `$imageUrl` | `'captcha_image.php'` | آدرس فایل تصویر |
| `$inputName` | `'captcha'` | نام فیلد ورودی |
| `$lang` | `null` (از کانفیگ) | زبان |

### `Captcha::styles(): string`

استایل‌های CSS ویجت کپچا را تولید می‌کند.

```php
echo Captcha::styles();
```

## 🎨 فونت سفارشی

1. فایل فونت `.ttf` خود را در پوشه `fonts/` داخل پکیج قرار دهید یا مسیر کامل را وارد کنید.
2. فونت را در کانفیگ تنظیم کنید:

```php
// فقط نام فایل (در پوشه fonts/ پکیج جستجو می‌شود)
'font' => 'MyCustomFont.ttf'

// یا مسیر کامل
'font' => '/path/to/fonts/MyCustomFont.ttf'
```

اگر فونتی تنظیم نشده باشد، پکیج ابتدا فونت‌های سیستمی و سپس فونت داخلی PHP را استفاده می‌کند.

## 🔧 مثال‌های کاربردی

### کپچای فقط عددی

```php
Captcha::configure([
    'char_type'   => 'numbers',
    'length'      => 4,
    'noise_level' => 'low',
]);
```

### کپچای امنیت بالا

```php
Captcha::configure([
    'char_type'      => 'mixed',
    'length'         => 7,
    'noise_level'    => 'extreme',
    'case_sensitive' => true,
    'expiry'         => 120,
]);
```

### کپچای انگلیسی

```php
Captcha::configure(['lang' => 'en']);

// یا فقط در html() تغییر زبان
echo Captcha::html('captcha_image.php', 'captcha', 'en');
```

---

## 📁 Project Structure / ساختار پروژه

```
pure-captcha/
├── src/
│   └── Captcha.php          # Main class / کلاس اصلی
├── config/
│   └── captcha.php          # Default config / کانفیگ پیش‌فرض
├── fonts/
│   └── .gitkeep             # Place your .ttf fonts here / فونت‌ها اینجا
├── composer.json
├── README.md
└── LICENSE
```

## 🤝 Contributing / مشارکت

Contributions, issues and feature requests are welcome!

از مشارکت، گزارش مشکلات و پیشنهاد ویژگی‌های جدید استقبال می‌شود!

## 📄 License / مجوز

This project is licensed under the [MIT License](LICENSE).

این پروژه تحت مجوز [MIT](LICENSE) منتشر شده است.

## 👤 Author / توسعه‌دهنده

**Amin Arjmand**

- 🌐 Website: [aminarjmand.com](https://aminarjmand.com)
- 📧 Email: [info@aminarjmand.com](mailto:info@aminarjmand.com)
- 🐙 GitHub: [@sibche2013](https://github.com/sibche2013)

---

<div align="center">

Made with ❤️ by [Amin Arjmand](https://aminarjmand.com)

</div>
