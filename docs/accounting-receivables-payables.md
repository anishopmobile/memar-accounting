# Accounts receivable and payable — branch feature/accounting-receivables-payables

## Scope and invariant

Invoices are sales/purchase documents; actual receipt/payment is an independent, immutable, dated accounting record. Invoices and payments are not interchangeable. An invoice may have many settlements, one receipt may be explicitly allocated to several invoices, and unallocated money is a party credit, not additional invoice value.

* The database stores financial decimal values in **TOMAN**. IRR is presentation/input conversion only. Online payment gateway transactions remain independent and untouched.
* Only `sales_invoice` and `purchase_invoice` affect actual debt. Proformas never increase debt; advances are unallocated account credit until explicitly assigned.
* `payment_status` is a **derived** invoice state: fully paid, partially paid, or unpaid. Manual editing is not a payment.
* Supplier balances: positive = payable, negative = advance/receivable. Customer balances: positive = receivable, negative = customer credit.
* Payment and credit allocation must never exceed the invoice's open amount. Unallocated payment is visible on the party statement.
* A verified financial record is not deleted: reversal creates an offsetting, opposite-direction entry, and allocated rows carry a reversal mark. Stock actions do not stand in for refunds.
* Return-credit allocation settles a customer invoice **without** creating fictitious cash; sales returns are always recorded separately.
* Invoices with allocations, allocated return credit or historical settlements are protected from deletion. Return documents with assigned credit are protected from edits/deletions.
* The customer/supplier debt reports, exports, and dashboard use real ledger-based settlement amounts. Unreconciled historical partials are marked rather than guessed.
* All changing endpoints require an authenticated session and CSRF. Financial changes validate server-side and use database transactions and row locks. Request tokens detect duplicate or conflicting retries.

## Data model

* `account_payments` — amount, direction, cash/POS/bank method and account, party, date, linked originating proforma (where applicable), idempotency hash, reversal link and operator ID.
* `account_payment_batches` — one atomic, idempotent posting for mixed cash/POS/transfer methods. Its child payments and allocations either all commit or all roll back.
* `account_payment_allocations` — amount linked to a final invoice, with auditable reversal flag. Allocation rows are append-only to preserve reversed history.
* `account_return_allocations` — explicit assignment of verified sales-return credit.
* `account_opening_balances` — one opening balance per party, with an explanation and author; positive debt / negative credit.
* `account_legacy_reconciliations` — one-time old partial invoice settlement correction, with reason, original status, author and timestamp.
* `accounting_cash_accounts` — named active cash register, POS, or bank account.
* `accounting_migrations` — idempotent migration registry. A MySQL advisory lock serializes schema upgrades; subsequent requests use a fast revision check.
* `invoices.legacy_paid_amount` — **historical non-cash snapshot**, initialized once.

## Legacy migration

Do a production backup and take a restore checkpoint **before** loading the feature. Existing paid final invoices initialize `legacy_paid_amount = payable_amount` to preserve their historical settled state **without inventing a cash transaction**. Existing unpaid final invoices start at zero historical settlement. Existing partial final invoices receive NULL — **unreconciled**; their true payment amount is unknown and must be entered by staff based on evidence. Proformas initialize zero.

Migration runs once and does not alter existing stock quantities, unit prices, invoice totals or the unrelated online payment module.

After deployment, locate every invoice flagged «تطبیق پرداخت تاریخی». Reconcile only based on documented received/sent amounts and a mandatory source/reason; the operator and date are audited. Do not enter an estimate merely to clear the warning. Opening balances represent external pre-system liabilities/credits, never duplicated debts already represented by migrated invoices.

## Operational workflow

