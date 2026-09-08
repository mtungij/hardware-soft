# Dashboard stock-location valuation

The dashboard and Stock Valuation Report now share `FinancialReportService::stockValuation()`. It reads quantity through `InventoryService::getProductStocks()` and cost through `InventoryService::getAverageCost()`. These inventory methods and all posting code are unchanged. Values are current ledger balances, independent of the dashboard's sales date range.

## Why a “Main Store” card could show TZS 480,000 and its report show zero

Previously, the dashboard's `stockValueByLocationType('store')` summed stock in every authorized location of type `store`, not one Main Store record. It multiplied its own signed-movement totals by its own weighted incoming-cost calculation. The card was nevertheless labelled “Main Store Stock Value” and linked to `?search=Main%20Store`.

The report used InventoryService and then applied that text search against product, size, category and location names. For example, 120 units at TZS 4,000 held in “Zanzibar store” contributed TZS 480,000 to the mislabelled card, but no rows matched “Main Store”. A regression reproduces exactly this result. This is a code-level reproduction; no production database was queried to attribute the user's particular TZS 480,000 balance to specific transactions.

The old cost formula was a duplicate of the canonical method, rather than a separate accounting or product-price source. The structural error was type aggregation versus name filtering, compounded by independent calculation and branch-selection paths. The old report excluded shared locations when a branch was selected.

## Location and filter behavior

- Active, non-deleted StockLocation records are selected using the existing stock authorization helpers. No type, warehouse flag, or dispensing flag is required.
- Company isolation is explicit, including for system-owner accounts. Branch and assignment scopes are enforced on every request, including manually supplied filter IDs.
- Branch selection includes authorized branches even if their only stock is in a shared company location.
- Shared locations follow the existing branch-workflow authorization rules. A selected branch uses only that branch's ledger entries; company scope without a branch filter counts the shared location once.
- Only locations with positive current product stock appear in the dashboard valuation section, preserving the report's existing positive-stock convention. Stock with a zero canonical cost remains visible with zero value.
- Cards link with `stock_location_id` and the selected `branch_id`. Names are display-only. The report has separate branch, location and product/category search controls. Changing branch clears the location selection.
- Report exports use the same filters and valuation service. Total Stock Value is the sum of those location values.

## Receipts

Stock Received Today includes both legacy `purchase_in` and current `purchase_receipt` rows, across authorized locations and the selected branch. Transfers are excluded. Receiving capability flags are not used to erase historical receipts if a location's configuration has since changed. The drill-down and exports show receiving locations and apply the same receipt/date scope.

## Existing transfer-cost limitation

`InventoryService::completeStockTransfer()` currently posts `transfer_in` without `unit_cost`. The canonical average-cost method ignores incoming rows without cost. Consequently, a destination with no prior cost history has zero canonical average cost after receiving a transfer. The dashboard and report both show that existing result. No cost is inferred or silently substituted.

Tests verify unchanged total value when source and destination have the same established average cost, and separately capture the existing zero-cost result for a new destination. Quantities are not duplicated in either case. Guaranteeing unchanged total monetary value for transfers into a new destination requires a separately authorized change to transfer posting/costing, excluded from this task.

## Regression coverage

`tests/Feature/StockLocationValuationTest.php` covers dynamic location types and flags, newly created locations, actual dashboard/report rendering, ID links, the TZS 480,000 mismatch, branch/company/assignment scope, shared locations, receiving, POS selling, transfers, aggregate totals and filtered exports. All test stock transactions use the isolated test database.

## Final validation

All nine task-specific regressions passed across the final suite run and the corrected export rerun (101 assertions in total). The export regression verifies identical filter parameters in PDF/XLS links, browser printing of the filtered report, streamed XLS content, and successful PDF generation. The broader run also passed 25 existing dashboard, authorization, receiving, POS and transfer checks.

Four unrelated failures were reproduced against an isolated copy of unchanged HEAD: the POS receipt test expects English `From:` text with the default Kiswahili locale, and three legacy transfer tests require seeded transfer/stock fixtures that the current seeder does not provide. They remain separate test-suite issues; the broader suite is not fully green.

PHP syntax checks cover the changed services, tests, localization files and Blade files. Laravel Pint and `git diff --check` pass. No changes were made to InventoryService, Goods Receipt/POS posting, accounting, costing methodology, transfer posting or historical stock records.
