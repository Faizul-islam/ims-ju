# Backlog

Prioritized work from the gaps in [delta_apec.md](delta_apec.md). `D-xx` refers to a delta in that file. Items are in suggested working order.

**Size** is a rough estimate: **S** under half a day, **M** one to two days, **L** three days or more.

**Conventions for this work:** make schema changes as new migrations on top of the SQL dump, never with `migrate:fresh`. Point `DB_DATABASE` at a throwaway database before running Feature tests, because they wipe it.

## Decisions needed

These change the scope of the items named.

| # | Question | Affects | Suggested answer |
| --- | --- | --- | --- |
| 1 | Should allocation stay as a direct issue, or need approval? | B-13 | Keep it as a direct issue, but restrict it to the Inventory Manager with its own capability, and correct the report |
| 2 | Should self-registration stay? | B-21 | Remove it; the Abstract says accounts are issued by staff |
| 3 | Keep or delete the on-support and vendor-return modules? | B-33 | Delete unless they are wanted soon |
| 4 | Is the system staying single-department? | B-27, B-28 | Yes for now; it avoids a large rework |
| 5 | Is the sample data worth keeping once balances are corrected? | B-10 | It looks like test data, so rebuild it |

## P0: Trustworthy stock and closed access

Do these before anything else. Nothing built on stock figures can be relied on until they are done.

| ID | Task | Done when | Deltas | Size |
| --- | --- | --- | --- | --- |
| B-01 | Set up a safe test database, then add tests for receive, issue, allocate, return, edit and delete | `phpunit` uses a dedicated database, and each movement has a test asserting balances | D-33 | M |
| B-02 | Protect `request-item/approve/{id}`: require the approve capability to approve, and the requester or receive capability to receive | A user without the capability gets 401 on the `PUT` | D-07 | S |
| B-03 | Stop accepting workflow fields from form input in request, allocation and return saves | Posting `is_approved=1` to create or update has no effect | D-08 | S |
| B-04 | Bind parameters in `getStockItems()` and `getRoomWiseStockItems()` | No request value is concatenated into SQL, and a quote in a filter is harmless | D-09 | S |
| B-05 | Make `Stock::remove()` fail on a missing row or short balance, and check availability on the server | Over-issuing shows an error, rolls back, and leaves balances unchanged | D-02 | M |
| B-06 | Reject receiving a request that is already received | A second `PUT` changes nothing | D-03 | S |
| B-07 | Fix returns: add a migration for the returning user and location, remove from the actual holder, and validate the amount held | The holder decreases and the store increases by the same amount; returning more than held is rejected; edits reverse correctly | D-01 | M |
| B-08 | Reverse old rows when an allocation is edited | Editing does not change the total, only the difference is moved. Correct the line in `CLAUDE.md` that says this already happens | D-04 | S |
| B-09 | Reverse stock on delete, or block delete once stock has moved | No delete can leave balances out of step with transactions | D-05 | M |
| B-10 | Add a reconciliation query, and correct or rebuild existing balances | Query shows stock equal to receipts plus or minus movements for every item, including the phantom 10 units of item 3 | D-01 | S |

## P1: Make the specified workflow true

| ID | Task | Done when | Deltas | Size |
| --- | --- | --- | --- | --- |
| B-11 | Define and seed Inventory Manager, Teacher and Department Chairman roles | Each role exists after setup, with a test user per role | D-14 | M |
| B-12 | Give the item and quantity lookups the capability of the form using them | A view-and-add user can complete a request | D-15 | S |
| B-13 | Apply decision 1 to allocation: separate capabilities, and remove or implement the dead approve route | Only authorized roles can allocate, and no route points at a missing method | D-17 | M |
| B-14 | Add a request state flow: requested, forwarded, approved, received | Invalid transitions are rejected; a forwarded request can only be approved by the forwardee; forwarding is limited to approvers; requesters cannot approve their own | D-16 | M |
| B-15 | Limit requests and returns to their owner, except for managers | A Teacher cannot view or edit another user's records | D-10 | M |
| B-16 | Block editing or deleting a request once approved, on the server | Direct requests to those routes are rejected | D-05, D-16 | S |
| B-17 | Add server-side validation for request, allocation, return and receive lines | At least one line, positive whole quantities, valid item IDs | D-19 | M |
| B-18 | Point item uniqueness at `inv_item_information`, and add unique keys to items and categories | Duplicates are rejected; a name matching a category is accepted. Check for existing duplicates before the migration | D-20 | S |
| B-19 | Fix the return list's table references | The date filter returns results | D-27 | S |
| B-20 | Add a `view_stock_reports` capability, use it on the stock routes and sidebar, then run `setadminpermissions` | A Chairman can see stock reports without activity-log access | D-11 | S |

