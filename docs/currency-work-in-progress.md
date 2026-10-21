# Accounting Currency: TOMAN / IRR — Release Verification (2026-10-21)

## Boundaries (project owner requirement)
- Accounting product, invoice, inventory, return and report amounts remain **TOMAN in storage**.
- The accounting display/input preference is **TOMAN or IRR**. Exactly **10 IRR = 1 TOMAN**.
- Preference changes MUST NOT rewrite historical money, stock or quantities.
- **Payment forms, payment transactions, public pay page, stored IRR amounts and the existing banking integration are out of scope and MUST remain unchanged.** The owner verified Payment displays IRR while accounting displays TOMAN. No currency conversion from accounting settings may be applied to the payment domain.

## Owner-confirmed manual acceptance
The owner confirmed 10 scenarios passed:
1. Currency tab after Tax is visible.
2. IRR choice persists after page reload.
3. Product priced 10,000 TOMAN displays as 100,000 IRR.
4. Invoice PDF money and Persian amount-in-words agree with the selected unit.
5. Enter product at 100,000 IRR; switching back shows 10,000 TOMAN.
6. A purchase invoice entered in IRR retains value on switch to TOMAN.
7. Editing an old invoice without changing prices does not change saved money.
8. A sales return keeps correct money and inventory counts in both units.
9. Product/inventory Excel exports convert prices while stock counts remain unchanged.
10. Current-affairs money field and report totals round-trip between units.

Separately confirmed: the Payment section uses IRR when accounting uses TOMAN.

## Automated CI release gates
Runs on feature pushes and on main, with MySQL 8 isolated service:
- PHP syntax checks and JavaScript syntax checks.
- Pure PHP/JavaScript currency math: grouping, digit normalization, negatives, precision, overflow/rejection.
- Exact accounting preference DB read/write and monetary-row stability.
- PDF Persian money words: integers, fractional amounts and negative signs.
- Current affairs: full regression with IRR/TOMAN filters, aggregates and Excel export.
- Multi-unit inventory, sales returns, product Excel import, sales invoice CRUD/proforma, bundles/snapshots and defective returns.
- Auth/CSRF HTTP smoke tests for critical accounting writes and exports, including owner-only currency settings and exact IRR input converted to stored TOMAN.
- No Payment code is changed by this feature; this invariant should be re-checked against main before merging.

## Security hardening undertaken during final review
- Enforced login plus CSRF for product writes and bulk price mutations.
- Enforced login plus CSRF for tax and invoice settings writes.
- Enforced login on product listing and accounting Excel exports.
- Kept currency setting owner-only, validating unit allowlist and CSRF.

## Release operations
- Take and independently verify a recoverable database and code backup before production rollout.
- Ensure CI for the final merge commit and deploy is green, then smoke-test the 10 owner scenarios and the Payment-IRR boundary on the destination environment.
- This automated suite does not replace all browser, gateway-sandbox or mPDF visual checks. No live bank transaction test was made or needed for these accounting-only changes.
