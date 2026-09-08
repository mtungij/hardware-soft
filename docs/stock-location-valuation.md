# Dashboard stock-location valuation

The dashboard and Stock Valuation Report now share `FinancialReportService::stockValuation()`. It reads quantity through `InventoryService::getProductStocks()` and cost through `InventoryService::getAverageCost()`. Values are current ledger balances, independent of the dashboard's sales date range.

The valuation task itself did not redesign Goods Receipt, POS, accounting, or the canonical costing method. A later stock-transfer cost-preservation follow-up now snapshots the source location's canonical average cost onto future transfer movements so stock moved into a new location does not lose its cost history.

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

## Stock transfer cost preservation follow-up

`InventoryService::completeStockTransfer()` now resolves the source location's canonical `getAverageCost()` before either transfer side is posted. That cost is snapshotted independently per product and written unchanged to both the matching `transfer_out` and `transfer_in` movements.

This fixes the important new-location case. For example, moving 20 units at a source average cost of TZS 4,000 into an empty “Zanzibar store” now creates an incoming cost snapshot of TZS 4,000, so the destination immediately carries TZS 80,000 of stock value instead of zero.

The completion path rejects the whole transfer if source stock exists but no resolvable cost history exists. It does not fall back to the product's current buying price. Explicit historical zero-cost stock remains zero-cost rather than being fabricated into a positive value. Existing completed transfers and their historical movements are not rewritten.

The follow-up does not create revenue, purchases, COGS, profit, inventory gains/losses, or other accounting records. It only preserves the inventory-cost snapshot on future internal transfer movements.

### Existing canonical averaging caveat

`InventoryService::getAverageCost()` still uses the project's existing historical incoming weighted-average method. It averages cost-bearing positive movements and does not reweight only the quantity currently remaining after prior issues/sales. Therefore, when a destination already has older incoming cost history plus substantial historical outgoing stock, its post-transfer reported valuation can still move in a way that is not strictly value-neutral at company level.

That behavior is pre-existing costing methodology and was intentionally not redesigned by the transfer-cost preservation follow-up. Fixing it would require a separate costing-methodology change, such as a perpetual moving-average/cost-layer approach, with its own migration, accounting, and historical-data review.

## Regression coverage

`tests/Feature/StockLocationValuationTest.php` covers dynamic location types and flags, newly created locations, actual dashboard/report rendering, ID links, the TZS 480,000 mismatch, branch/company/assignment scope, shared locations, receiving, POS selling, transfers, aggregate totals and filtered exports. All test stock transactions use the isolated test database.

`tests/Feature/StockTransferCostPreservationTest.php` covers transfer-out/in cost snapshots, a brand-new ordinary Store such as Zanzibar store, destination existing-cost averaging, multiple products, fractional/base-unit conversion, unresolved source cost rollback, explicit zero-cost history, historical transfer immutability, quantity neutrality, financial-table non-mutation, and the existing historical-incoming average-cost caveat.

## Validation notes

For the stock-location valuation task, all nine task-specific regressions passed across the final suite run and the corrected export rerun (101 assertions in total). The export regression verifies identical filter parameters in PDF/XLS links, browser printing of the filtered report, streamed XLS content, and successful PDF generation. The broader run also passed 25 existing dashboard, authorization, receiving, POS and transfer checks.

Four unrelated failures were reproduced against an isolated copy of unchanged HEAD during that valuation task: the POS receipt test expected English `From:` text with the default Kiswahili locale, and three legacy transfer tests required seeded transfer/stock fixtures that the current seeder did not provide. They remain separate test-suite issues; the broader suite was therefore not fully green at that point.

The repository now also contains the dedicated stock-transfer cost-preservation regression suite. GitHub does not currently show a CI workflow run for the latest pushed commit, so its runtime result should be confirmed in the normal local/container test environment before deployment.

No changes were made to Goods Receipt costing, purchase costs, POS sale costing, COGS recognition, unit conversion architecture, accounting behavior, or historical stock movements. The transfer-cost follow-up changes only future stock-transfer posting by preserving the source `unit_cost` on both transfer sides.