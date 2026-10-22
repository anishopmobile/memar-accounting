<?php
/** Reused by both product and bundle dialogs. Define $mediaWidgetPrefix. */
$mediaWidgetPrefix = isset($mediaWidgetPrefix) ? (string)$mediaWidgetPrefix : 'product';
$mediaFieldId = $mediaWidgetPrefix . 'MediaIds';
?>
<section class="media-editor" data-media-editor="<?php echo htmlspecialchars($mediaWidgetPrefix, ENT_QUOTES, 'UTF-8'); ?>">
  <h3>تصاویر <?php echo $mediaWidgetPrefix === 'bundle' ? 'بسته' : 'محصول'; ?></h3>
  <p class="setting-note">تصویر اول، تصویر اصلی است. حداکثر ۱۰ تصویر؛ تصاویر مشترک بدون کپی مجدد استفاده می‌شوند.</p>
  <input type="hidden" id="<?php echo $mediaFieldId; ?>" name="media_ids" value="[]">
  <div class="media-selected" role="list" aria-label="تصاویر انتخاب‌شده"></div>
  <div class="media-actions">
    <button type="button" class="button-secondary media-open-library">انتخاب از کتابخانه تصاویر</button>
    <button type="button" class="button-secondary media-open-upload">آپلود تصاویر جدید</button>
    <span class="media-editor-message" role="status" aria-live="polite"></span>
  </div>
</section>
