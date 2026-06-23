# Hardex Hardware ERP Multi-Application Architecture

This document defines the production architecture for Hardex Hardware ERP as two independent Laravel applications connected only through secure REST APIs.

## 1. System Overview

Hardex has two deployable applications:

| Application | Host | Runtime Role | Database Access |
| --- | --- | --- | --- |
| Hardex ERP Staff App | `https://staff.buildcore.site` | Master ERP, staff workflows, source of truth, API provider | Direct MySQL access |
| Hardex Customer Portal | `https://customer.buildcore.site` | Customer self-service portal, PWA, API client | No ERP database access |

The ERP owns all business records: products, branches, stock, sales, customer debts, deposits, receipts, notifications, accounting, and reporting. The customer portal stores only local application state such as sessions, cached API responses if needed, logs, and PWA/UI preferences.

All customer-facing business data must be requested from:

```http
https://staff.buildcore.site/api/customer/*
```

The customer portal must never connect to the ERP database, use ERP database credentials, or replicate source-of-truth financial tables.

## 2. Technology Stack

### ERP Staff Application

- Laravel 12 or 13, depending on deployment standard. This repository currently targets Laravel `^13.8`.
- Livewire 3
- Volt
- Tailwind CSS
- Alpine.js
- MySQL 8
- Laravel Sanctum
- Spatie Laravel Permission
- Queue workers for notifications, PDF/email delivery, and slow reports
- Redis or database cache/queue in production

### Customer Portal

- Laravel 13
- Livewire 3
- Volt
- Tailwind CSS
- Alpine.js
- Sanctum bearer-token API client
- PWA manifest and service worker
- Optional local MySQL database for sessions, logs, and non-authoritative cache only

## 3. Repository And Folder Structure

Recommended production structure:

```text
/var/www/hardex/
├── hardex-erp/
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── Api/
│   │   │   │   │   ├── CustomerAuthController.php
│   │   │   │   │   └── CustomerPortalApiController.php
│   │   │   │   ├── CustomerPortal/
│   │   │   │   └── Reports/
│   │   │   ├── Middleware/
│   │   │   └── Requests/
│   │   ├── Models/
│   │   ├── Notifications/
│   │   ├── Policies/
│   │   ├── Services/
│   │   └── Support/
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/
│   ├── resources/views/livewire/
│   │   ├── admin/
│   │   ├── products/
│   │   ├── purchases/
│   │   ├── pos/
│   │   ├── reports/
│   │   └── settings/
│   ├── routes/
│   │   ├── web.php
│   │   └── api.php
│   └── storage/app/private/
│       ├── customer-receipts/
│       └── customer-deposits/
│
└── hardex-customer-portal/
    ├── app/
    │   ├── Clients/
    │   │   └── HardexErpClient.php
    │   ├── Http/
    │   │   ├── Controllers/
    │   │   └── Middleware/
    │   ├── Livewire/
    │   └── Support/
    ├── config/
    │   ├── hardex.php
    │   └── pwa.php
    ├── database/
    │   └── migrations/
    ├── public/
    │   ├── manifest.json
    │   ├── sw.js
    │   └── icons/
    ├── resources/views/livewire/customer/
    │   ├── auth/
    │   ├── dashboard.blade.php
    │   ├── debts/
    │   ├── receipts/
    │   ├── deposits/
    │   ├── statements/
    │   ├── notifications/
    │   └── profile.blade.php
    └── routes/
        └── web.php
```

## 4. Application Responsibilities

### ERP Staff App

The ERP staff app provides:

- Dashboard
- Products
- Categories
- Units
- Suppliers
- Customers
- Purchases
- Stock receiving
- Main store stock
- Dispensing stock
- Stock transfers
- POS sales
- Credit sales
- Expenses
- Accounting
- Reports
- Users
- Roles
- Branches
- Settings
- Customer accounts
- Customer portal users
- Pending receipts
- Pending deposits
- Customer statements
- Customer notifications
- Customer REST API

### Customer Portal

The customer portal provides:

- Dashboard
- My Debts
- My Credit Purchases
- Upload Payment Receipt
- My Deposits
- Deposit Balance
- Statements
- Profile
- Notifications
- Support
- PWA install prompt: `Install Hardex Customer App`

## 5. Authentication Architecture

### ERP Staff Authentication

