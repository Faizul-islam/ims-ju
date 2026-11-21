# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Integrated web-based Inventory Management System (IMS), built as an MSc project. It is a Laravel 13 / PHP 8.3 / MySQL app with server-rendered Blade views, jQuery and Bootstrap 4. It was upgraded from Laravel 8 but keeps the pre-Laravel-11 structure (`app/Http/Kernel.php`, `app/Console/Kernel.php`, providers listed in `config/app.php`), which Laravel 13 still supports. The UI is bilingual (Bengali default, English). The project report is in `_doc/`.

The code is a fork of an older government CMS (NATP-2 / LGSP3), so much of it is unrelated to inventory: farmers, CIGs, procurement, AIF monitoring, training helpers. Active work is under `Inventory`, `Reports`, `Users` and part of `Settings`. Expect leftover references to classes and tables that no longer exist (e.g. `App\Models\Procurement\Tenderer`, the `app/Repositories/Monitoring` bindings).

`README.md` is the stock Laravel readme. `CONTRIBUTING.md` is the setup guide, but its Docker section is out of date: there is no `app` service, the app is not on port 8080, and `felixkiss/uniquewith-validator` is not a dependency. Trust `docker-compose.yml`.

## Commands

### Docker

| Service | Purpose | Host port |
| --- | --- | --- |
| `nginx-pmit-ju` | Web | 9530 |
| `php-fpm-pmit-ju` | PHP 8.3; run artisan and composer here | none |
| `mysql-pmit-ju` | MySQL 8 | 3530 |
| `phpmyadmin-pmit-ju` | phpMyAdmin | 9531 |

```bash
docker-compose up -d
docker-compose exec php-fpm-pmit-ju composer install
docker-compose exec php-fpm-pmit-ju php artisan <command>
```

MySQL data persists in `./volumes/mysql` (gitignored). The PHP container runs only `php-fpm`, so run `composer install` yourself after the first `up`.

Inside containers, set `DB_HOST=mysql-pmit-ju` and `DB_PORT=3306`. The committed `.env.example` and local `.env` use `127.0.0.1`, which only works when PHP runs on the host.

### Assets

Laravel Mix 6; entries are listed in `webpack.mix.js`. Bootstrap 4's SCSS relies on Sass `@import`, so `webpack.mix.js` silences those Dart Sass deprecation warnings; moving to Bootstrap 5 would mean rewriting jQuery plugin markup (`data-toggle` etc.) across the views.

```bash
npm run dev     # one-off build
npm run watch   # rebuild on change
npm run prod    # production build
```

### Tests

```bash
php artisan test                           # everything
php artisan test --testsuite=Feature
php artisan test --filter=AuthenticationTest
vendor/bin/phpunit tests/Feature/AuthenticationTest.php
```

**Tests wipe their database.** The Feature tests use `RefreshDatabase`, so `phpunit.xml` points them at a separate MySQL database, `iu_ims_testing`. Create it once with `CREATE DATABASE iu_ims_testing;`. `tests/CreatesApplication.php` refuses to run against any database whose name doesn't end in `_testing`. If a run is refused, clear any cached config with `php artisan config:clear`. Test coverage is only the Breeze auth scaffolding plus example tests (self-registration is removed, and `RegistrationTest` asserts `/register` is a 404); inventory code has none. `RefreshDatabase` only builds the legacy migrations, so tests can't use tables that lack a migration, such as `inv_stocks`.

Because routes are prefixed with the locale seen at boot, `CreatesApplication` boots the test app under `/bn` (`TestCase::LOCALE`), so test URLs must start with `/bn/`. The login form posts a single `login` field (email or username), not `email`.

On Windows, the first `RefreshDatabase` run is very slow (MySQL DDL on the bind-mounted `./volumes/mysql`), so expect the suite to take a long time there.

### Lint

There is no lint script. `phpcs.xml` (PSR-2, `app/` only, no line-length rule) and `.styleci.yml` exist, but `phpcs` is not a Composer dependency.

### Application setup

```bash
php artisan setadminpermissions   # sync the Administrator role with config/capabilities.php
php artisan setup-application     # create or update the Subscriber role
php artisan storage:link
```

## Database

**`php artisan migrate` alone does not produce a working schema.** The SQL dumps in `_scripts/` are the source of truth; use the newest one (currently `iu_ims_20250227.sql`).

- The migrations are the legacy set, ending in July 2021. They have no migrations for `inv_stocks`, `inv_request_items*`, `inv_item_allocation*` or `inv_return_items`/`inv_return_item_details`, all of which the current code needs.
- The dump has 34 tables, most of the old CMS tables are gone, and it records all 307 migrations as already run. Legacy features that use the removed tables (e.g. `divisions`, `districts`) will fail against it.

So set up by importing the dump into the database named in `.env`, not by migrating. Add schema changes as new migrations, which will then run on top of the dump. Do not run `migrate:fresh`.

## Architecture

### Routing and locale

- `RouteServiceProvider` loads `routes/api.php`, `routes/web/uam_mdm.php` (users, master data, settings; it also requires `routes/auth.php`) and `routes/web/inventory.php`.
- Web routes are prefixed with `Request::segment(1)`, the locale from the current URL. URLs look like `/{en|bn}/admin/inventory/...`, and `config('app.admin_route_prefix')` supplies `/admin`.
- Because the prefix depends on the live request, don't use `php artisan route:cache`: it would freeze the prefix at the value seen on the command line.
- The `locale` middleware redirects to the fallback locale (`bn`) when the first segment isn't an active language.
- Routes use the string form (`'ItemInformationController@index'`) with `->namespace()` groups, and redirects use `action('Inventory\SupplierController@create')`. Follow this style.

### Permissions

