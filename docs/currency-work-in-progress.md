# TOMAN/IRR project currency integration — 2026-10-21

## Acceptance evidence
The project owner manually confirmed on an isolated test installation:
1. Currency tab appears after Tax settings.
2. Changing to IRR persists after reload.
3. 10,000 TOMAN is displayed as 100,000 IRR in product listing.
4. An existing invoice PDF amounts and number-in-words match the selected currency.
5. Entering a new product at 100,000 IRR stores/display-roundtrips as 10,000 TOMAN.

These are **manual reports**, not a comprehensive regression suite.

## Architecture / no destructive data migrations
- Accounting/invoices/products are stored in TOMAN; change currency is at UI/input boundary.
- Historical payment forms/transactions are **NOT** stored in TOMAN: the payment subsystem historically stores integer IRR.
- `payment_currency.php` isolates display and input conversion for payment forms, admin transaction views and public payment form. Existing payment values and payment/gateway request semantics remain IRR.
- A public payment form includes a currency-code snapshot; if an admin changes the preference before the user submits, the stale form is rejected rather than charging 10x or 0.1x.
- ZarinPal verification/request handling and its existing IRR -> gateway Toman conversion are UNCHANGED. Live payment should not be tested before sandbox checks.
- No historical financial database row migrations or rewrites are performed.

## Automated checks
The GitHub Actions `Currency WIP checks` workflow syntax-checks PHP/JS and runs standalone currency conversion assertions and the payment IRR boundary assertions. Confirm the latest commit's workflow result separately.

## Known remaining work before merging to main
- Sandbox end-to-end checks for payment form fixed/custom/selectable, gateways and webhook callbacks, and public receipt.
- Complete MySQL CRUD roundtrip and precision/rounding verification, including stock and returns.
- Review remaining monetary surfaces, current affairs, product Excel import & exports, CSV and other outputs.
- Run integration regression tests using an isolated DB clone and actual PDF rendering.
- Review role-based permission policy for currency settings: current implementation uses the earliest registered active user as owner because the existing users schema lacks explicit roles.
- Full money convention/data audit is required before any main-branch deployment; do not conflate payment IRR storage with accounting TOMAN.

Do **NOT** merge to main or deploy to production merely because PHP syntax checks pass. Use a backup and an isolated sandbox.