- Laravel session authentication for staff.
- Email and password login.
- Email verification where required.
- Spatie roles and permissions.
- Staff roles:
  - Super Admin
  - Admin
  - Manager
  - Cashier
  - Store Keeper
  - Accountant

Recommended role matrix:

| Module | Super Admin | Admin | Manager | Cashier | Store Keeper | Accountant |
| --- | --- | --- | --- | --- | --- | --- |
| Dashboard | Yes | Yes | Yes | Yes | Yes | Yes |
| Products/Categories/Units | Yes | Yes | Yes | Read | Read | Read |
| Suppliers/Purchases | Yes | Yes | Yes | No | Yes | Yes |
| Stock Receiving | Yes | Yes | Yes | No | Yes | View |
| Main/Dispensing Stock | Yes | Yes | Yes | Yes | Yes | View |
| Stock Transfers | Yes | Yes | Yes | No | Yes | View |
| POS Sales | Yes | Yes | Yes | Yes | No | View |
| Credit Sales | Yes | Yes | Yes | Yes | No | Yes |
| Expenses | Yes | Yes | Yes | No | No | Yes |
| Accounting/Reports | Yes | Yes | Yes | View | View | Yes |
| Users/Roles/Settings | Yes | Yes | No | No | No | No |
| Customer Approvals | Yes | Yes | Yes | No | No | Yes |

### Customer Portal Authentication

Customer portal authentication is against the ERP API:

- Email login through `login`.
- Phone login through `login`.
- Password authentication now.
- OTP-ready fields and routes reserved for later.
- Google-login-ready identity mapping reserved for later.

The customer portal should store the Sanctum bearer token in the server-side session for Livewire-rendered pages. If a future SPA mode is introduced, use secure, short-lived browser storage with strict CSP, CSRF protections for portal-side forms, and token rotation.

## 6. Sanctum API Security

The ERP API uses Laravel Sanctum personal access tokens:

```http
Authorization: Bearer <token>
Accept: application/json
```

Recommended token abilities:

| Ability | Purpose |
| --- | --- |
| `customer:read` | Dashboard, debts, deposits, statements, profile, notifications |
| `customer:write` | Upload receipts, upload deposits, update profile |
| `customer:logout` | Revoke current token |

Security controls:

- API route group: `prefix('customer')->middleware('throttle:customer-api')`.
- Protected group: `auth:sanctum`.
- Active account middleware: `customer.api.active`.
- Customer ownership checks on debt, receipt, deposit, statement, and notification resources.
- Request validation on every input endpoint.
- File uploads restricted to JPG, PNG, and PDF.
- File uploads limited to 5 MB.
- Private storage for uploads.
- Signed or controller-mediated downloads for staff review.
- HTTPS only.
- HSTS on both domains.
- CORS restricted to `https://customer.buildcore.site`.
- Rate limits:
  - Login/register: 5 attempts per minute per IP/login.
  - Authenticated customer API: 90 requests per minute per account.
  - Upload endpoints: 10 requests per minute per account.

## 7. ERP API Contract

Base URL:

```http
https://staff.buildcore.site/api
```

### Authentication

#### `POST /api/customer/login`

Request:

```json
{
  "login": "customer@example.com",
  "password": "password",
  "device_name": "Hardex Customer Portal"
}
```

`login` accepts email or phone.

Response:

```json
{
  "token": "1|plain-text-token",
  "token_type": "Bearer",
  "account": {
    "id": 1,
    "customer_id": 10,
    "name": "Asha Hardware",
    "phone": "+255700000000",
    "email": "customer@example.com",
    "status": "active",
    "otp_ready": true,
    "google_login_ready": true
  }
}
```

#### `POST /api/customer/register`

Creates a pending customer portal account. Staff must approve the account before protected customer data is available.

Request:

```json
{
  "name": "Asha Hardware",
  "phone": "+255700000000",
  "business_name": "Asha Hardware Ltd",
  "email": "customer@example.com",
  "password": "password",
  "password_confirmation": "password",
  "branch_name": "Dar es Salaam",
  "device_name": "Hardex Customer Portal"
}
```

#### `POST /api/customer/logout`

Revokes the current access token.

### Dashboard

#### `GET /api/customer/dashboard`

Response:

