# Delta Spec: Project Report vs. Code

What the MSc project report specifies, compared with what the code does today. Work items are in [backlog.md](backlog.md).

| | |
| --- | --- |
| **Spec baseline** | [IU MSc Project Defense Report](_doc/IU%20MSc%20Project%20Defense%20%20Report.pdf), February 2025 |
| **Code baseline** | `develop` at `34caec6`, with `_scripts/iu_ims_20250227.sql` as the schema and sample data |
| **Audited** | 2026-11-07 |
| **Method** | Read the code and inspected the dump. Nothing was executed, so findings about runtime behaviour come from the code path, not from a run. |

## Summary

Of the 23 features listed in report §3.5, **11 are present, 11 are partial and 1 is broken**. Everything is at least attempted, but the stock balance cannot be trusted yet, and the approval step is not enforced on the server.

Fix these first:

1. **Stock balances drift.** Returns, repeat receipts, allocation edits and deletes all produce wrong totals (D-01 to D-05). The sample data already shows it: return #1 created 10 units of an item that was never received.
2. **Approval can be bypassed.** Any logged-in user can approve a request or receive its items (D-07), or set `is_approved` when creating the request (D-08).
3. **Stock reports are open to SQL injection** (D-09).
4. **The report's roles do not exist.** The dump has only the Administrator role, so Teacher, Inventory Manager and Department Chairman behaviour has not been exercised (D-14).

Severity: **S1** corrupts data or bypasses security, **S2** a specified behaviour fails, **S3** a specified behaviour is incomplete, **S4** documentation or minor, **P** planned future work (report §6.2).

## 1. Feature status (report §3.5)

**Present** means the routes and screens exist and no delta was found. Depth of checking varied, and the UI was not run. **Partial** means it works with gaps. **Broken** means normal use gives wrong results.

| # | Feature | Status | Deltas |
| --- | --- | --- | --- |
| 1 | Dynamic user management | Present | |
| 2 | Role and permission management | Partial | D-14 |
| 3 | Dynamic setup data | Present | |
| 4 | Employee information | Present | |
| 5 | Responsive design | Present | |
| 6 | Login | Present | |
| 7 | Sign out | Present | |
| 8 | Forget password | Present | D-34 |
| 9 | User profile | Present | |
| 10 | Item categories | Present | |
| 11 | Dynamic item configuration | Partial | D-20, D-23, D-24 |
| 12 | Supplier information | Partial | D-25 |
| 13 | Item purchase from supplier | Partial | D-05, D-21 |
| 14 | Item request | Partial | D-15, D-18, D-19 |
| 15 | Requested item approval | Partial | D-03, D-07, D-08, D-16 |
| 16 | Allocation to employees | Partial | D-02, D-04, D-05, D-17 |
| 17 | Return to Inventory Manager | **Broken** | D-01, D-05, D-27 |
| 18 | Stock report | Partial | D-09, D-11, D-22 |
| 19 | Room-wise stock report | Partial | D-09, D-11 |
| 20 | User activity report | Partial | D-29 |
| 21 | Downloadable reports (PDF, Word, Excel) | Present | |
| 22 | User-friendly filters | Partial | D-27, D-28 |
| 23 | Interactive UI | Present | D-30 |

## 2. Deltas

### Stock integrity

#### D-01 · S1 · Returns do not reduce the holder's stock

