# Product Media Library — Feature Branch

This feature adds a central media library without changing monetary or inventory
calculation functions. Images are intentionally **live metadata**: old invoices
render the currently selected product image, not a historical snapshot.

## Deployment requirements

- PHP >= 8.1, extensions: mysqli, fileinfo, GD (JPEG/PNG/WebP), mbstring,
  ZIP (for full backup/restore), existing mPDF dependencies.
- MySQL/MariaDB using InnoDB and existing product tables.
- A **private writable directory outside the public document root** is used for
  originals and thumbnails. Default path: `dirname($_SERVER['DOCUMENT_ROOT'])/memar_media` (outside the server's served document root).
  Set `MEMAR_MEDIA_STORAGE` to an absolute private directory if the web server
  cannot write to the default. Restrict owner-only permissions and deny serving
  that path directly in your web server.
- Ensure PHP `upload_max_filesize` >= 5M and `post_max_size` >= 6M. Image
  dimensions are limited to 3000x3000 and 9 million pixels.
- Frontend Dropzone.js **5.9.3** JS/CSS are bundled locally under
  `assets/vendor/dropzone/` with MIT license; no CDN is used.

## Migration

`assets/php/db.php` creates `media`, `product_media`, `category_media`,
`media_display_settings` using `CREATE TABLE IF NOT EXISTS`. Migrations
never reset the existing `products`, `product_units`, `invoices`,
`invoice_items`, `stock_movements` or Excel structures. Existing products
have zero images until the user adds some. Initial print switches are all off.

`product_media(product_id, media_id)` is a unique many-to-many link; ordering
decides primary (lowest sort order). A media file can be shared across any
number of products and categories. Removing it from a product only removes
the relation. Deleting from the central library is refused whenever any active
product/category references it.

## Usage

1. Open **کالاها و خدمات**, create/edit a product or bundle.
2. Under **تصاویر محصول**, use **آپلود تصاویر جدید** (Dropzone) or
   **انتخاب از کتابخانه تصاویر**.
3. Reorder using up/down controls; first picture is primary. Removing the
   picture only unlinks it.
4. The independent **کتابخانه تصاویر** page provides searchable/paginated
   media management, filters used/unused, and safe deletion.
5. In product category editor, select or unlink a category photo.
6. In **تنظیمات**, before branding, configure independent UI and PDF switches.
   PDFs with image switches off preserve their legacy table composition.

## Full backups and recoveries

The legacy SQL backup remains separate. A SQL file **alone does not contain
private media files**. Use **پشتیبان کامل حسابداری و تصاویر (ZIP)** for an
archive containing `database.sql`, `manifest.json` and `media/original`
and `media/thumb` with SHA-256 checksums.

**Restore into a controlled test installation first.** Restoring a full ZIP
replaces all current database tables and installs its referenced media files;
it is not a merge operation. Verification rejects unknown ZIP paths, duplicate
entries, oversized archives and checksum discrepancies before applying the
restore. The legacy SQL importer is destructive, so recovery of the prior SQL
dump after an import error is best-effort; an independent offsite backup
remains necessary. Existing unreferenced physical media is retained after a
restore rather than being deleted.

## Security and operational notes

- Uploaded content is independently validated using fileinfo + getimagesize,
  decoded and re-encoded with GD (thus stripping EXIF/embedded payloads).
- Non-predictable filenames and restricted path grammar are enforced;
  originals are never stored under the project public directory.
- All media and backup operations require an authenticated session; all
  mutations validate CSRF. Image streaming is an authenticated PHP endpoint.
- Media picker performs AJAX pagination (24/page); product search includes only
  a single primary-media ID and does not duplicate any image files.
- Product/bundle images are persisted *inside* the existing SQL transaction.
  They never participate in monetary calculations.
- Rendering the PDF with images enabled should be visually verified in both
  landscape and portrait modes with multi-page documents and missing images.
- No Excel import/export or payment gateway source has been edited.

## Tests and release gate

- `node --check assets/js/media-ui.js`
- `php -l` for every touched PHP source and template
- `php tests/media_smoke.php` **ONLY in a disposable MariaDB database**.
- Manual end-to-end browser tests: upload single/multiple/invalid files,
  picker search/pagination/re-use, shared deletion safeguards, edits, bundles,
  categories, finance/stock invariants, each of five PDF print families,
  landscape/portrait page breaks, low-stock report, full ZIP restore, RTL
  desktop/mobile, and login/CSRF denial.
- GitHub Actions workflow `.github/workflows/media-ci.yml` automates parsing
  and database smoke checks on the feature branch.

**Do not merge to main without a completed CI run and real browser/PDF/restore
verification on a staging deployment.**