1. Create a final invoice or proforma. After saving, the financial dialog opens. Enter **actual** paid amount (not full invoice value by default), date, method, and cash/POS/bank account. The financial dialog is a separate confirmation step, so do not assume an invoice save also saves a payment.
2. Financial date entry uses the existing `kamaDatepicker` Persian/Jalali calendar on all customer/supplier/invoice accounting dialogs; the display and statement/export dates are Jalali while a hidden ISO Gregorian field is sent to the unchanged accounting API and stored in MySQL. Invalid dates block posting. The existing invoice.js/jdf.php conversion algorithm is reused; no new calendar dependency is introduced.
3. The selected final invoice is automatically assigned up to its remaining value. Staff may add up to 12 cash/POS/bank rows to one **atomic** receipt/remittance and manually distribute the summed amount across earlier invoices. Excess money becomes visible account credit.
3. On later installments, open «دریافت/پرداخت و صورت‌حساب» from the invoice. New payments append history and update statuses atomically.
4. When an existing payment has unused credit, use «تخصیص اعتبار» to clear a later invoice. Customer return credits have their own explicitly separate allocation action.
5. From Customers/Suppliers, open «صورت حساب / دریافت و پرداخت» to view the chronological running balance and export it to currency-aware Excel. A documented opening balance may be entered **once**. A credit refund is limited to a verified credit amount.
6. To correct an erroneous entry, use «ثبت سند اصلاحی» with a reason. Original and reversing rows both remain visible.
7. Payments must never be recorded through the online gateway module as though they had been received by this ledger; the modules are intentionally separate.

## Manual acceptance scenarios

| Test | Expected outcome |
|---|---|
| Sales invoice 5m, receive 3m | Paid 3m, receivable 2m, status partial, customer debt report 2m |
| Later receive remaining 2m | Status paid, invoice remainder zero; both payment events remain |
| Purchase invoice 15m, pay 10m | Supplier debt increases 5m |
| Supplier opening debt 70m + new 15m purchase, pay 35m and allocate 15m to current invoice | New invoice paid, supplier total debt 50m; 20m unallocated payment available to allocate to older invoices |
| Pay 20m when total existing debt is only 15m | Current invoice settled; supplier advances show -5m (credit to shop) |
| Mixed payment 2m POS + 1m cash + 2m bank | Three independently recorded receipts with correct selected accounts; same invoice fully paid |
| Proforma advance 2m | No final debt and no stock change; unallocated credit recorded; credit can later settle converted final invoice |
| Sales return credited 1m | Reduces customer net debt by 1m without fake receipt; available to allocate or refund |
| Jalali receipt/payment date | Existing Persian calendar picks a day; hidden Gregorian ISO is persisted without date shift; invoices, receipt history, movement ledger and Excel statement show the same Jalali day. Month and year selects inside kamaDatepicker remain native and select the requested Jalali month/year, without HCG enhancement. |
| Change display currency TOMAN ↔ IRR | Every amount renders at correct scale while DB value and outstanding remain unchanged |
| Correct duplicate submission using same token | One payment only; different payload with same token rejected |
| Concurrent attempts to overpay the same invoice | Only allowed total may be allocated; no negative invoice remainder |
| Attempt delete paid invoice, edit settled invoice below amount paid, change party | Server blocks unsafe operation |
| Refund credit, then reverse mistaken refund | Refund has physical outflow and reversal restores credit; original records preserved |
| Old partial invoice with unknown historical amount | Marked "needs reconciliation"; cannot be assigned arbitrary new settlement until reconciled |
| PDF and Excel export | Correct payable, paid, remaining and party balance, as of document generation date; historical unknown clearly labelled |
| Security | Unauthenticated GET/POST blocked, POST without CSRF blocked, IDs/amounts/dates validated server-side |
| Existing stock/returns/gateway features | No stock, return or payment-gateway regression |

## Automated testing and review

Run GitHub Actions `Accounting Ledger CI` on the feature branch. It covers PHP and JavaScript syntax, isolated MySQL settlement/return/credit/refund/reversal/atomic mixed-payment tests, existing invoice and stock regressions, auth/CSRF smoke tests, desktop/mobile RTL browser regressions, and an actual Playwright customer/supplier settlement journey.

Do not merge to `main` before a copy of production-like data is backed up, historical partials are reconciled as needed, CI passes for the final SHA, and the shop owner completes manual acceptance. Production payment gateway files are not part of this feature.