- **Spec:** Return management hands items back to the custodian (§3.5 #17). A return carries item status, serial and fixed asset ID (§4.6).
- **Code:** [ReturnItemDetails](app/Models/Inventory/ReturnItemDetails.php) adds to the store, then removes from `Auth::id()` with no `asset_location_id`. Issued stock is held at a location, so the lookup finds no row and `Stock::remove()` silently does nothing. Nothing checks that the user holds the item. `inv_return_items` does not record who returned it, and the form lists every item.
- **Evidence:** Dump return #1 is 10 units of item 3. Item 3 has no receipt and no holder, yet `inv_stocks` id 9 shows 10 in the store.

#### D-02 · S1 · Issuing creates stock when the store is short

- **Spec:** Items are issued "if items are available" (Abstract).
- **Code:** [RequestItemController::updateStock()](app/Http/Controllers/Inventory/RequestItemController.php) and [ItemAllocationDetails](app/Models/Inventory/ItemAllocationDetails.php) ignore the result of `Stock::remove()` and always call `Stock::add()`. If the store row is missing or at zero, the user gains stock from nothing. If it is too low, the store goes negative. The allocation form fetches availability in the browser, but the server never checks.

#### D-03 · S1 · Receiving twice moves stock twice

- **Code:** `RequestItemController::approve()` calls `updateStock()` without checking `is_received`. Only the list page hides the link, so a repeated `PUT` or a double submit issues the items again.

#### D-04 · S1 · Editing an allocation applies the stock move again

- **Code:** [ItemAllocationDetails::saveRequestedItem()](app/Models/Inventory/ItemAllocationDetails.php) deletes the old rows and repeats the move without reversing it. Editing an allocation of 2 units takes 4 from the store.
- **Note:** `CLAUDE.md` lists this class as one that reverses old rows. It does not.

#### D-05 · S1 · Deletes leave stock unchanged

- **Code:** `destroy()` in the receive, allocation, return and request controllers removes the records and never touches `inv_stocks`. Deleted transactions stay in the balance. The approved-request case is the same: the delete and edit routes do not check request state.

#### D-06 · S3 · Stock updates are not safe under concurrency

- **Code:** `Stock::add()` reads and then writes with no row lock, and `inv_stocks` has no unique key on its five key columns. Two simultaneous moves can lose an update or create duplicate rows.

### Access and security

#### D-07 · S1 · The approve action has no capability check

- **Spec:** Requests are approved by the Inventory Manager or Chairman; the application is "highly secure" (Abstract).
- **Code:** The middleware on [`PUT request-item/approve/{id}`](routes/web/inventory.php#L135-L142) is commented out, leaving only `auth`. Any logged-in user can approve any request, or receive it and move stock. `approve_item_request_information` guards only the page that shows the form.

#### D-08 · S2 · Approval fields can be set from the form

- **Code:** `RequestItem::saveOrUpdate()` passes `$request->all()` into a model whose `$fillable` includes `is_approved`, `approved_by`, `is_received` and `requested_by`. A user with only the add capability can create a request that is already approved. `ItemAllocation` and `ReturnItem` follow the same pattern.

#### D-09 · S1 · SQL injection in stock reports

- **Code:** [Stock::getStockItems()](app/Models/Inventory/Stock.php) and `getRoomWiseStockItems()` concatenate `user_id` and `location_id` from the request into raw SQL. Exploiting it requires a login and report access.

#### D-10 · S2 · Users can see and change each other's requests

- **Spec:** Role-based access; employees request items for themselves.
- **Code:** `RequestItem` and `ItemAllocation` listings are not filtered by user, and `show`, `edit`, `update` and `destroy` do not check ownership. `RequestItem` also lacks `OrganizationScope`.

#### D-11 · S3 · Stock reports need the activity-log capability

- **Spec:** A Department Chairman views the stock report (§4.2 story 6).
- **Code:** [The route](routes/web/uam_mdm.php#L719) requires `view_user_activity_log_reports`, apparently copied from the route above it. A user who should see stock must also be granted activity-log access. There is no stock report capability.

#### D-12 · S3 · Password policy is weaker than specified

- **Spec:** Letters, digits and at least one special character (§4.6 `users`).
- **Code:** Every path uses only `min:8`.

#### D-13 · S3 · Self-registration is open but unlinked

- **Spec:** It conflicts with itself. Phase 1 lists Registration, while the Abstract says users get credentials from the Inventory Manager and Admins create accounts.
- **Code:** The login page's link is commented out, but `/register` still accepts guests and assigns `subscriber`, a role missing from the dump until `setup-application` runs.

### Workflow and roles

#### D-14 · S2 · The specified roles do not exist

- **Spec:** Admin, Inventory Manager, Teacher, Department Chairman (§3.4).
- **Code:** `roles_permissions` holds only Administrator, and all four sample users have that role. The capability system works, but no role matches the report, so the role-specific paths below are untested.

#### D-15 · S2 · Users with only "add" cannot pick an item

- **Code:** The request form loads items through [`getItemByCategory`](routes/web/inventory.php#L120), which requires `edit_item_request_information`. A Teacher given view and add gets a 401 and an empty dropdown. [`getItemQtyByItemId`](routes/web/inventory.php#L88) has the same problem.

#### D-16 · S2 · Forwarding does not route anything

- **Spec:** The Inventory Manager approves or forwards to the Chairman for higher-level approval (Abstract, §1).
- **Code:** `forwarded_user_id` is written but never read. A forwarded request is not assigned to anyone: every approver sees it and can approve it, and it can be forwarded to any user. There are no state rules.
- **Evidence:** Dump request 19 was approved and then forwarded. Request 20 was approved by its own requester.

#### D-17 · S2 · Allocations bypass approval

- **Spec:** "Approval Flow for Allocations" (§2.2).
- **Code:** Allocations move stock immediately. `is_approved` is never set. [The approve route](routes/web/inventory.php#L105-L110) points at `ItemAllocationController@approve`, which does not exist. Allocation shares the request capabilities, so anyone who can request can also allocate stock directly to any user.

#### D-18 · S3 · Admin cannot request on behalf of a teacher

- **Spec:** §3.2.
- **Code:** `requested_by` is always `Auth::id()`. Allocation is the nearest substitute, but it skips approval.

### Data rules

#### D-19 · S2 · Transactions have no server-side validation

- **Spec:** "Increase Accuracy through validation" (§3.2.1).
- **Code:** Request, allocation and return controllers validate nothing, so quantities can be zero or negative and line items can be absent. Receive validates only the header.
- **Evidence:** Dump allocation 23 has no detail rows.

#### D-20 · S2 · Item uniqueness checks the wrong table

- **Spec:** Item name and code are unique (§4.6).
- **Code:** [InvItemInformationRequest](app/Http/Requests/Inventory/InvItemInformationRequest.php#L30) tests against the category table. Duplicate items pass, while an item named like a category is rejected. No `inv_*` table has a unique key to catch this.

#### D-21 · S2 · Serials are not enforced or tracked

- **Spec:** Serial is mandatory for fixed assets and serialized items. The core problem is not knowing "who took which laptop" (§3.1.1, §4.6).
- **Code:** Nothing requires a serial on receipt. Allocation never writes `serial_no`, return details have no serial column, and `inv_stocks` is quantity-only. The approval form stores a serial, but no report can answer who holds a given one. `is_serialized` defaults to Yes in the form, while the dictionary says false.

#### D-22 · S2 · Item condition cannot be tracked

- **Spec:** Track "available items and their conditions" (§1); item status comes from a status setup (§4.6).
- **Code:** [getItemStatus()](app/Helpers/Utilities.php) is a hardcoded list with only "Good", and issues and returns hardcode status 1. A damaged item cannot be recorded. Any other status value would also make the report's status lookup fail.

#### D-23 · S3 · Inactive items are still offered

- **Spec:** Inactive items are unavailable in new transactions (§4.6).
- **Code:** All pickers use `InvItemInformation::pluck()` with no `is_active` filter.

#### D-24 · S3 · Replenishment insight is missing

- **Spec:** "Generate insights for inventory replenishment" (§1); minimum re-order quantity (§4.6).
- **Code:** `min_re_order_qty` exists in the table and `$fillable`, but the item form has no input for it and no report or alert uses it.

#### D-25 · S3 · Supplier fields differ from the dictionary

- **Spec:** Code is mandatory and unique; contact number, email and address are mandatory or validated (§4.6).
- **Code:** `inv_suppliers` has no code columns. `store` validates only `name_en`, and `update` validates nothing.

#### D-26 · S4 · Other data dictionary differences

| Table | Dictionary says | Dump has |
| --- | --- | --- |
| `users` | Role ID foreign key | Role stored in `user_meta` |
| `inv_request_item_details` | Item status, serial, fixed asset ID | `serial_no` only |
| `inv_return_items` | Created by, created date | `created_at` only; no user column |
| `inv_return_item_details` | Item status, serial, fixed asset ID | None of these |
| Not documented | | `inv_stocks`, `inv_item_allocations`, `inv_item_allocation_details`, `inv_request_item_approval_history`, `inv_return_item_details` |

Among the `inv_*` tables, only `inv_item_return_on_supports` has a foreign key.

### Reports and interface

#### D-27 · S2 · The return list's date filter should fail

- **Code:** [ReturnItem::getAll()](app/Models/Inventory/ReturnItem.php#L65-L75) was copied from `RequestItem` and still filters on `inv_request_items`, which is not in the query. Using the date filter would raise an SQL error.

#### D-28 · S3 · Filters are limited

- **Spec:** "Advanced filters" (§3.5 #22).
- **Code:** Stock reports filter only by user, plus location on the room report, not by item, category or status. Zero-quantity rows are not excluded. The item list's `name_bn` filter [reads `code_en`](app/Models/Inventory/InvItemInformation.php#L74).

#### D-29 · S3 · The activity log covers logins only

- **Spec:** "Track all system interactions" (§2.2).
- **Code:** `access_logs` is written only at login and logout. No record exists of who changed stock, items or users.

#### D-30 · S3 · Inventory screens are mostly untranslated

- **Spec:** Dual language across the whole application (§2.2, Phase 2).
- **Code:** A scan of single-quoted `__()` calls in inventory and report views found **117 of 169 strings missing from `bn.json`**. Bengali is the default locale, so these screens appear largely in English. Item, category and supplier pickers and report rows also hardcode `name_en`.

#### D-31 · S3 · The dashboard has no inventory data

- **Spec:** A monitoring dashboard (§3.2, §4.2 story 1).
- **Code:** `DashboardController::showInventoryModule()` passes no data and has no stock or request queries. The controller still serves legacy monitoring, training and procurement dashboards.

### Non-functional

#### D-32 · S3 · Performance requirements are unverified

- **Spec:** Respond within 2 seconds; handle large databases (§3.3).
- **Code:** No measurements exist, the dump has only a few rows, and `inv_*` tables have almost no secondary indexes. Stock reports return all rows at once.

#### D-33 · S2 · No inventory tests or UAT material

- **Spec:** UAT and training materials (§3.2).
- **Code:** Tests cover only Breeze auth. Stock logic, the highest-risk code, has none. No manual or UAT material is in the repo.

#### D-34 · S3 · Password reset has no mail service in Docker

- **Code:** `.env.example` points at `mailhog`, but `docker-compose.yml` has no mail service, so reset emails cannot be delivered in that setup.

### Planned work (report §3.1.5, §6.2)

#### D-35 · P · Single-department design

- **Spec:** "What will happen for the Multiple department stock report?" is an open risk.
- **Code:** `'dept_cse'` is hardcoded in every stock path. This blocks D-36.

#### D-36 · P · Transfers and transfer receipts

- **Spec:** Future work items 3 and 4.
- **Code:** Scaffolding exists: controllers, models, views and tables. It is unusable because the routes require capabilities that are not in `config/capabilities_inventory.php`, so even Administrators get a 401. There is no sidebar link, and it never calls `Stock`.

#### D-37 · P · Item disposal

- **Spec:** Future work item 1; also an open risk in §3.1.5.
- **Code:** Nothing exists.

#### D-38 · P · Notifications and alerts

- **Spec:** Future work item 2; "reminder & alert" (§3.2.1).
- **Code:** Nothing exists beyond Laravel's built-in email verification and password reset.

## 3. In the code but not in the report

| Item | Notes |
| --- | --- |
| On-support and return-to-vendor modules | Three controllers with routes and tables. They have the same capability gap as D-36, so they are unreachable. |
| Legacy dashboards, routes and capabilities | Monitoring, training, procurement, farmers, geography. Many route capabilities are undefined, including `view_salary_reports` on the activity-log lookup route. |
| Organization scoping | `OrganizationScope` is multi-tenant groundwork that the report does not mention, and it is applied unevenly. |

## 4. Corrections to the report

The code is right in these cases, so the report is what should change if it is reissued.

| Report says | Actually |
| --- | --- |
| Web server is Apache (§5.4) | Docker setup uses nginx and PHP-FPM |
| Cites Laravel 10.x documentation (ref. 8) | Locked to Laravel 8.83.29, which is past end of support |
| Transfers are entirely future work (§6.2) | Partly scaffolded (D-36) |
| Data dictionary covers the schema (§4.6) | See D-26 |
