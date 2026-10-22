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

## Implementation status
1. ✅ Schema and core payment-allocation service added.
2. ✅ CSRF-protected server-side API added (not connected to the invoice UI).
3. ✅ Startup one-time legacy snapshot added.
4. ✅ PHP syntax-check workflow added. Actual status not yet verified.
5. ☐ Historical partial reconciliation endpoint and audit.
6. ☐ Receipt/payment entry UI in sales, purchase invoices; multiple payment methods and allocations.
7. ☐ Parties' statement view with opening balances, overdue invoices, balances and credits.
8. ☐ Dedicated payments, refunds, corrections and payment-to-invoice reallocation UI.
9. ☐ Update customers, suppliers, debt reports, XLS, dashboard to use ledger calculations, including historical uncertainty.
10. ☐ Handle proforma deposits and conversion, sales returns, PDF history and balance.
11. ☐ Protect invoice edits/deletes with ledger constraints and void/reversal audit.
12. ☐ Full integration tests, security tests, concurrent payments, migration on existing MySQL, dual-currency tests and manual QA.

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
