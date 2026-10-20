<div class="card">
    <div class="settings-tabs">
        <button type="button" class="active" data-target="store-info">اطلاعات فروشگاه</button>
        <button type="button" data-target="invoice-settings">تنظیمات فاکتور</button>
        <button type="button" data-target="taxes">مالیات و تخفیف</button>
        <button type="button" data-target="security">امنیت ورود</button>
        <button type="button" data-target="database">پایگاه داده</button>
    </div>
    <div id="store-info" class="settings-section">
        <form id="storeSettingsForm" enctype="multipart/form-data">
            <div class="form-grid">
                <div class="form-row"><label for="storeName">نام فروشگاه / شخص</label><input type="text" id="storeName" name="store_name" required></div>
                <div class="form-row"><label for="economicCode">کد اقتصادی</label><input type="text" id="economicCode" name="economic_code"></div>
                <div class="form-row"><label for="nationalCode">کد ملی</label><input type="text" id="nationalCode" name="national_code"></div>
                <div class="form-row"><label for="registrationNumber">شماره ثبت</label><input type="text" id="registrationNumber" name="registration_number"></div>
                <div class="form-row searchable-select" data-target="province">
                    <label for="provinceSearch">استان</label>
                    <input type="search" id="provinceSearch" placeholder="جستجو استان...">
                    <select id="provinceSelect" name="province_id">
                        <option value="">انتخاب کنید</option>
                    </select>
                </div>
                <div class="form-row searchable-select" data-target="city">
                    <label for="citySearch">شهر</label>
                    <input type="search" id="citySearch" placeholder="جستجو شهر..." disabled>
                    <select id="citySelect" name="city_id" disabled>
                        <option value="">ابتدا استان را انتخاب کنید</option>
                    </select>
                </div>
                <div class="form-row"><label for="postalCode">کد پستی</label><input type="text" id="postalCode" name="postal_code"></div>
                <div class="form-row"><label for="phone">تلفن</label><input type="text" id="phone" name="phone"></div>
                <div class="form-row" style="grid-column: span 2;"><label for="address">آدرس کامل</label><textarea id="address" name="address" rows="4"></textarea></div>
            </div>
            <div class="card image-section" style="margin-top: 24px; padding: 24px;">
                <h2>تصاویر</h2>
                <div class="upload-grid">
                    <label class="upload-card image-upload-field" data-input="logoImage" tabindex="0">
                        <div class="upload-preview"><img id="logoPreview" src="" alt="پیش‌نمایش لوگو" hidden></div>
                        <div class="upload-icon">🖼️</div>
                        <div class="upload-label">لوگو فروشگاه</div>
                        <input type="file" id="logoImage" name="logo_image" accept="image/*" hidden>
                    </label>
                    <label class="upload-card image-upload-field" data-input="signatureImage" tabindex="0">
                        <div class="upload-preview"><img id="signaturePreview" src="" alt="پیش‌نمایش امضا" hidden></div>
                        <div class="upload-icon">🖼️</div>
                        <div class="upload-label">امضای فروشنده</div>
                        <input type="file" id="signatureImage" name="signature_image" accept="image/*" hidden>
                    </label>
                    <label class="upload-card image-upload-field" data-input="stampImage" tabindex="0">
                        <div class="upload-preview"><img id="stampPreview" src="" alt="پیش‌نمایش مهر" hidden></div>
                        <div class="upload-icon">🖼️</div>
                        <div class="upload-label">مهر فروشنده</div>
                        <input type="file" id="stampImage" name="stamp_image" accept="image/*" hidden>
                    </label>
                </div>
                <div class="form-row" style="margin-top: 18px; max-width: 240px;"><label for="defaultSize">اندازه پیش‌فرض مهر و امضاء (٪)</label><input type="number" id="defaultSize" name="default_size_percentage" min="1" max="300" value="80"></div>
            </div>
            <div class="form-actions">
                <button class="button" type="submit">ذخیره اطلاعات فروشگاه</button>
                <span class="settings-save-message" aria-live="polite"></span>
            </div>
        </form>
    </div>
    <div id="invoice-settings" class="settings-section" style="display:none;">
        <form id="invoiceSettingsForm">
            <div class="form-grid">
                <div class="form-row">
                    <label for="officialDirection">جهت فاکتور رسمی</label>
                    <select id="officialDirection" name="official_invoice_direction">
                        <option value="vertical">عمودی</option>
                        <option value="horizontal">افقی</option>
                    </select>
                </div>
                <div class="form-row">
                    <label for="unofficialDirection">جهت فاکتور غیررسمی</label>
                    <select id="unofficialDirection" name="unofficial_invoice_direction">
                        <option value="vertical">عمودی</option>
                        <option value="horizontal">افقی</option>
                    </select>
                </div>
                <div class="form-row">
                    <label for="proformaTitle">عنوان پیش‌فاکتور</label>
                    <input type="text" id="proformaTitle" name="proforma_title" placeholder="مثلاً: پیش‌فاکتور فروش">
                </div>
                <div class="form-row">
                    <label for="invoiceTemplateColor">رنگ قالب فاکتور</label>
                    <input type="color" id="invoiceTemplateColor" name="invoice_template_color" value="#2068ff">
                </div>
                <div class="form-row" style="grid-column: span 2;">
                    <label for="unofficialInvoiceDesc">توضیحات فاکتور غیررسمی</label>
                    <textarea id="unofficialInvoiceDesc" name="unofficial_invoice_desc" rows="4" placeholder="متن بلند توضیحات فاکتور غیررسمی..."></textarea>
                </div>
                <div class="form-row" style="grid-column: span 2;">
                    <label for="officialInvoiceDesc">توضیحات فاکتور رسمی</label>
                    <textarea id="officialInvoiceDesc" name="official_invoice_desc" rows="4" placeholder="متن بلند توضیحات فاکتور رسمی..."></textarea>
                </div>
                <div class="form-row" style="grid-column: span 2;">
                    <label for="proformaDesc">توضیحات پیش‌فاکتور</label>
                    <textarea id="proformaDesc" name="proforma_desc" rows="4" placeholder="متن بلند توضیحات پیش‌فاکتور..."></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button class="button" type="submit">ذخیره تنظیمات فاکتور</button>
                <span class="invoice-settings-save-message" aria-live="polite"></span>
            </div>
        </form>
    </div>
    <div id="taxes" class="settings-section" style="display:none;">
        <form id="taxSettingsForm">
            <div class="form-grid">
                <div class="form-row" style="grid-column: span 2;">
                    <label>وضعیت مالیات</label>
                    <div class="toggle-row">
                        <label class="switch" for="taxEnabled">
                            <input type="checkbox" id="taxEnabled" name="tax_enabled" value="1">
                            <span class="slider"></span>
                        </label>
                        <span id="taxStatusText" class="toggle-text">مالیات غیرفعال است</span>
                    </div>
                </div>
                <div class="form-row" style="grid-column: span 2;">
                    <label for="taxRate">نرخ مالیات (٪)</label>
                    <input type="number" id="taxRate" name="tax_rate" min="0" max="100" step="0.01" value="0">
                </div>
            </div>
            <div class="form-actions">
                <button class="button" type="submit">ذخیره تنظیمات مالیات</button>
                <span class="tax-settings-save-message" aria-live="polite"></span>
            </div>
        </form>
    </div>
    <div id="database" class="settings-section" style="display:none;">
        <h2>تهیه نسخه پشتیبان</h2>
        <div class="card panel" style="margin-top: 12px;">
            <div class="form-row">
                <label for="backupDirInput">آدرس پوشه ذخیره پشتیبان</label>
                <input type="text" id="backupDirInput" value="C:\backups" placeholder="مثلاً: C:\backups  یا  MyBackups" autocomplete="off">
                <p class="setting-note">مسیر ذخیره‌سازی پشتیبان را وارد کنید (مثلاً <span dir="ltr">C:\backups</span> یا نام پوشه‌ای در پوشه backups پروژه). سپس «ذخیره آدرس» را بزنید تا برای دفعات بعدی حفظ شود.</p>
            </div>
            <div class="form-actions" style="gap: 12px; flex-wrap: wrap;">
                <button class="button-secondary" type="button" id="saveBackupDir">ذخیره آدرس</button>
                <button class="button" type="button" id="createBackup">تهیه نسخه پشتیبان</button>
                <button class="button-secondary" type="button" id="createFilesBackup">🗂️ تهیه نسخه پشتیبان از تمام فایل‌ها</button>
                <span class="backup-message" aria-live="polite"></span>
            </div>
        </div>

        <h2 style="margin-top: 28px;">بازیابی نسخه پشتیبان</h2>
        <div class="card panel" style="margin-top: 12px;">
            <div class="form-row">
                <label>فایل پشتیبان (SQL)</label>
                <button type="button" class="file-pick-card" id="restoreFilePick">
                    <span class="file-pick-meta">
                        <span class="file-pick-icon">🗄️</span>
                        <span>
                            <span class="file-pick-name" id="restoreFileName">انتخاب فایل SQL...</span>
                            <small>با کلیک یک فایل با پسوند .sql انتخاب کنید</small>
                        </span>
                    </span>
                    <span class="db-browse">انتخاب فایل</span>
                </button>
                <input type="file" id="restoreFileInput" accept=".sql,application/sql" style="display:none;">
            </div>
            <div class="form-actions" style="gap: 12px; flex-wrap: wrap;">
                <button class="button" type="button" id="restoreBackup">بازیابی نسخه پشتیبان</button>
                <span class="restore-message" aria-live="polite"></span>
            </div>
        </div>
    </div>
    <div id="security" class="settings-section" style="display:none;">
        <input type="hidden" id="authSecurityCsrfToken" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

        <h2>کپچا (کد امنیتی)</h2>
        <p class="setting-note">تولید و اعتبارسنجی کپچا کاملاً روی همین سرور انجام می‌شود؛ هیچ سرویس خارجی استفاده نمی‌شود.</p>

        <div class="form-grid">
            <div class="form-row">
                <div class="toggle-row">
                    <label class="switch" for="secLoginCaptcha">
                        <input type="checkbox" id="secLoginCaptcha">
                        <span class="slider"></span>
                    </label>
                    <span class="toggle-text">کپچا هنگام ورود</span>
                </div>
            </div>
            <div class="form-row">
                <div class="toggle-row">
                    <label class="switch" for="secRegCaptcha">
                        <input type="checkbox" id="secRegCaptcha">
                        <span class="slider"></span>
                    </label>
                    <span class="toggle-text">کپچا هنگام ثبت‌نام اولیه</span>
                </div>
            </div>
            <div class="form-row">
                <label for="secCaptchaLang">زبان رابط کپچا</label>
                <select id="secCaptchaLang">
                    <option value="fa">فارسی</option>
                    <option value="en">English</option>
                </select>
            </div>
            <div class="form-row">
                <label for="secCaptchaCharType">نوع کاراکتر</label>
                <select id="secCaptchaCharType">
                    <option value="mixed">حروف بزرگ + کوچک + اعداد</option>
                    <option value="numbers">فقط اعداد</option>
                    <option value="characters">فقط کاراکترهای خاص</option>
                    <option value="lettersB">فقط حروف بزرگ</option>
                    <option value="lettersS">فقط حروف کوچک</option>
                </select>
            </div>
            <div class="form-row">
                <label for="secCaptchaNoise">سطح نویز</label>
                <select id="secCaptchaNoise">
                    <option value="low">کم</option>
                    <option value="medium">متوسط</option>
                    <option value="high">زیاد</option>
                    <option value="extreme">خیلی زیاد</option>
                    <option value="nightmare">کابوس!</option>
                </select>
            </div>
            <div class="form-row">
                <label for="secCaptchaLength">تعداد کاراکتر (۴ تا ۸)</label>
                <input type="number" id="secCaptchaLength" min="4" max="8">
            </div>
            <div class="form-row">
                <label for="secCaptchaWidth">عرض تصویر بر حسب پیکسل (۱۲۰ تا ۵۰۰)</label>
                <input type="number" id="secCaptchaWidth" min="120" max="500">
            </div>
            <div class="form-row">
                <label for="secCaptchaHeight">ارتفاع تصویر بر حسب پیکسل (۴۰ تا ۱۶۰)</label>
                <input type="number" id="secCaptchaHeight" min="40" max="160">
            </div>
            <div class="form-row">
                <label for="secCaptchaFontSize">اندازه فونت (۱۲ تا ۷۲)</label>
                <input type="number" id="secCaptchaFontSize" min="12" max="72">
            </div>
            <div class="form-row">
                <label for="secCaptchaExpiry">مدت اعتبار کد (۶۰ تا ۹۰۰ ثانیه)</label>
                <input type="number" id="secCaptchaExpiry" min="60" max="900">
            </div>
            <div class="form-row">
                <div class="toggle-row">
                    <label class="switch" for="secCaptchaCaseSensitive">
                        <input type="checkbox" id="secCaptchaCaseSensitive">
                        <span class="slider"></span>
                    </label>
                    <span class="toggle-text">حساس به بزرگ/کوچک بودن حروف</span>
                </div>
            </div>
        </div>

        <h2 style="margin-top: 24px;">ضد حمله‌ی جستجوی رمز (Brute Force)</h2>
        <p class="setting-note">سوابق تلاش‌های ناموفق به‌صورت پایدار در دیتابیس ذخیره می‌شوند و پاک‌کردن کوکی/سشن توسط مهاجم تأثیری در محافظت ندارد.</p>

        <div class="form-grid">
            <div class="form-row">
                <label for="secBruteMax">حداکثر تلاش ناموفق (۳ تا ۵۰)</label>
                <input type="number" id="secBruteMax" min="3" max="50">
            </div>
            <div class="form-row">
                <label for="secBruteWindow">بازه‌ی شمارش به دقیقه (۱ تا ۶۰)</label>
                <input type="number" id="secBruteWindow" min="1" max="60">
            </div>
            <div class="form-row">
                <label for="secBruteLockout">مدت قفل به دقیقه (۱ تا ۱۲۰)</label>
                <input type="number" id="secBruteLockout" min="1" max="120">
            </div>
        </div>

        <div class="form-actions">
            <button class="button" type="button" id="authSecuritySave">ذخیره تنظیمات امنیت</button>
            <span class="auth-security-message" aria-live="polite"></span>
        </div>
    </div>
</div>

<script src="assets/js/auth.js" defer></script>