```json
{
  "total_outstanding_debt": 1200000,
  "available_deposit_balance": 300000,
  "credit_limit": 5000000,
  "available_credit": 3800000,
  "last_payment": {
    "id": 44,
    "amount": 250000,
    "payment_method": "bank",
    "reference_number": "NBC-1002",
    "payment_date": "2026-06-22"
  },
  "last_purchase": {
    "id": 501,
    "invoice_number": "INV-000501",
    "date": "2026-06-22",
    "total_amount": 750000,
    "paid_amount": 250000,
    "outstanding_balance": 500000,
    "status": "partial"
  },
  "pending_receipts": 1,
  "pending_deposits": 2
}
```

### Debts

#### `GET /api/customer/debts`

Paginated customer invoices:

```json
{
  "data": [
    {
      "id": 501,
      "invoice_number": "INV-000501",
      "date": "2026-06-22",
      "products": [
        {
          "name": "Cement",
          "sku": "CEM-001",
          "quantity": 100,
          "unit_price": 15000,
          "line_total": 1500000
        }
      ],
      "total_amount": 1500000,
      "paid_amount": 300000,
      "outstanding_balance": 1200000,
      "status": "partial"
    }
  ],
  "links": {},
  "meta": {}
}
```

#### `GET /api/customer/debts/{id}`

Returns one invoice with products and payment history. The API must return `403` if the invoice does not belong to the authenticated customer.

### Receipts

#### `GET /api/customer/receipts`

Returns uploaded debt payment receipts with statuses:

- `pending`
- `approved`
- `rejected`

#### `POST /api/customer/receipts`

Multipart request:

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `invoice_id` | integer | No | Must belong to customer |
| `amount` | decimal | Yes | Must be greater than zero |
| `payment_method` | string | Yes | `mobile_money`, `bank`, `cash_deposit` |
| `reference_number` | string | No | Unique per customer |
| `receipt` | file | Yes | JPG, PNG, PDF, max 5 MB |
| `notes` | string | No | Max 1000 chars |

### Deposits

#### `GET /api/customer/deposits`

Returns:

- Approved deposits
- Pending deposits
- Used deposits
- Remaining deposit balance

#### `POST /api/customer/deposits`

Multipart request:

| Field | Type | Required |
| --- | --- | --- |
| `amount` | decimal | Yes |
| `payment_method` | string | Yes |
| `reference_number` | string | No |
| `receipt` | file | Yes |
| `notes` | string | No |

### Statements

#### `GET /api/customer/statements`

Returns JSON statement data:

- Customer profile
- Outstanding balance
- Transaction history
- Debt history
- Payment history
- Deposit history

#### `GET /api/customer/statements?format=pdf`

Returns a generated PDF statement.

### Profile

#### `GET /api/customer/profile`

Returns account and linked customer profile.

#### `PUT /api/customer/profile`

Updates:

- Name
- Phone
- Email

### Notifications

#### `GET /api/customer/notifications`

Returns paginated notifications:

- Receipt approved
- Receipt rejected
- Deposit approved
- Deposit rejected
- New debt added
- New invoice generated

## 8. ERP Database Design

The ERP MySQL database is the source of truth.

### Core Tables

