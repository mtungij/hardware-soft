# HARDEX Phase 1 quotation template validation

Validation date: 14 September 2026. This resume retained the existing registry, migration, routes, selector, gallery and twelve layouts. Changes were limited to rendering defects, regression coverage, missing preview assets, formatting and documentation.

## Architecture and persistence audit

- **Architecture:** company-scoped Quotation/QuotationItem models; B2bQuotationService is still the financial/snapshot source of truth. No calculation, numbering, stock, sales posting, accounting, COGS, payment or unit-conversion architecture changed.
- **Schema:** additive company `quotation_template_key` (40 characters, default `classic`) and nullable quotation `quotation_template_key`; existing migration retained, no historical backfill, no production migration executed during this resume.
- **Registry:** `app/Support/QuotationTemplateRegistry.php`, backed by `config/document_templates.php`; only active application-owned keys resolve.
- **Twelve designs:** all keys, display names and meaningful structural differences are documented in `quotation-templates.md`. Headers, customer/metadata arrangement, density, typography, borders, summary and signoff styling vary. Common page-reference footers and financial partials deliberately remain shared.
- **Company defaults:** stored on each company; settings resolve the authenticated company and authorize the action. Company A Corporate and Company B Modern Blue remain independent.
- **Effective persistence:** direct and purchase-request quotation creation save the resolved actual key. Empty selector uses the company default at save time; explicit selection is validated. Literal `default` is rejected.
- **History:** QT-A Corporate remains Corporate after its company changes to Modern Blue; new QT-B stores Modern Blue. NULL/unknown historical keys fall back to Classic at resolution without changing the row. Existing cached PDFs are reused unchanged.
- **Creation and purchase requests:** both selectors use the registry, offer Use Company Default plus all twelve names and pass selection to the existing creation services. Request workflow totals/snapshots remain unchanged.
- **Gallery and preview:** company-authorized gallery with twelve actual PDF thumbnails; unsaved HTML/PDF samples reserve no numbers or transactions. Saved HTML preview and PDF downloads share existing document authorization.
- **PDF architecture:** normalized stored-value payload → registry-owned Blade view → existing mPDF A4 renderer. Quotation downloads no longer persist `pdf_path`; sending retains its existing attachment persistence. Filesystem PDF/temp files are expected rendering artifacts.
- **Security:** arbitrary Blade names and traversal keys cannot resolve via requests or the rendering boundary. Invalid web input follows existing redirect/session-error handling; deliberate historical fallback is separate from request validation.
- **Authorization:** authenticated/verified staff route group; existing company-settings authorization; quotations.view and shared branch/company restrictions; customer downloads check company/customer identity. No parallel document policy was introduced.

## Visual review

All 36 PDFs were rasterized with Poppler and inspected as rendered pages, including first pages and every continuation page. Six additional representative PDFs cover payment absence and 2400×600-pixel logo handling (Classic, Compact, Premium). Grayscale first pages for all twelve were inspected. This is digital A4 print QA; no physical printer was used.

| Template | 1 item pages | 10 item pages | 35 item pages | Review |
| --- | ---: | ---: | ---: | --- |
| classic | 1 | 2 | 4 | Pass |
| modern_blue | 1 | 2 | 4 | Pass |
| corporate | 1 | 2 | 4 | Pass |
| minimal | 1 | 2 | 4 | Pass |
| bold_header | 1 | 2 | 4 | Pass |
| elegant | 1 | 2 | 4 | Pass |
| construction | 1 | 2 | 4 | Pass |
| hardware_pro | 1 | 2 | 4 | Pass |
| compact | 1 | 2 | 3 | Pass |
| executive | 1 | 2 | 4 | Pass |
| clean_border | 1 | 2 | 4 | Pass |
| premium | 1 | 2 | 4 | Pass |

All long descriptions wrap without overlapping the unit/quantity/price columns. The exact cement name ending in 50KG and a long reinforcement-bar description are included. Stored values use the same number formatter and normalized fields in every design. Subtotal, discount, tax, transport and grand total appear, along with dates, validity, customer, terms, payment reference, preparer and page numbers. All item rows are present, headers repeat on pages containing items, and text stays inside A4 bounds. No clipped rows or footer collisions were observed. Logos remain bounded; missing logos and absent payments collapse cleanly. Grayscale retains readable hierarchy.

