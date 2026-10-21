# TOMAN/IRR currency work in progress — 2026-10-21

This is a **checkpoint commit**, deliberately isolated on a feature branch. It is NOT a finished migration and is NOT ready to deploy to a live accounting site.

## Completed in this checkpoint

- Exact decimal string-based currency conversion and validation for Iranian rial vs. toman.
- Persian and Arabic digits; thousand separators and negative values.
- PHP and JS tests (25 + 29 assertions) and additive SQL for the display preference.
- Draft protected settings endpoint, currently not referenced by the existing settings screen.

## Verification

Run from the repository root:

    php -l assets/php/currency.php
    php -l assets/php/currency_settings.php
    php assets/php/tests/currency_test.php
    node --check assets/js/currency.js
    node assets/js/currency.test.js

Unit tests were run independently with PHP 8.4 and Node 22. Integration tests against MySQL, mPDF, actual browser pages, invoices, and ZarinPal have NOT passed or been run in the current environment.

## Important: activation not yet implemented

The currency preference is NOT enabled in index.php or settings.php in this checkpoint. Merely checking out the branch will not provide a new currency selection screen or convert the existing pages, exports, PDFs and CRUD operations. Do not manually enable the preference or execute the SQL against production as a substitute for completing integration.

A separate, explicitly unfinished dry-run installer and audit report were provided as a conversation artifact and have NOT been applied to this branch. Future checkpoint(s) must integrate all financial surfaces, reconcile legacy ZarinPal IRR storage, run complete MySQL/PDF/browser tests, and obtain acceptance before merging to main.

No existing monetary database records are touched or migrated by this commit.
