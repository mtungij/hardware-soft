# Quotation templates — Phase 1

## Architecture audit (before implementation)

Quotation and QuotationItem use company scoping. B2bQuotationService creates quotations directly for customers or from purchase requests, calculates commercial totals, and persists product/name/SKU, transaction-unit, conversion, quantity and price snapshots. Additional charges (including transport) are separately snapshotted. The create page supports quotation and proforma; there is no quotation edit page/service. Details expose send, offline acceptance and sale conversion under existing status/permission rules.

B2bDocumentPdfService currently renders one English Blade quotation/proforma layout with mPDF. B2bDocumentController authorizes staff by company, quotations.view and report_scope/branch, or customer accounts by company/customer. Before Phase 1, downloads cached a pdf_path; sending also uses this path. Phase 1 quotation downloads now reuse an existing cache or generate the PDF file without updating the quotation row. The existing send operation still persists its attachment path. Proforma and invoice download behavior is unchanged. There is no standalone quotation print route. Invoices have a separate renderer. Company stores branding/contact/logo/currency/language; CompanyPaymentMethod supplies active document-specific instructions. Terms and notes are stored on the quotation. NumberFormatter formats existing monetary and transaction-quantity values. PDF labels are English even when the staff UI is Swahili; Phase 1 retains consistent English document labels.

## Design and persistence

The application-owned quotation catalog in config/document_templates.php contains Classic, Modern Blue, Corporate, Minimal, Bold Header, Elegant, Construction, Hardware Pro, Compact, Executive, Clean Border and Premium. QuotationTemplateRegistry validates keys, resolves only catalog views, and falls back to Classic for null/unknown historical saved keys. Request keys are strictly validated; no request value becomes a Blade path.

Additive columns: companies.quotation_template_key defaults to classic; quotations.quotation_template_key is nullable. No historical quotation rows are backfilled. Both quotation creation services resolve the company default at creation (or validate an explicit selection) and persist the effective key. Later default changes affect only future quotations. Existing saved documents have no new edit capability. Proforma creation/rendering stays outside this catalog.

QuotationDocumentService builds one normalized payload from stored snapshots and stored totals; it never recalculates commercial amounts. It supplies configured company branding, payment methods, terms and prepared-by information. Company/customer contact details and payment instructions retain their existing current-record behavior; template keys do not freeze those fields or future application template revisions. The old PDF entry point remains compatible. New quotation PDFs use the resolver; pre-existing cached historical PDFs remain available unchanged.

Settings → Document Templates uses existing company-settings.update middleware and repeated action authorization. The target company always comes from the authenticated staff account, never a browser-supplied ID. Sample previews use deterministic unsaved sample objects and never reserve document numbers or create transactions. Existing quotation preview uses the same authorization as PDF download. All document labels remain English, matching the existing quotation PDF convention.

## Extending later

Add document-type catalogs and dedicated payload builders for future phases. Do not route invoice, delivery note, receipt, purchase order, goods receipt or stock transfer rendering through this quotation catalog. Templates remain application-owned Blade/CSS; no template upload, code editor or arbitrary execution is supported.


## Registered keys and design differences

| Key | Display name | Composition |
| --- | --- | --- |
| classic | Classic | Traditional stacked identity/title, ruled customer brief, full item grid |
| modern_blue | Modern Blue | Split tinted masthead with reference, open rows, filled grand total |
| corporate | Corporate | Large identity followed by three columns: title, customer, metadata; boxed totals |
| minimal | Minimal | Quiet split heading, generous open rows, light rules and unboxed totals |
| bold_header | Bold Header | Oversized title above a reference band; company and dates below |
| elegant | Elegant | Centered serif letterhead and title, refined rules, centered prepared-by |
| construction | Construction | Project supply heading, shaded project brief, delivery/charge summary heading |
| hardware_pro | Hardware Pro | Material schedule, emphasized quantities, monospaced SKUs, spacious rows |
| compact | Compact | Condensed company/metadata header, smaller rows and spacing |
| executive | Executive | Formal top rule and split letterhead, strong totals rule, right-aligned signoff |
| clean_border | Clean Border | Boxed identity/customer/metadata panels and complete product grid |
| premium | Premium | Asymmetric serif masthead, vertical accent, shaded summary and right signoff |