## P2: Complete the specification

| ID | Task | Done when | Deltas | Size |
| --- | --- | --- | --- | --- |
| B-21 | Apply decision 2 to `/register` | The route is removed, or the report and setup are aligned | D-13 | S |
| B-22 | Enforce the password policy on every path | Reset, registration, user admin and profile all reject weak passwords | D-12 | S |
| B-23 | Add item status setup, and carry status through issue and return | A returned item can be recorded as damaged and appears that way in reports | D-22 | L |
| B-24 | Track serials from receipt through issue and return, and add a lookup | Serial required where the spec says; "who holds serial X" can be answered | D-21 | L |
| B-25 | Add the re-order input and a low-stock report | Items below minimum are listed | D-24 | M |
| B-26 | Filter inactive items out of new-transaction pickers, and support `name_bn` in pickers and reports | Inactive items do not appear; Bengali names show under the `bn` locale | D-23, D-30 | S |
| B-29 | Add supplier code columns and shared store and update validation | Matches the data dictionary | D-25 | S |
| B-30 | Let approved staff create a request on behalf of a teacher | The request records both the requester and who entered it | D-18 | S |
| B-31 | Log changes to stock, items and users, and show them in the activity report | Each change records who, what and when | D-29 | M |
| B-32 | Add item, category and status filters to reports, hide zero rows, and fix the `name_bn` filter | Filters work in preview and exports | D-28 | S |
| B-34 | Translate the 117 missing strings into `bn.json` | A re-run of the scan finds none missing | D-30 | M |
| B-35 | Add inventory figures to the dashboard: stock, pending requests, low stock | Figures match the reports | D-31 | M |
| B-36 | Add a mail service to `docker-compose.yml` and set the sender in `.env.example` | A password reset email arrives in the Docker setup | D-34 | S |
| B-37 | Add indexes and a row lock on stock, and a unique key on the stock key columns. Measure reports with realistic data | Under 2 seconds at an agreed data volume; concurrent moves stay correct. `asset_location_id` and `user_id` are nullable, so MySQL will not enforce uniqueness on nulls without a workaround | D-06, D-32 | M |

## P3: Planned features and cleanup

| ID | Task | Done when | Deltas | Size |
| --- | --- | --- | --- | --- |
| B-27 | Replace the hardcoded `'dept_cse'` with a real department, if decision 4 changes | Stock is separated by department, and multi-department reports work | D-35 | L |
| B-28 | Finish transfers and transfer receipts: capabilities, sidebar, stock movement with reversal | Stock leaves on send and arrives on receipt. Depends on B-27 | D-36 | L |
| B-38 | Add item disposal with an approval step | Disposed items leave stock and stay in history | D-37 | M |
| B-39 | Add notifications for new requests, forwards, approvals and low stock | The next person to act is told | D-38 | M |
| B-33 | Apply decision 3 to the on-support modules | Modules are removed, or reachable and working | Section 3 of the delta | S |
| B-40 | Remove legacy dashboards, routes and undefined capabilities | Every route's capabilities exist, or the route is gone | Section 3 of the delta | M |
| B-41 | Write the user manual and UAT scripts per role | A tester can follow them without help | D-33 | M |
| B-42 | Update the data dictionary and issue report corrections | The report matches the final schema | D-26, Section 4 of the delta | S |
| B-43 | Plan an upgrade from Laravel 8 | A target version and steps are agreed | Section 4 of the delta | M |
| B-44 | Tidy the repository: remove tracked `_doc/~$*` lock files, ignore `repomix-output.xml`, and remove real password hashes from committed dumps if there are any | `git status` is clean of generated files | | S |