Totals are kept together. Depending on row density, the entire summary can start on a continuation page; it is not split or squeezed to an unreadable size. Closing payment/signoff sections remain together. This leaves some whitespace on the last page of long samples, an accepted pagination tradeoff. The 36-PDF matrix totals **83 pages**; the six supplemental samples total six pages.

### Defects corrected during the resume

1. mPDF mishandled CSS `@page size: A4`, creating excessive margins, blank pages and hundreds of broken pages; Construction raised an internal error. A4 stays configured in the existing mPDF constructor and CSS supplies explicit margins.
2. Named HTML footer bound explicitly to page CSS; two-cell footer replaces unsupported float alignment, restoring reference/company and page count.
3. Corporate nested metadata cells no longer inherit outer three-column padding/borders/widths.
4. Single-item signoff overflow corrected with limited spacing adjustments, including Bold Header and Elegant.
5. Construction's summary heading moved into the unbreakable totals table; payment/signoff kept together across layouts.
6. First quotation download no longer updates the quotation row, satisfying NULL-history and no-render-side-effects requirements.
7. Missing gallery PNGs generated from the reviewed PDFs.
8. Historical regression compares persisted row attributes, not differently loaded relation graphs; invalid-key tests follow the existing web validation response contract.

## Exact validation commands and results

Final feature suite command:

```sh
php -d memory_limit=512M vendor/bin/pest tests/Feature/QuotationTemplateTest.php tests/Feature/B2bOrderingWorkflowTest.php tests/Feature/CustomerPhoneAuthenticationTest.php
```

**64 passed tests, 898 assertions, 0 failures/errors** (349.446 seconds). Including the separately repeated render check below, the final verification executions total **65 passed test executions and 1,233 assertions**; there are 64 distinct tests. No unrelated pre-existing failures occurred in the selected suites.

After the last pagination edits, the all-layout rendering test was repeated against the final templates:

```sh
php -d memory_limit=512M vendor/bin/pest tests/Feature/QuotationTemplateTest.php --filter='all designs render'
```

Result: **1 passed test, 335 assertions** (22.167 seconds). This repeats a test in the full suite and is reported separately, not added to its unique test count.

The expanded quotation tests cover registry/view resolution, malicious/invalid keys, defaults, company separation, unauthorized settings, explicit/default creation, effective persistence, historical fallback, all-template data/PDF output, bounded multipage output, branding/payment presence/absence, branch/company access, authentication and repeated render invariance. The repeated-request regression compares **every application database table** except session/cache tables, covering quotations/items, stock, sales/items, payments, accounting-related data, sequences and audit records. The B2B suite separately covers ordering, request quotations, monetary/snapshot preservation, acceptance, conversion, stock and idempotence.

Sample commands:

```sh
php scripts/render-quotation-template-samples.php
python3 scripts/verify-quotation-template-samples.py
```

Result: 42 PDF samples generated; all 36 matrix PDFs pass the Poppler checks for A4 dimensions, expected pagination, complete item rows, required text, identical demo financial values, repeated table headers, page counts and text bounds. Visual review supplements these checks.

Static validation:

- `php -l` on all 41 quotation-task PHP/Blade files: **pass** (14 ordinary PHP files, 27 Blade files).
- `vendor/bin/pint --test` on the 14 changed ordinary PHP files listed below: **pass** after changed-file formatting. Blade files are syntax/render-checked separately.
- `git diff --check`: **pass**.
- Repository-wide Pint and the unrelated full application suite were not run; no repository-wide green claim is made.

Earlier diagnostic runs (not added to the final passed-test/assertion totals):

- `php artisan test tests/Feature/QuotationTemplateTest.php`: found the original rendering error, relation-comparison fixture failure and incorrect JSON-response expectation.
- `php artisan test tests/Feature/QuotationTemplateTest.php --filter='company defaults resolve'`: proved the `pdf_path` row mutation before its fix.
- `php artisan test tests/Feature/QuotationTemplateTest.php tests/Feature/B2bOrderingWorkflowTest.php tests/Feature/CustomerPhoneAuthenticationTest.php`: intermediate run overlapped rendering edits and reported stale renderer/footer errors; superseded by the final run.
- `php artisan test tests/Feature/QuotationTemplateTest.php`: the expanded run exceeded the CLI's default 128 MB during oversized-logo MIME handling; the final test process uses 512 MB. No production memory setting was changed. Standalone sample generation succeeds at the default limit.

## Exact Phase 1 quotation file inventory

