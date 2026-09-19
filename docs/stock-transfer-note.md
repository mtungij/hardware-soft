# Stock Transfer Note audit and implementation

Existing flow: create/edit store a draft header, notes and product lines in base-stock quantities. The index invokes InventoryService::completeStockTransfer(). Completion locks the transfer, locations and items, validates capabilities and AuthorizationScope access, checks available stock and duplicate posting, resolves source costs, writes one transfer_out and transfer_in per item with identical cost, then records completed status/user/time. Store → Dispensing is allowed by can_transfer_to_dispensing; location types do not select the document endpoints. Existing numbering is reused.

StockTransferItem persists quantity, product ID and notes, without transaction-unit or name snapshots. Locations, products, units and user names are current related records. This implementation preserves that backward-compatible fallback for all transfers; names, base-unit labels and company branding can change after completion. Quantities always come from stored transfer items, never current balances. No schema changes or historical backfills are performed. Missing related records display unavailable labels, and cross-company related records fail closed.

Documents reuse mPDF, Company branding fields, StockLocation::TYPES, NumberFormatter and existing date conventions. GET print/PDF routes share the stock transfer role middleware, warehouse flag, stock.view permission and AuthorizationScope branch/location checks with details. Both endpoints require completed status. Current location visibility rules also apply to historical documents (inactive locations may therefore be inaccessible). The company is loaded from the transfer, never Company::current().

The quantity-only note shares a standalone A4 template for browser printing and PDF, with repeatable table headings, per-base-unit totals and printable accountability fields. Only prepared-by is prefilled; completion does not establish who physically released or received goods. Rendering performs no inventory writes. InventoryService, costing, accounting and movements are unchanged.

## Files

- `app/Http/Controllers/StockTransferNoteController.php`: completed-only print and PDF responses, private/no-store headers and safe download filename.
- `app/Services/StockTransferNoteService.php`: shared visibility checks, transfer-owned data and branding, unit totals, mPDF rendering.
- `resources/views/documents/stock-transfer-note.blade.php`: standalone quantity-only A4 document.
- `routes/web.php`: named print/PDF routes within existing transfer middleware.
- `resources/views/livewire/stock-transfers/show.blade.php`: shared access checks and completed document actions.
- `resources/views/livewire/stock-transfers/index.blade.php`: dynamic internal-location description.
- `tests/Feature/StockTransferNoteTest.php`: document, tenancy, visibility and no-side-effect regression coverage.
- `tests/Feature/StockTransferPhaseTest.php`: explicit costed stock/transfer fixtures and English locale for English UI assertions (the default seeder no longer supplies demo transfers).
- `tests/Feature/StockTransferLocationOptionsTest.php`: source cost in the completion fixture, required by existing cost preservation.

No inventory service, accounting code, migrations, or historical records were modified.

## Verification

All requested suites passed (38 tests, 390 assertions):

- StockTransferNoteTest: 11 tests, 178 assertions.
- StockTransferCostPreservationTest: 8 tests, 55 assertions.
- StockLocationValuationTest: 9 tests, 101 assertions.
- StockTransferPhaseTest and StockTransferLocationOptionsTest: 10 tests, 56 assertions.

Commands used `PHP_INI_SCAN_DIR=` and `APP_DEBUG=false` for the local CLI/test environment. Changed PHP files pass syntax checks and targeted Pint; `git diff --check` passes. Full `vendor/bin/pint --test` reports 13 existing formatting failures outside the changed files. Print HTML and real mPDF binary generation are covered by HTTP tests; physical browser printing was not manually verified.
