# Accounting ledger rollout (feature branch; NOT ready for merge)

## Scope and invariants
- The historical `invoices.payable_amount` is the authoritative nominal invoice total in TOMAN.
- Each accounting receipt/disbursement is immutable and independent of the existing IRR online-gateway `payment_transactions`.
- `ledger_payments` holds money movements; `ledger_allocations` maps them to final invoices.
- A payment can settle many invoices, and unapplied funds are party credit, not invoice revenue.
- Final invoice status is derived from remaining debt, not user-selected `payment_status`.
- Legacy paid invoices are recognized via `ledger_legacy_invoices`, NOT fabricated bank/cash transactions.
- Legacy partial invoices are marked `requires_reconciliation`; allocations to them are blocked until the actual historical amount is documented.
- Openings are signed: positive = party debt to business (customers) / store debt to supplier (suppliers), negative = credit of party/store respectively.
- Save payment + allocations in one DB transaction and lock party and invoices to avoid races. Request tokens prevent double submissions.
- Store monetary amounts in TOMAN DECIMAL(14,2), no floating-point math. Convert displayed IRR only at input/output boundaries.
- Do not change public payment forms, ZarinPal gateway, or existing payment transaction tables.

## Existing problem inventory
- `assets/js/invoice.js` stores only enum paid/unpaid/partial.
- `pages/report-customer-debt.php`, `pages/report-supplier-debt.php`, `pages/customers.php`, `pages/suppliers.php`, related XLS exports and dashboard mark 100% of a partial invoice outstanding.
- Sales return amounts do not reduce receivables.
- Invoice edits/deletes do not consider accounting ledger events.
- Data migration must not assume a partial invoice has 0 paid: require supervised reconciliation.

## Implementation status (feature branch only)
1. ✅ Database ledger schema and one-time legacy snapshots.
2. ✅ Server journal, multi-invoice payments, credit allocation, refundable cash and reconciliation.
3. ✅ CSRF/authenticated API, validated TOMAN/IRR monetary conversion, strict idempotency.
4. ✅ Atomic invoice/proforma initial payments, split across cash/POS/transfer.
5. ✅ Customer/supplier account statements, signed opening, old partial reconciliation.
6. ✅ Real refunds and audited non-destructive voids with conservative safeguards.
7. ✅ Unified accounts/reports/XLS/dashboard calculated from ledger and returns.
8. ✅ Proforma deposits and conversion, sales returns, PDF printed balances.
9. ✅ Ledger-posted and legacy invoices protected from destructive edits/deletes.
10. ✅ Isolated MySQL CI integration incl two concurrent independent payments and regression tests.
11. ⏳ Manual production-data-clone QA and explicit user approval before merging main.

## Manual acceptance checklist
1. Back up live database and media. Perform every test on a staging copy, not production.
2. Sale 5,000,000 / receipt 3,000,000 -> due 2,000,000; another receipt 2,000,000 -> paid in list/PDF/XLS.
3. Supplier opening debt 70,000,000 + purchase 15,000,000 - paid 35,000,000 = due 50,000,000.
4. Purchase 15,000,000 / paid 10,000,000 -> 5,000,000 due. Purchase 15,000,000 / paid 20,000,000 -> credit 5,000,000.
5. Reapply previous unapplied credit to another invoice without another bank movement.
6. Split single sale payment into cash/POS/bank and check dates, references and each entry.
7. Enter proforma deposit, convert to final and verify one bank entry; refund unused credit and prevent reused cash.
8. Partial paid sale with sales return, then refund credit: account total matches external record; inventory restores correctly.
9. Old paid/unpaid/partial documents: no invented cash, partial requires documented one-time reconciliation.
10. Switch TOMAN/IRR repeatedly, ensure monetary persistence and unit quantities unchanged.
11. Block cross-party allocations, overpay, double-submit, CSRF and deletion of settled invoices.
12. Mobile/desktop review of statement, reports and PDFs with accountant and original bank records.

## Known boundaries
- The detail account page currently loads latest 150 invoices/payments; account totals include all records.
- Opening and historic reconciliation records are one-time immutable entries; corrected historical partial amounts require source evidence.
- The feature is a financial subledger; full double-entry general ledger and bank imports are separate future modules.
- Do not deploy, reconcile or merge to main until manual acceptance is complete.
## Acceptance cases
- Sales 5m, receipt 3m -> 2m remains, partial; receipt 2m -> paid.
- Supplier opening debt 70m + new purchase 15m, payment 35m allocated 15m/20m -> 50m remains.
- Purchase 15m, paid 10m -> 5m owed.
- Purchase 15m, paid 20m -> 5m unapplied credit, not negative new invoice.
- 2+ receipts with different dates and methods, multiple invoice allocations, no duplicate request tokens.
- Multiple users attempt payments on same invoice concurrently; overpayment by allocation blocked.
- Editing/deleting paid invoices, issuing return to invoice, reversal/refund and conversion preserve immutable ledger.
- Legacy paid/unpaid/partial correctly migrated, no phantom cashflow, reconciliation logged.
- TOMAN/IRR switch round-trip without changing stored amounts or quantities.
- CSRF, authorization, validation, injection, bad allocation ownership, race and callback isolation.

**Do not deploy or merge before remaining checkboxes are completed and tests pass.**