| Table | Purpose |
| --- | --- |
| `users` | Staff users |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions` | Spatie permission data |
| `branches` | Branches/locations |
| `settings` | ERP configuration |
| `categories` | Product categories |
| `units` | Measurement units |
| `products` | Product catalog |
| `suppliers` | Suppliers |
| `customers` | ERP customer master records |
| `stock_locations` | Main store/dispensing stock locations |
| `purchases`, `purchase_items` | Supplier purchases |
| `goods_receiving_notes`, `goods_receiving_note_items` | Stock receiving |
| `stock_movements` | Inventory ledger |
| `stock_adjustments` | Manual stock corrections |
| `stock_transfers`, `stock_transfer_items` | Movement between stock locations |
| `sales`, `sale_items`, `sale_payments` | POS and credit sales |
| `expenses`, `expense_categories` | Expenses |
| `customer_payments` | Staff-entered customer payments |
| `supplier_payments` | Supplier settlement |
| `cashbook_sessions` | Daily cash accounting |

### Customer Portal Tables In ERP

| Table | Purpose | Important Fields |
| --- | --- | --- |
| `customer_accounts` | Customer login accounts linked to ERP customers | `customer_id`, `name`, `phone`, `email`, `password`, `status`, `approved_at`, `approved_by`, `last_login_at` |
| `customer_receipts` | Debt payment receipt uploads | `customer_account_id`, `customer_id`, `sale_id`, `amount`, `payment_method`, `reference_number`, `receipt_file`, `status`, approval/rejection fields |
| `customer_deposits` | Customer deposit uploads and balances | `customer_account_id`, `customer_id`, `amount`, `used_amount`, `balance_amount`, `status`, approval/rejection fields |
| `customer_deposit_usages` | Deposit consumption ledger | `customer_deposit_id`, `customer_id`, `sale_id`, `amount`, `used_by`, `used_at` |
| `customer_notifications` | Portal notifications | `customer_account_id`, `customer_id`, `type`, `title`, `message`, `notifiable`, `read_at` |
| `personal_access_tokens` | Sanctum API tokens | Token metadata and hashed tokens |

### Recommended Indexes

- `customers`: `branch_id`, `phone`, `email`, `status`
- `sales`: `customer_id`, `branch_id`, `sale_date`, `payment_status`, `status`
- `sale_payments`: `sale_id`, `customer_id`, `payment_date`
- `customer_accounts`: unique `email`, index `customer_id,status`
- `customer_receipts`: index `customer_id,status`, unique `customer_id,reference_number`
- `customer_deposits`: index `customer_id,status`, unique `customer_id,reference_number`
- `customer_notifications`: index `customer_id,read_at`
- `stock_movements`: `product_id`, `branch_id`, `stock_location_id`, `movement_date`

## 9. API Implementation Pattern

ERP API controllers should stay thin and delegate business rules to services:

```text
app/
├── Http/
│   ├── Controllers/Api/
│   │   ├── CustomerAuthController.php
│   │   └── CustomerPortalApiController.php
│   ├── Requests/Api/Customer/
│   │   ├── LoginRequest.php
│   │   ├── RegisterRequest.php
│   │   ├── StoreReceiptRequest.php
│   │   ├── StoreDepositRequest.php
│   │   └── UpdateProfileRequest.php
│   └── Resources/Customer/
│       ├── AccountResource.php
│       ├── DashboardResource.php
│       ├── DebtResource.php
│       ├── ReceiptResource.php
│       ├── DepositResource.php
│       ├── StatementResource.php
│       └── NotificationResource.php
├── Services/
│   ├── CustomerPortalService.php
│   ├── CustomerReceiptApprovalService.php
│   ├── CustomerDepositApprovalService.php
│   ├── AccountingService.php
│   └── StatementService.php
└── Notifications/
    ├── CustomerReceiptApproved.php
    ├── CustomerReceiptRejected.php
    ├── CustomerDepositApproved.php
    ├── CustomerDepositRejected.php
    ├── CustomerDebtCreated.php
    └── CustomerInvoiceGenerated.php
```

Use API resources for stable response shape. Use services for approval workflows so Livewire staff pages and API endpoints cannot drift.

## 10. Approval Workflows

### Receipt Upload

```text
Customer uploads receipt
→ ERP validates invoice ownership, amount, file type, reference
→ ERP stores private file
→ customer_receipts.status = pending
→ Accountant sees item in Pending Receipts
→ Accountant approves or rejects
→ On approve:
   - Create customer payment
   - Apply payment to invoice/customer balance
   - Mark receipt approved
   - Store approved_by and approved_at
   - Notify customer
→ On reject:
   - Store rejected_by, rejected_at, rejection_reason
   - Notify customer
```

### Deposit Upload

```text
Customer uploads deposit
→ ERP validates amount, file type, reference
→ ERP stores private file
→ customer_deposits.status = pending
→ Accountant sees item in Pending Deposits
→ Accountant approves or rejects
→ On approve:
   - Set status approved
   - Set balance_amount = amount
   - Store approved_by and approved_at
   - Notify customer
→ On reject:
   - Store rejected_by, rejected_at, rejection_reason
   - Notify customer
