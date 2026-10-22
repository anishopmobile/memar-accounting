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


## Follow-up QA fixes (2026-10-22)

- Read-only image streaming skips expensive schema initialization, releases the
  PHP session lock before sending bytes, and responds with a per-login private
  browser cache (ETag, conditional 304, and `Vary: Cookie`). Gallery grids
  use lazy-loaded fixed-size thumbnails, debounced/cancellable AJAX queries,
  and lightbox immediately displays the cached thumbnail while fetching the
  **actual media original ID**. Image placeholders are not clickable originals.
- Sales/purchase invoice and proforma product pickers now show pictures in an
  independent **تصویر** column; chosen product IDs carry their media IDs into
  an independent photo column for line items. Sales service photographs follow
  the same rule. Invoice calculations and financial snapshots were not edited.
- Uploads to the **central library** are unlimited in total (within storage
  quotas and per-file limits). At most **10 media relationships per product**
  remain enforced by server and UI, independently of overall library size.
  A product save cannot proceed while its Dropzone upload queue is pending.
- Media folders are **logical DB organization**, not duplicate physical copies.
  Create a folder in the picker or manager, browse a folder or all files,
  upload into the selected folder, and move an existing photo between folders
  through the manager. The same media can still be attached to many products.
  Existing files remain in the unfiled group until explicitly moved.
- Defective goods can link up to 10 independent photographic records when
  registering damage; their first thumbnail appears in both initial and AJAX
  reports. These links are recorded within the existing defective-stock
  transaction; they are not changes to a product's current gallery.
- Full ZIP recovery still validates **every** archived file and SHA-256 value,
  but does not stage/copy a file when its correct contents already exist.
  SQL database restoration retains existing safety/error behavior; its runtime
  depends on the number of SQL statements and server I/O. Restores must
  always be tested on a disposable/staging installation.

### Focused CI checks

The feature branch's `media-ci.yml` performs PHP syntax checks, JavaScript
parsing, transactional schema/linkage/financial-invariant smoke tests, actual
mPDF image/no-image rendering, authenticated HTTP checks, ZIP backup/restore,
new-product media search, folders, damaged-item photo registration, and upload
of more than ten distinct files to the library.

### Staging acceptance checks remaining

1. Open a product and bundle image picker on mobile/desktop; select and upload
   media in a folder while rapidly searching and changing result pages.
2. Create a brand-new product with images while an upload is pending; the save
   should block until it finishes. Find the product by code/name and click its
   thumbnail to verify the original (not the product ID) appears in lightbox.
3. Toggle sales/purchase UI photos and check both standard and proforma invoices;
   verify the **تصویر** column exists only when enabled, is in the correct
   location, and is populated for both newly selected and previously saved
   line items. Re-check totals, discounts, tax and multi-unit/barcode selection.
4. Register defective goods with a separately uploaded damage photo, verify
   the report thumbnail/lightbox, and return an item to stock without losing
   the photograph.
5. Create more than ten library images, then link at most ten to a single
   product; reuse/move the same photo across category folders without file
   duplication. Try deleting a photo still linked to defective goods.
6. Compare first-load and repeat-load speed of a 50-item product modal,
   multiple rapid search queries, and lightbox. Verify browser Network tab
   reports `304` when requesting unchanged images with `If-None-Match`.
7. Compare full ZIP recovery elapsed time and integrity in staging with mostly
   pre-existing vs missing media files. Check that all amounts and quantities
   before/after recovery match, and logged-out image requests are denied.

No files in Excel import/export, banking, or payment were changed for these
follow-up fixes. **Do not merge until the reviewer signs off on staging.**


## Gallery, folder smoothness, security and large-backup follow-up

The product and defective-goods thumbnail viewer now requests the **linked gallery**
from the existing `media_api.php?action=product` / `action=defective`
read-only endpoints. It opens immediately with the cached thumbnail, loads only
one full-size original at a time, and supports next/previous buttons, an image
strip, keyboard arrows, Escape, and touch swipe. Unsaved product editors use
their currently selected media IDs without an extra database round-trip.
Single-media library and category images retain simple single-image view.
No change is made to stored invoice item snapshots, totals, or inventory math.

The library's folder selector is cached per page session and reads folder
counts through a single indexed aggregate query. Moving a media item updates
only its current card and the local folder counts. The whole grid is not
replaced by a reload, and the active folder remains selected. Navigating to a
different page or opening a new page may legitimately load its content via AJAX.

For ZIP recovery, after checking the destination file's byte count and
SHA-256 against the manifest, already-correct images are not decompressed from
the ZIP a second time. **Missing** images are always decompressed, staged and
SHA-256 verified before use. If the current SQL dump hash also exactly matches
the backup and no images are missing, the restore avoids destructive SQL replay.
A file inside a ZIP that is not used because an identical verified copy already
exists is not fully decompressed solely for archival checking; run an independent
backup archive-integrity check before moving archives to long-term storage.
First-time full restores remain necessarily limited by storage and SQL import
speed. Backup creation and the transaction/rollback handling for changed SQL
remain in place.

The settings endpoint for store logo, stamp and signature is now authenticated
and CSRF-protected. All **new** uploaded branding images, including the app
menu logo, undergo genuine MIME/dimension validation, a size cap and GD
decode/re-encoding into a randomly named local JPEG/PNG/WebP file; forged
Content-Type and extensions are not trusted. Old configured image files are
still respected for backwards compatibility. The UI restricts its file dialog
to the supported formats. The installer must keep `assets/uploads` in a
non-executable static-files web-server location.

### Release verification specifically requested

- A product with three images: open its thumbnail in product list, sales and
  purchase pickers, invoice lines and inventory reports; navigate all images,
  including arrow keys/swipe and image strip. Try an unsaved editor with a
  newly uploaded image and an existing image selected from the library.
- Create multiple logical folders; move a photo between folders while the
  manager is open, confirm no page reload, the filter remains selected and
  both folders work after a **manual** browser refresh. Check behavior with
  1000+ images/50+ folders as a performance comparison.
- Restore the same ZIP twice into a staging clone to validate the no-copy
  path; remove one media original, restore and ensure it is verified/recreated.
  Perform a full restore on a fresh staging clone to measure first-time cost
  independently of already-present files. Re-check prices, stock and totals.
- In store settings upload JPEG/PNG/WebP logo, stamp and signature, reload the
  page and generate PDF with all three. Test an invalid or disguised file and
  a missing CSRF token: both must be rejected. Re-check the separate app menu
  logo and confirm previously uploaded images still display.
- Confirm no Excel import/export or payment source files changed. Hold
  `main` until the staging results have been approved by the user.
