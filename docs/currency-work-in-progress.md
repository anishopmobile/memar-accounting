# Currency integration: TOMAN/IRR for accounting only (2026-10-21)

## Scope explicitly confirmed by project owner
The existing **Payment** module and its ZarinPal integration MUST remain entirely in **IRR / ریال** regardless of the accounting display currency chosen in Settings.

**Do not** convert payment-form input, payment-form display, public `pay.php`, transaction displays, payment reports, historical payment rows, SMS amounts or ZarinPal request/verification amounts according to the accounting TOMAN/IRR setting. Payment is out of scope for this feature. The previous attempt to make payment forms track the accounting currency was reverted in the subsequent checkpoint; no change to the payment domain should be implied by current currency settings.

## Accounting rules
- Accounting/inventory/invoice monetary records use TOMAN as their database base unit.
- Accounting display/form input may use either TOMAN or IRR (10 IRR = 1 TOMAN).
- Existing monetary rows must not be rewritten when the preference changes.
- Quantities, percentages, stock, unit-conversion factors and transaction identifiers are not money; do not multiply them.
- A separate payment module has its own legacy IRR storage. Do NOT apply accounting conversions to payment data.

## Manual checks reported successful by owner
1. New Currency settings tab visible after Tax.
2. IRR preference persists after reload.
3. A product of 10,000 TOMAN displays as 100,000 IRR.
4. An invoice PDF displays selected currency and correct amount in words.
5. Product entry of 100,000 IRR roundtrips as 10,000 TOMAN.

## Automated checks
The currency CI workflow syntax-checks project PHP and core JS, runs PHP/JS standalone currency tests, and tests currency preference and accounting monetary-row integrity against an isolated MySQL container. These are not substitutes for end-to-end accounting regression tests and mPDF rendering.

## Remaining work
- Verify untouched payment module still displays IRR in every mode and runs its own existing tests; do not change gateway contract.
- Test full accounting CRUD, bundle inventory, returns, decimal precision, Excel import/export, current-affairs custom money fields, and all rendered mPDF templates on test data.
- Audit settings permissions and the entire accounting monetary surface before merging to main.
- Do not merge/deploy this feature until regressions are resolved.