Authorization does not use Laravel Gates or Policies (`AuthServiceProvider` is empty). It is a custom capability system:

- `config/capabilities.php` is the registry of all capabilities, and it also drives the role-editing UI.
- Roles live in `roles_permissions` (`App\Models\RolePermission`) as serialized arrays. A user's role is the `_role` entry in `user_meta`.
- A user's own `_capabilities` meta **replaces** their role's capabilities when present (`User::getUserCaps`).
- `hasUserCap()` is a global helper from `app/Helpers/Shortcuts.php`.
  - The `permissions:a;b` route middleware is strict: the user needs **all** listed capabilities. Separate them with `;`, because a comma breaks the string.
  - `hasUserCap(['a','b'])` in Blade is non-strict: **any** one is enough.

To add a capability:
1. Add it to `config/capabilities.php`.
2. Apply it with `->middleware('permissions:...')` on the route.
3. Wrap the sidebar link in `hasUserCap()`.
4. Run `php artisan setadminpermissions`, or Administrators won't have it.

### Multi-tenant scoping

- `App\Scopes\OrganizationScope` filters queries by the logged-in user's `organization_id`, and is added in each model's `booted()`. Users without an organization see every row.
- Not every model has it: `Stock` and `RequestItem`, for example, do not.
- The scope calls `auth()`, so scoped models behave differently in console commands and queued contexts.
- `Controller::pushUserSessionData()` stamps `created_by`/`updated_by`, `organization_id`, and the `selected_organogram_id`/`selected_project_id` session values onto request data.

### Module pattern

Each feature follows the same shape, with query logic on the Eloquent model rather than in a service layer:

- **Model statics:** `Model::getAll($args, $id = null)` builds the listing query (defaults merged by `parseArguments()`, filters collected in controllers by `filterParams()`). `Model::getMasterData($record = null)` returns dropdown data for forms.
- **Master/detail saves:** these are static methods on the detail model, e.g. `InvSupplierItemInfo::saveSupplierItemInfo()`. Updates delete the detail rows and re-insert them.
- **Controllers:** resource-style, with `DB::beginTransaction`/`DB::transaction` around multi-table writes.
- **Views:** `resources/views/{module}/{feature}/{index,create,edit,show}.blade.php`. Inventory pages include `layouts.admin-sidebar-inventory`.
- **Repositories:** `app/Repositories` is legacy AIF monitoring code. Don't use it for new features.
- **Scaffolding:** `resources/crud-generator` holds customized stubs for `appzcoder/crud-generator`. The package was removed because it doesn't support Laravel 13, so the stubs are unused.
- **Forms:** `Form::`/`Html::` come from `rdx/laravelcollective-html`, a maintained fork of LaravelCollective with the same API.
- **Images:** uploads are resized with Intervention Image v4 (`Image::decodePath()->scaleDown()`), not the v2 `Image::make()->resize()` API.

Large helper libraries are autoloaded globally: `app/Helpers/Utilities.php` and `app/Helpers/Shortcuts.php`. Check them before writing a new one. Options and user meta have WordPress-style helpers (`getOption`, `getUserMeta`) with caching behind them; `common_labels` holds generic lookup lists via `getCommonLabels($data_type)`.

### Inventory stock

`App\Models\Inventory\Stock` (`inv_stocks`) holds current balances, keyed by `item_id` + `item_status_id` + `user_id` + `dept` + `asset_location_id`. All movements go through `Stock::add()` and `Stock::remove()`:

- `user_id = null` is the central store. A non-null `user_id` is stock held by that user.
- Issuing moves stock: `remove` from the store, then `add` to the requester at their location. See `RequestItemController::updateStock()`.
- Editing a transaction must first reverse the old detail rows, then apply the new ones, or balances drift. `InvSupplierItemInfo::saveItems()` does this correctly; copy it. **Known bugs, don't copy them:** `ItemAllocationDetails::saveRequestedItem()` deletes old rows without reversing them, and `ReturnItemDetails` removes stock from the logged-in user with no `asset_location_id`, so it misses the holder's row while still adding to the store. No `destroy()` reverses stock.
- `dept` is hardcoded to `'dept_cse'` throughout, and `item_status_id = 1` means "good". A mismatch in any key field finds no row.
- `Stock::remove()` silently does nothing when no row matches or the row's balance is 0, and doesn't check that enough stock exists, so quantities can go negative. Validate availability before calling it.

Item requests go through an approval flow (`RequestItem`, `RequestItemDetails`, `RequestItemApprovalHistory`) using `is_requested`/`is_approved`/`is_received` flags and `forwarded_user_id`; stock moves in `updateStock()`. One route, `PUT request-item/approve/{id}`, handles approve, forward and receive, so it is authorized inside `RequestItemController::approve()` rather than by route middleware. Master `saveOrUpdate()` methods take an explicit `$request->only([...])` whitelist; workflow flags are set server-side, never from form input.

`backlog.md` lists the known gaps in priority order, and `delta_apec.md` compares the code with the project report. Check them before fixing inventory behaviour.

### Reports

Report controllers delegate to `App\Helpers\Reports` (`indexMaster`, `showMaster`), which handles the filter page, preview, and PDF (mPDF) and `.xls` export using the `layouts/report*` views. Stock reports use raw SQL with bound parameters in `Stock::getStockItems()` and `getRoomWiseStockItems()`. Never concatenate request values into SQL.

### Localization

- User-facing strings use `__('English text')`, with Bengali in `resources/lang/bn.json` and `resources/lang/{en,bn}/master.php`.
- Bilingual data uses paired `name_en`/`name_bn` columns, selected with `"name_{$lang}"` or `resolveFieldName()`.
- `app/Http/ENtoBN.php` handles number and date transliteration.
