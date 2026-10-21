<div class="card" id="mediaLibraryPage" style="padding:20px">
  <div class="page-actions">
    <h2 style="margin:0">کتابخانه مرکزی تصاویر</h2>
    <button id="mediaManagerUpload" type="button" class="button">+ آپلود تصاویر جدید</button>
  </div>
  <p class="setting-note">تصاویر این کتابخانه بین چند محصول یا دسته‌بندی قابل استفاده هستند. حذف فقط زمانی امکان‌پذیر است که هیچ وابستگی فعالی وجود نداشته باشد.</p>
  <div class="media-picker-tools">
    <input type="search" id="mediaManagerSearch" placeholder="جستجو بر اساس نام تصویر">
    <select id="mediaManagerUsage">
      <option value="all">تمام تصاویر</option>
      <option value="used">در حال استفاده</option>
      <option value="unused">بدون استفاده</option>
    </select>
  </div>
  <div class="media-manager-grid" id="mediaManagerGrid" aria-live="polite">در حال دریافت تصاویر...</div>
  <div class="media-pages">
    <button id="mediaManagerPrev" type="button">قبلی</button>
    <span id="mediaManagerPageText" aria-live="polite"></span>
    <button id="mediaManagerNext" type="button">بعدی</button>
  </div>
</div>