This is the complete quotation-task diff inventory relative to repository HEAD, including implementation already present when the resume began. It excludes the separate Stock Transfer Note task. Files untouched by this resume remain listed to make the complete Phase 1 change reviewable.

- `app/Http/Controllers/B2bDocumentController.php`
- `app/Http/Controllers/QuotationTemplatePreviewController.php`
- `app/Models/Company.php`
- `app/Models/Quotation.php`
- `app/Services/B2bDocumentPdfService.php`
- `app/Services/B2bQuotationService.php`
- `app/Services/QuotationDocumentService.php`
- `app/Support/QuotationTemplateRegistry.php`
- `config/document_templates.php`
- `database/migrations/2026_09_13_160000_add_quotation_template_keys.php`
- `docs/quotation-template-validation.md`
- `docs/quotation-templates.md`
- `public/document-templates/quotations/bold_header.png`
- `public/document-templates/quotations/classic.png`
- `public/document-templates/quotations/clean_border.png`
- `public/document-templates/quotations/compact.png`
- `public/document-templates/quotations/construction.png`
- `public/document-templates/quotations/corporate.png`
- `public/document-templates/quotations/elegant.png`
- `public/document-templates/quotations/executive.png`
- `public/document-templates/quotations/hardware_pro.png`
- `public/document-templates/quotations/minimal.png`
- `public/document-templates/quotations/modern_blue.png`
- `public/document-templates/quotations/premium.png`
- `resources/views/documents/quotations/layout.blade.php`
- `resources/views/documents/quotations/partials/company.blade.php`
- `resources/views/documents/quotations/partials/customer.blade.php`
- `resources/views/documents/quotations/partials/items.blade.php`
- `resources/views/documents/quotations/partials/metadata.blade.php`
- `resources/views/documents/quotations/partials/payments.blade.php`
- `resources/views/documents/quotations/partials/prepared.blade.php`
- `resources/views/documents/quotations/partials/selector.blade.php`
- `resources/views/documents/quotations/partials/terms.blade.php`
- `resources/views/documents/quotations/partials/totals.blade.php`
- `resources/views/documents/quotations/templates/bold_header.blade.php`
- `resources/views/documents/quotations/templates/classic.blade.php`
- `resources/views/documents/quotations/templates/clean_border.blade.php`
- `resources/views/documents/quotations/templates/compact.blade.php`
- `resources/views/documents/quotations/templates/construction.blade.php`
- `resources/views/documents/quotations/templates/corporate.blade.php`
- `resources/views/documents/quotations/templates/elegant.blade.php`
- `resources/views/documents/quotations/templates/executive.blade.php`
- `resources/views/documents/quotations/templates/hardware_pro.blade.php`
- `resources/views/documents/quotations/templates/minimal.blade.php`
- `resources/views/documents/quotations/templates/modern_blue.blade.php`
- `resources/views/documents/quotations/templates/premium.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/livewire/customer-requests/show.blade.php`
- `resources/views/livewire/quotations/create.blade.php`
- `resources/views/livewire/quotations/show.blade.php`
- `resources/views/livewire/settings/quotation-templates.blade.php`
- `routes/web.php`
- `scripts/render-quotation-template-samples.php`
- `scripts/verify-quotation-template-samples.py`
- `tests/Feature/B2bOrderingWorkflowTest.php`
- `tests/Feature/QuotationTemplateTest.php`

## Unrelated work and limitations

Separate Stock Transfer Note files/routes/views/tests were already present in the workspace. They were not implemented or validated as part of this quotation task. No unrelated production logic or test fixtures were modified to obtain a passing quotation run. Shared `routes/web.php` retains those existing separate changes.

No invoice template system, Delivery Note template system or Stock Transfer Note template system was implemented by this Phase 1 quotation work. This does not imply unrelated existing renderers or separate workspace changes are absent.

## Required confirmations

- All twelve quotation templates render and have meaningfully different designs.
- All use the exact same stored quotation financial data; quotation calculations were not changed.
- Stock posting, accounting and COGS were not changed.
- Each company has its own default; saved quotations preserve the effective key after later default changes.
- Historical NULL-template quotations resolve Classic without destructive migration or row updates during viewing/downloading.
- Arbitrary Blade/template execution through template input is impossible within the registry boundary.
- Preview/PDF rendering creates no stock or accounting side effects and leaves quotation monetary values unchanged.
- Invoice templates were NOT implemented by this task.
- Delivery Note templates were NOT implemented by this task.
- Stock Transfer Note templates were NOT implemented by this task.