All designs include the same monetary fields and transaction-unit snapshots through shared partials. Differences are structural and typographic, not just accent colors. Repeating page footers intentionally share the document reference, company name and page count.

## Resolver and request boundaries

`config/document_templates.php` owns the catalog. `app/Support/QuotationTemplateRegistry.php` exposes active definitions; `require()` rejects unknown keys, while `saved()` deliberately falls back to Classic for NULL or unknown legacy keys. `forNew()` validates an explicit key or resolves the authenticated company's default. Empty selection means “Use Company Default”; the literal `default` is invalid.

The additive migration is `database/migrations/2026_09_13_160000_add_quotation_template_keys.php`: a 40-character company key defaulting to `classic`, and a nullable 40-character quotation key. Viewing or downloading does not backfill NULL keys or mutate historical rows.

Both `B2bQuotationService::createDirect()` and `createFromRequest()` persist the effective key at save time. Corporate QT-A remains Corporate after the company changes to Modern Blue; the next default-selected QT-B stores Modern Blue. Explicit selections override only that quotation. Company B's default is independent of Company A's.

`B2bDocumentPdfService::quotation()` sends quotations through `QuotationDocumentService::buildDocumentData()`, the saved-key registry resolver, application-owned Blade templates and the existing mPDF engine. It writes the resulting file under the company quotation directory. Proformas retain their prior renderer. The payload reads stored financial values; only deterministic unsaved gallery samples construct demonstration totals. No business calculations run during rendering.

| Route name | Purpose | Authorization |
| --- | --- | --- |
| `settings.quotation-templates` | Gallery/default setting | Authenticated, verified staff; existing company-settings middleware and repeated action authorization |
| `settings.quotation-templates.preview` | Unsaved HTML sample; `download=1` returns PDF | Same middleware; registry validation before rendering |
| `quotations.preview` | Saved quotation HTML/print preview | Existing quotations.view and shared company/branch document authorization |
| `quotations.pdf` | Saved PDF download | Existing quotations.view and shared company/branch document authorization |

The existing customer PDF route also uses shared document authorization and checks company plus customer identity. No browser-supplied company ID selects the settings target. Traversal strings, arbitrary Blade names and unregistered keys cannot resolve through this catalog. Web validation uses the application's existing redirect/session-error convention. No production authorization or validation is weakened for tests.

Preview uses unsaved sample objects, reserves no document numbers and creates no quotation. Repeated saved preview/download requests also leave business tables unchanged. PDF files and mPDF temporary files are filesystem artifacts, not transactions. Existing cached historical PDFs are served unchanged; newly rendered NULL-template PDFs use Classic.

## PDF QA and reproduction

Generate samples with:

```sh
php scripts/render-quotation-template-samples.php
python3 scripts/verify-quotation-template-samples.py
```

The default output directory is `/tmp/hardex-quotation-samples`; both commands accept a directory argument. The PHP generator creates 36 PDFs (12 templates × 1/10/35 items), plus six representative no-payment/oversized-logo variants for Classic, Compact and Premium. It uses the exact long cement name ending in `50KG` and another long hardware description. The Python verifier requires Poppler (`pdfinfo`, `pdftotext`), checks A4 size, bounded page counts, item completeness, financial/section text, repeated headers, page numbers and text bounds. It complements actual rendered-page inspection; successful HTML/PDF compilation alone is insufficient.

The validation resume corrected the mPDF `@page size: A4` failure (mPDF already receives A4 in its constructor), explicitly bound the named page footer, replaced unsupported footer float alignment with a table, prevented split payment/signoff sections, kept Construction's summary heading with its totals, corrected Corporate metadata cell styling and tightened spacing that orphaned single-item signoffs. No PDF engine was replaced.

Gallery thumbnails under `public/document-templates/quotations/` are first-page rasterizations of the corresponding reviewed PDFs, not invented mockups. Regenerate those assets after intentional visual changes. The sample PDFs are review artifacts and are not real customer transactions.

See `docs/quotation-template-validation.md` for exact test results, page counts, changed-file inventory and limitations from the final validation run.

## Scope boundary

Invoice templates were NOT implemented by this phase. Delivery Note templates were NOT implemented. Stock Transfer Note templates were NOT implemented by this quotation task; separate pre-existing Stock Transfer Note workspace changes were left outside its scope.