```

### Deposit Usage

Deposits are consumed only by ERP staff workflows, usually during POS/credit sale settlement:

```text
Staff selects customer deposit as payment source
→ ERP confirms deposit balance
→ Create customer_deposit_usages row
→ Reduce customer_deposits.balance_amount
→ Increase customer_deposits.used_amount
→ Set status used or partial
→ Link usage to sale where applicable
```

## 11. Notifications

Notifications are persisted in `customer_notifications` and surfaced through the customer API.

Required notification types:

| Type | Trigger |
| --- | --- |
| `receipt_approved` | Receipt approval |
| `receipt_rejected` | Receipt rejection |
| `deposit_approved` | Deposit approval |
| `deposit_rejected` | Deposit rejection |
| `new_debt_added` | Credit sale assigned to customer |
| `new_invoice_generated` | Invoice completed/generated |

Recommended channels:

- Database notifications now.
- Email optional.
- SMS/WhatsApp optional later.
- Web push can be added after PWA push subscription support is implemented.

## 12. Customer Portal API Client

The customer portal should use a dedicated API client:

```php
namespace App\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class HardexErpClient
{
    private function client(?string $token = null): PendingRequest
    {
        return Http::baseUrl(config('hardex.erp_api_base_url'))
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 200)
            ->when($token, fn ($http) => $http->withToken($token));
    }
}
```

The client should expose methods matching the API contract:

- `login(array $payload)`
- `register(array $payload)`
- `logout(string $token)`
- `dashboard(string $token)`
- `debts(string $token, array $query = [])`
- `debt(string $token, int $id)`
- `receipts(string $token, array $query = [])`
- `storeReceipt(string $token, array $payload, UploadedFile $file)`
- `deposits(string $token, array $query = [])`
- `storeDeposit(string $token, array $payload, UploadedFile $file)`
- `statements(string $token, array $query = [])`
- `statementPdf(string $token)`
- `profile(string $token)`
- `updateProfile(string $token, array $payload)`
- `notifications(string $token, array $query = [])`

## 13. Customer Portal UI Architecture

The customer portal is mobile first and PWA installable.

### Layout

Sidebar/navigation items:

- Dashboard
- My Debts
- Upload Receipt
- My Deposits
- Statements
- Notifications
- Profile
- Logout

Desktop:

- Fixed sidebar.
- Top bar with customer name, dark/light toggle, notification indicator, install button.
- Main content in a constrained, readable width.

Mobile:

- Bottom navigation or drawer.
- Sticky header.
- Large tap targets.
- Upload forms optimized for phone camera/file picker.

### Screens

#### Dashboard

Displays API-provided cards:

- Total Outstanding Debt
- Available Deposit Balance
- Credit Limit
- Available Credit
- Last Payment
- Last Purchase
- Pending Receipts
- Pending Deposits

#### My Debts

Columns/cards:

- Invoice Number
- Date
- Products
- Total Amount
- Paid Amount
- Outstanding Balance
- Status

#### Upload Payment Receipt

Fields:

- Invoice
- Amount
- Payment Method
- Reference Number
- Receipt Image/PDF
- Notes

#### My Deposits

Displays:

- Approved Deposits
- Pending Deposits
- Used Deposits
- Remaining Balance

#### Deposit Submission

Fields:

- Amount
- Receipt
- Payment Method
- Reference Number

#### Statements

Features:

- Transaction history
- Debt history
- Deposit history
- Download PDF statement

#### Notifications

Displays unread/read notifications from ERP API.

#### Profile

Allows customer to update name, phone, and email through ERP API.

## 14. Branding And Theme

Use existing Hardex visual assets:

- Logo: `public/images/hardex.png`
- Existing PWA icons: `public/icons/*`
- Primary dark: `#0F172A`
- Background light: `#FFFFFF`

Recommended Tailwind tokens:

```js
theme: {
  extend: {
    colors: {
      hardex: {
        ink: '#0F172A',
        gold: '#F59E0B',
        green: '#16A34A',
        red: '#DC2626',
        mist: '#F8FAFC'
      }
    }
  }
}
```

Dark mode:

- Use Tailwind `darkMode: 'class'`.
- Persist preference in portal local storage.
- Respect `prefers-color-scheme` by default.

## 15. PWA Configuration

Customer portal manifest:

```json
{
  "name": "Hardex Customer App",
  "short_name": "Hardex",
  "description": "Hardex customer debts, deposits, receipts, and statements.",
  "theme_color": "#0F172A",
  "background_color": "#FFFFFF",
  "display": "standalone",
  "orientation": "portrait",
  "start_url": "/dashboard",
  "scope": "/",
  "id": "/",
  "categories": ["business", "productivity", "finance"],
  "icons": [
    { "src": "/icons/icon-192x192.png", "sizes": "192x192", "type": "image/png", "purpose": "any maskable" },
    { "src": "/icons/icon-512x512.png", "sizes": "512x512", "type": "image/png", "purpose": "any maskable" }
  ]
}
```

Service worker strategy:

- Cache app shell, logo, icons, compiled assets, and offline page.
- Network-first for Livewire routes.
- Never cache authenticated API responses in shared caches.
- Avoid caching uploaded files or PDFs.
- Show offline page when navigation fails.

Install UI:

- Button label: `Install Hardex Customer App`
- Show on Android/Desktop when `beforeinstallprompt` is available.
- Show iPhone instructions for Safari share-sheet installation.

## 16. Environment Configuration

### ERP `.env`

```env
APP_NAME="Hardex ERP"
APP_ENV=production
APP_URL=https://staff.buildcore.site
APP_DEBUG=false

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hardex_erp
DB_USERNAME=hardex_erp
DB_PASSWORD=strong-password

SANCTUM_STATEFUL_DOMAINS=staff.buildcore.site,customer.buildcore.site
SESSION_DOMAIN=.buildcore.site

CORS_ALLOWED_ORIGINS=https://customer.buildcore.site
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

### Customer Portal `.env`

```env
APP_NAME="Hardex Customer Portal"
APP_ENV=production
APP_URL=https://customer.buildcore.site
APP_DEBUG=false

HARDEX_ERP_API_BASE_URL=https://staff.buildcore.site/api
HARDEX_CUSTOMER_APP_NAME="Hardex Customer App"

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

The customer portal database must not contain ERP source-of-truth tables.

## 17. Deployment Setup

### DNS

```text
staff.buildcore.site    A/AAAA/CNAME -> ERP server
customer.buildcore.site A/AAAA/CNAME -> Customer portal server
```

### Nginx: ERP

```nginx
server {
    listen 443 ssl http2;
    server_name staff.buildcore.site;
    root /var/www/hardex/hardex-erp/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Nginx: Customer Portal

```nginx
server {
    listen 443 ssl http2;
    server_name customer.buildcore.site;
    root /var/www/hardex/hardex-customer-portal/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Release Checklist

For both apps:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

ERP only:

```bash
php artisan permission:cache-reset
php artisan queue:restart
```

Run workers under Supervisor or systemd:

```text
php artisan queue:work redis --sleep=1 --tries=3 --timeout=120
```

## 18. Scalability Plan

To support thousands of customers and multiple branches:

- Keep ERP and customer portal as separately deployable apps.
- Put the ERP database on a managed or dedicated MySQL server.
- Use Redis for queue, cache, rate limiting, and locks.
- Run multiple PHP-FPM workers behind Nginx.
- Use horizontal scaling for the customer portal because it is stateless apart from session/cache.
- Store uploaded receipts on S3-compatible private storage when traffic grows.
- Queue PDFs and notifications for large reports or bulk statement generation.
- Add database read replicas only for reporting workloads.
- Partition or archive high-volume ledger tables by date if they become large.
- Add branch-aware indexes to stock, sales, purchase, and report queries.

## 19. Production Security Best Practices

- Enforce HTTPS and HSTS on both domains.
- Keep `APP_DEBUG=false`.
- Use separate DB credentials per app.
- Never place ERP DB credentials in the customer portal.
- Encrypt backups.
- Rotate Sanctum tokens on password reset and suspected compromise.
- Log API authentication failures.
- Use Laravel policies for staff actions and customer ownership.
- Restrict file downloads by role/account ownership.
- Virus-scan uploaded PDFs/images when possible.
- Use strict MIME validation and extension validation.
- Add audit logs for approval/rejection workflows.
- Use least-privilege server users.
- Set secure cookie flags:
  - `SESSION_SECURE_COOKIE=true`
  - `SESSION_HTTP_ONLY=true`
  - `SameSite=Lax` or stricter where practical.
- Apply CSP for the customer portal.
- Monitor queue failures, API error rates, slow queries, disk usage, and failed login spikes.

## 20. Implementation Status In This Repository

This workspace already contains a combined Laravel implementation with many of the required pieces:

- Laravel `^13.8`
- Sanctum
- Livewire 3
- Volt
- Tailwind CSS
- Spatie permission
- ERP web routes
- Customer API routes under `/api/customer`
- Customer account, receipt, deposit, deposit usage, and notification tables
- PWA manifest, icons, and service worker
- Staff admin pages for customer accounts, receipts, deposits, statements, and notifications
- Customer-facing Livewire pages

For strict production compliance with the requested architecture, split the customer-facing Livewire portal into its own Laravel 13 application and replace direct model access there with the `HardexErpClient` API client. Keep the current `/api/customer/*` implementation in the ERP app as the master integration boundary.

