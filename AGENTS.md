# HARDEX engineering rules

These rules apply throughout the repository. Before implementing new features, review the [production memory exhaustion incident](docs/production-memory-incident.md) and the fixes in commit `781c43b`. The incident exposed two independent failures. Resolving a cold authentication guard from a company scope re-entered the identity lookup, and the Dashboard loaded an unbounded stock ledger into PHP. Preserve the resolved-identity checks, SQL aggregation and bounded reads introduced by the fixes.

## 1. Authentication and tenant isolation

- Never introduce recursive authentication, session resolution or company global scope evaluation. `CompanyScope` and `HasCompany` must read only already-resolved identities. Check that a guard has a user before calling any method that could hydrate it.
- Preserve company, branch, location and user authorization boundaries on reads and writes, including aggregates and background work.
- Test both staff and customer portal authentication. Exercise sign-in and saved sessions whose guards start unresolved.

## 2. Memory and database performance

- Never load unbounded stock movements, sales, purchases or transaction histories into PHP. Do not use unrestricted `get()` or `all()` on large transactional datasets.
- Prefer SQL aggregation, indexed queries, pagination and bounded processing. Apply authorization filters before aggregation and bound results before hydrating models.
- Preserve transactional locking, stock integrity and financial calculation accuracy.
- Never raise PHP memory limits to compensate for inefficient queries. Validate critical pages on realistic large datasets with PHP `memory_limit=128M`. Record HTTP outcomes and peak PHP memory.

## 3. Production deployment permissions

- Use `umask 022` for Git operations, Composer installation and application source files. Never use `umask 077` across an entire deployment. Apply restrictive permissions specifically to secrets and backups.
- Generate Laravel caches as the Apache runtime user, `www-data`. Ensure `www-data` can read application source and vendor dependencies and can write to `storage` and `bootstrap/cache`.
- Protect `.env` and cached configuration from unauthorized access. Keep required runtime access and never use `chmod 777`.

## 4. Standard deployment compatibility

Keep updates compatible with customer servers and Docker deployments using the standard workflow:

1. `git pull --ff-only origin main`
2. `composer install` when required
3. `npm ci && npm run build` when required
4. `php artisan migrate --force`
5. Rebuild Laravel caches as `www-data`.

Do not require custom deployment scripts, new containers, additional infrastructure or manual database operations without explicit approval.

## 5. Database migration safety

- Leave previously deployed migrations intact. Use new forward-only migrations for schema changes.
- Never run `migrate:fresh`, `migrate:reset`, `db:wipe` or destructive seeders against production databases.
- Preserve customer records, stock movements, financial records, user roles and customized permissions.
- Verify the upgrade on a restored production-like database before deployment. Check record integrity, role assignments and effective permissions after the upgrade.

## 6. Testing database safety

- Never run automated tests against production or development business databases. Use isolated test databases and restricted database users.
- Preserve the existing fail-closed database safety guard. Never bypass it to run tests.
- Verify the actual connection, including the database and authenticated account, before any destructive test operation. Abort if it differs from the explicitly permitted test connection.

## 7. Stock Projection restrictions

- Stock Projection remains deferred. Do not deploy it, enable it or turn on `STOCK_PROJECTION_DASHBOARD_READS` without explicit approval.
- Keep the pending projection migrations out of `main`. Preserve the existing ledger fallback.
- Disabling projection reads does not disable database triggers. Check trigger state separately when assessing write behavior.

## 8. Mandatory regression testing

Before marking any significant task complete, verify all of the following:

- Staff sign-in and customer portal sign-in.
- Authenticated Dashboard, Products and Inventory Summary.
- POS and stock deduction.
- Purchases and goods received notes (GRNs).
- Stock transfer and cancellation.
- Financial reports.
- Roles and permissions.
- Tenant and branch isolation.
- HTTP responses and memory consumption.
- Application compatibility with cached and uncached Laravel configuration.

## 9. Release discipline

- Inspect the Git changes before committing. Keep secrets, local test credentials, database backups and unrelated unfinished features out of commits.
- Never push directly to `main` or deploy to production without explicit authorization.
- Report actual test results, failed or incomplete checks, known limitations and deployment requirements.
- Never claim production readiness solely because migrations or compilation succeeded.
