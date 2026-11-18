## Bulk fulfillments

In this branch we are working on the new bulk fulfillments feature. This feature should allow
fulfilling all relevant WooCommerce orders (orders that are actually ready to be shipped) in multiple steps/actions.

Nothing is released yet, so the DB schema, scripts and classes may be replaced freely.

---

## 1. Goals (original brief)

- **One loop**: a single, regularly synced, FIFO queue of orders that are ready to ship. All workflows work on this queue,
  and multiple users work on the same queue simultaneously. Orders currently being fulfilled are **locked** so no other
  user/worker can fulfill them at the same time.
- An order may pass through **several workflows** (e.g. "Pick & Pack" by one user, later "DHL Labels" by another).
  It stays in the queue until it is shipped; it is only unavailable while locked by someone else.
- **Warehouses & locations**: warehouses may belong to a **group** (= physical location). Splitting happens per
  location in the **shipping packages** (cart → shipping lines → shipments): warehouses of the same group ship together,
  an ungrouped warehouse is a location of its own, products without a warehouse form the default location.
  A shipment may contain items of multiple warehouses. Workflows can be filtered to one or more locations/warehouses.
- **Backorders**: backordered units are never put into shipments automatically, users are warned (see §3.7).
- **Storage locations**: a per-warehouse location schema defines how locations are sorted for picking (see §3.8).
- **Batch picking** is part of v1.
- **Offline by default**: every step works offline unless it needs external server data (carrier APIs, documents).
- **Workflows**: users create/edit multiple workflows. A workflow = *filters* (which orders, e.g. shipping provider/method)
  + *ordered steps* (Pick, Pack, Create labels, Print documents, custom). Steps may run in the background and only
  surface on errors; a workflow made only of background-capable steps runs fully in the background.
- **Actions**: registrable by third parties. Each declares a *phase* (before queue / per item / after queue) and a
  *scope* (order or shipment). Shipments get created (auto or manual) first, then per-shipment steps run.
- **UI**: a SPA, fast, mobile/touch-first, drag & drop, works **offline** and syncs. Uses the WP Interactivity API so
  PHP hooks/filters can change the markup (especially per-action views). Does not load wp-admin scripts/styles
  (custom `Scripts` loader). DataViews/DataForm serves as visual reference:
  https://wordpress.github.io/gutenberg/?path=/docs/dataviews-dataform--docs

---

## 2. Current state (branch `fulfillments`)

What exists:

- Tables `stc_bulk_fulfillments(+meta)`, `stc_bulk_fulfillment_orders(+meta)`, `stc_warehouses(+meta)`.
- `BulkFulfillment` = a *batch*: on `initialize()` it copies matching orders (shipping status filter, date range)
  into `bulk_fulfillment_orders` in pages of 50. Navigation is prev/next by row id.
- `BulkFulfillmentOrder` builds an *action loop* per order (order-scope actions first, then shipment-scope actions
  expanded per shipment, with `must_run_before` dependencies injected). Step state is stored as JSON in `action_data`.
- Actions: `CreateShipments` (most complete: client-side shipment editing, DnD, bin packing via binpackingjs),
  `Pick` and `Pack` (stubs).
- UI: standalone page `admin.php?page=wc-shiptastic-fulfillment` rendered outside the wp-admin chrome, own script
  loader (`Scripts`, `FulfillmentScripts`, `FulfillmentScriptModules`), iAPI store `shiptastic/fulfillments`,
  iAPI router navigation between orders/actions. Saving goes through admin-ajax (`save_bulk_fulfillment`), which returns
  the full HTML of the next step. Local drafts are kept in `localStorage`.
- `Rest/BulkFulfillmentsController` (wc/v3/bulk-fulfillments) is a skeleton.

Where the current model doesn't fit the goals:

| Goal | Current | Gap |
|---|---|---|
| One shared, synced loop | One snapshot per batch, built once | No sync; same order can be in several batches; FIFO by row id, not by order date |
| Locking | `locked_by`/`date_locked` columns only | No claim/release/expiry logic |
| Workflows (reusable definitions) | Filters/actions live on the batch | No separation between workflow *definition* and *execution* |
| Background steps | Not implemented | No runner, no error inbox |
| Run phases (before/after queue) | Declared in `get_supported_run_contexts()` but unused | — |
| Offline | `localStorage` draft for the current order | Each step needs a server HTML round-trip; no outbox, no prefetch, no SW |
| Single transport | admin-ajax + half REST + placeholder `my-plugin/v1` endpoint | Should be one REST API |

Smaller bugs noticed while reading (to fix during refactor):

- `FulfillmentAction` uses `self::get_supported_types()` / `self::get_supported_contexts()` /
  `self::get_supported_run_contexts()` — needs `static::`, otherwise subclass overrides are ignored.
- `Factory::get_bulk_fulfillment()` reads cache group `bulk-fulfillment` but writes `bulk-fulfillments`.
- `BulkFulfillmentOrder` imports `Vendidero\Germanized\Shipments\Order` (should be `Vendidero\Shiptastic\Order`).
- `BulkFulfillmentsController::prepare_fulfillment()` calls a non-existent `BulkFulfillment::get_current_action()`.
- `Ajax::save_bulk_fulfillment()` silently returns (no response) on `WP_Error`; there is no lock/ownership check.

---

## 3. Target architecture

### 3.1 Data model (replaces the bulk_fulfillment tables)

```
stc_fulfillment_queue            -- THE loop: one row per (order, package) that is (or was recently) ready to ship
  queue_id            PK
  order_id
  package_key         {location}|{shipping method id}, e.g. "nord|flat_rate:1" ('' location = default location)
  location            location of the package (group or ungrouped warehouse name, '' = default location)
  UNIQUE (order_id, package_key)
  date_queued_gmt     FIFO key (date paid, fallback date created)
  status              pending | locked | error | done | removed
  is_stale            1 = lost its eligibility while locked (e.g. order cancelled during fulfillment)
                      (done = order shipped / no longer needs fulfillment; NOT "a workflow finished")
  workflow_id         workflow of the current lock holder (0 = not locked); informational only
  locked_by           user id (scheduled/background runs: the configured run-as admin, see §3.4)
  lock_source         interactive | background | scheduled
  lock_token          random token for the claim (used for idempotent writes)
  lock_expires_gmt    lock TTL, extended via heartbeat
  version             int, ++ on every write (optimistic concurrency for offline sync)
  last_error          text
  -- denormalized filter columns, refreshed on sync, so claiming is pure SQL:
  shipping_provider, shipping_method, country, item_count, weight, has_backorders
  (weight = content weight without packaging, store weight unit, recomputed on each sync of the order — e.g. for
  "small parcels" / "freight" workflows; item_count e.g. for "single-item orders" workflows)
  KEY (status, location, date_queued_gmt), KEY (workflow_id, status), KEY (lock_expires_gmt)

stc_fulfillment_workflows        -- definitions
  workflow_id, name, status (active|inactive), priority, mode (interactive|background),
  filters (json), steps (json: [{action, scope, phase, settings}]), date_created/modified
  (+ meta table)

stc_fulfillment_steps            -- per-ORDER action state (replaces action_data JSON); shared across workflows
  step_log_id, queue_id, action, shipment_id (0 for order scope),
  status (open|done|error|skipped), data (json),
  workflow_id + user_id (who did it, for audit), date_done_gmt, error
  UNIQUE (queue_id, action, shipment_id)
```

Step state is keyed by **action**, not by workflow step: if workflow A already ran `create_shipments` for an order,
workflow B sees it as done and skips it (unless the action is repeatable, see §3.5). Shipment-scope state is keyed by
shipment, so a shipment added later (e.g. partial shipping) gets its own open steps.

Notes:
- Denormalized filter columns are the key to performance: claiming the next N orders for a workflow is a single indexed
  `UPDATE … WHERE status='pending' AND <filters> ORDER BY date_queued_gmt LIMIT N`, without loading WC orders.
  Filters that cannot be expressed in SQL are allowed (filter hook) but evaluated in PHP after claiming; non-matching
  rows are released immediately.
- **Queue unit = order × package** (shipping line). The location split itself happens earlier in the shipping
  packages (§3.6); the queue just mirrors the packages. Locks are per row, so two packages of one order can be
  fulfilled by different users/workflows at the same time.
  - Each queue item only "sees" its package's items: `create_shipments` only offers/packs those.
  - A queue item is `done` when its package is fully shipped; the order is shipped when all its queue items are.

### 3.2 Queue sync

- **Event-driven upsert**: hook into order status/payment changes, shipment create/status changes, order edits
  → recompute eligibility + denormalized columns for that order (deferred to shutdown/Action Scheduler to keep
  checkout fast).
- **Periodic reconcile** (Action Scheduler, e.g. every 5 min + on app open if stale): cursor over orders modified since
  last run; insert new eligible orders, mark no-longer-eligible ones `removed` (unless locked → flag for the user).
- Eligibility = filterable (`woocommerce_shiptastic_fulfillment_order_is_eligible`), default: paid statuses + shipping
  status in `not-shipped | partially-shipped | ready-for-shipping`, has shippable items.
- Retention: purge `done/removed` rows after X days.

### 3.3 Locking

- A lock belongs to a **user/device session**, not to a workflow. Many users claim from the same queue in parallel;
  the atomic UPDATE guarantees each order is handed to exactly one of them at a time.
- `claim( workflow, n )`: atomic UPDATE setting `status=locked, workflow_id, locked_by, lock_token, lock_expires_gmt`
  on the next N `pending` rows that match the workflow's filters **and still have open steps for that workflow**
  (subquery/anti-join on `stc_fulfillment_steps`); returns the claimed rows. Expired locks are claimable again.
- **Defaults** (filterable + setting):
  - Lock TTL **30 min**. Heartbeat every **2 min** while online resets expiry to now + 30 min for all items the
    device holds. Covers short offline periods (up to ~30 min after the last heartbeat) while an abandoned tab blocks
    orders for at most 30 min.
  - Claim window **10** queue items per device (item-by-item workflows); refill (claim 10 more) when **5** are left.
    At ~1–2 min per order, 10 items ≈ the TTL, so a device never holds more than it can finish offline. Payload is
    ~5–20 KB JSON per item + small thumbnails → cheap to prefetch on mobile; larger windows would mostly block
    other users.
  - Batch picking: batch size **20** (per-workflow setting, max 50); same TTL/heartbeat.
  - Background/scheduled runs: claim chunks of **25**, lock TTL 10 min, released after each chunk.
- `release( queue_id, token )` on skip/close **and when the workflow's last step finishes**: status goes back to
  `pending` (other workflows can pick it up), or `done` if the order is now fully shipped.
- All writes for a queue item require a matching `lock_token` (or a still-valid re-claim) and the expected `version`;
  otherwise → 409 conflict, the client shows the conflict and refetches.
- Admin can force-unlock from the queue overview.

### 3.4 Workflows

- CRUD via REST, UI built with the same SPA (list + DataForm-like editor).
- Filters: warehouse(s) (multi-select; most workflows will be per warehouse), shipping provider, country/zone, item count/weight ranges, order date,
  custom (filter hook registering extra filter types with SQL + PHP evaluators).
- Steps: ordered list of actions with per-action settings (schema provided by the action → rendered as a form).
- An order matching several workflows can go through all of them, one at a time (whoever claims it first holds the
  lock). A workflow only claims orders that still have open steps for it, so finished orders don't reappear.
- `priority` decides the order in which the background runner processes workflows.
- **Schedules**: background-only workflows can run on a schedule (interval, e.g. every 15 min, or fixed times, e.g.
  daily 13:30 before carrier pickup), via Action Scheduler.
  - **Run as admin**: a "run as" user setting (default: the admin who saved the workflow; must have
    `manage_woocommerce`). The job calls `wp_set_current_user()` for that user, re-checks the capability at run time
    (if missing → skip run + admin notice). Audit/step log records the user + `lock_source = scheduled`.
  - One run per workflow at a time (unique AS action), processed in chunks of 25 queue items per AS job, re-queued
    until no matching items are left — avoids timeouts and keeps lock contention low.
- **Error handling** (per-workflow setting `on_error`, applies to **scheduled runs only**):
  - `continue` (default for scheduled workflows): the failed item is marked `error` (lock released, step log keeps the
    error) and lands in the exception inbox; the run continues with the next item. Optional summary notification
    (admin notice + e-mail digest) after the run if errors occurred.
  - `stop`: the run halts at the first failed item; remaining items stay `pending`; the schedule is paused until an
    admin resolves the error and resumes; admin notice + e-mail immediately.
  - **Runs with a user present** (interactive steps, or background steps started from the UI) never fail silently,
    regardless of `on_error`: the run stops at the failed item and shows the error to the user, who can fix + retry,
    skip the item (→ `error`/exception inbox) or abort. Background steps started from the UI keep processing other
    items only after the user chose "skip".
- **Dependencies are auto-injected**: when a workflow claims an order and a step's `requires` are not done yet (by any
  workflow), the required actions are prepended to that order's step list. Most actions require `create_shipments`,
  so a "DHL Labels" workflow claiming an order without shipments creates them first.
  - The injected step's mode follows the workflow configuration: if the workflow lists `create_shipments` explicitly,
    its settings/mode (background = auto by packaging rules, interactive = manual editor) are used; otherwise a
    workflow-level default ("create missing shipments: automatically | manually") applies.
  - Injected steps that run in the background only surface in the UI on error (e.g. items don't fit any packaging),
    falling back to the interactive editor for that order.
  - The editor shows injected dependencies as implicit (greyed) steps so users see what will happen.
- Validation on save: dependencies (`requires`) satisfied, scope/phase supported, background-only workflow only if every
  step supports background mode.

### 3.5 Actions API

```php
abstract class FulfillmentAction {
    static function get_name(): string;
    static function get_title(): string;
    static function get_description(): string;
    static function get_scopes(): array;          // ['order'] | ['shipment'] | both
    static function get_phases(): array;          // ['before_queue', 'item', 'after_queue']
    static function get_modes(): array;           // ['interactive', 'background']
    static function get_requires(): array;        // default ['create_shipments']; satisfied if done by ANY workflow,
                                                  // otherwise auto-injected before this step (see §3.4)
    static function is_repeatable(): bool;        // default false: done state is shared across workflows; true = runs each time (e.g. print)
    static function requires_network(): bool;     // default false (offline-capable); true for carrier APIs, documents
    static function get_settings_schema(): array; // JSON-schema-ish, drives the workflow editor form

    function run( StepContext $ctx ): StepResult;                 // background mode
    function get_view_data( StepContext $ctx ): array;            // interactive: JSON payload for the client
    function process( StepContext $ctx, array $data ): StepResult;// interactive: submit
    static function get_template(): string;                       // iAPI markup (server-rendered once per app load)
    static function get_script_module(): ?string;                 // optional iAPI store module for this action
}
```

- Registration: `woocommerce_shiptastic_fulfillment_actions` filter (name ⇒ class); third parties ship their own
  template + script module, which the SPA shell loads.
- Phases:
  - `before_queue`: runs once over the claimed batch (e.g. **pick list / wave picking** aggregated across orders).
  - `item`: per order or per shipment (create shipments, pack, label, documents).
  - `after_queue`: once over the finished batch (e.g. **print all labels as one PDF**, carrier manifest/close-out).
- **Batch picking (v1)** — `pick` supports two phases:
  - `item` phase: pick per order (simple stores, small batches).
  - `before_queue` phase: after claiming a batch (e.g. 20 queue items of one warehouse), build one **pick list**
    aggregated by product (sum of quantities across all orders), sorted by pick sequence (§3.8, fallback: SKU). The picker walks once through the warehouse, confirms each line by
    tap or scan.
  - **Sort to orders**: after picking, the run continues per order (pack step); the pack view shows which picked units
    belong to which order (optionally a tote/bin number assigned per order at claim time, printed on the pick list).
  - **Short picks**: a line picked with less than the required quantity → affected orders are marked (`error` with
    reason "short pick"), released from the batch and land in the exception inbox; the rest continues.
  - Pick state is stored per queue item (step log), so a batch-picked order counts as picked for any later workflow.
- Built-in actions (v1): `create_shipments` (auto by packaging rules or manual), `pick` (per order or batch), `pack` (scan-to-verify),
  `create_label`, `print_documents` (label/packing slip/invoice via existing attachment/document APIs),
  `mark_shipped`.
- Background runner: Action Scheduler job per chunk of queue items; on error writes `last_error`, sets status
  `error` and behaves according to §3.4 (scheduled: per-workflow `on_error`; user present: always surfaced). Items in
  `error` show up in the **exception inbox**, where a user resolves them interactively and resumes.

### 3.6 Warehouses, locations & shipping packages (implemented in milestone 1)

- **Model**: warehouses and **warehouse groups** live in `stc_warehouses` (groups have `type = group`). A warehouse
  references its group by name (`_group` meta). The **location** of a warehouse is its group, or the warehouse itself
  if ungrouped (or the group was deleted). Products without (an existing) warehouse belong to the **default location**
  (`''`, shipper address, global settings).
- **Location settings**: backorder handling (§3.7) on the group or an ungrouped warehouse. **Sender address**: only
  groups may override it (toggle "Custom address" + address fields); ungrouped warehouses and the default location
  always use the shipper address. The storage location schema (§3.8) is a *warehouse* setting, not a location setting.
- **UI**: Pick & Pack → Warehouses: group list (title, warehouse count), warehouse list (title, type, group), global
  backorder default; edit page per group/warehouse (`admin.php?page=shipment-warehouse&warehouse={id}`). Grouped
  warehouses link to their group for location settings.
- **Cart**: `split_cart_packages()` splits by **location** first, then the existing "ship separately via provider" split
  runs *inside* each location package. Package key = location + provider (e.g. `nord`, `nord:dhl`, `berlin`, `:dhl`).
  Packages get a `location` key; coupons are kept for location packages, removed for provider packages (as before).
- **Checkout**: `MethodHelper::register_rate_meta()` stores `_packaged_items` (now summing quantities of the same product
  across cart items) and `_location`; Woo copies rate meta onto the shipping line.
- **Orders**: `Order::get_packages()` splits each shipping line package by location: lines with `_packaged_items` use the
  line's `_location`; otherwise (admin/REST/legacy orders) items are split by product location
  (`Order::split_items_by_location()`, filterable). Package keys: `{method_id}` or `{method_id}@{location}`.
- **Shipments**: `location` prop (column `shipment_location`, `ShipmentQuery` arg `location`) set from the package;
  shipments created without explicit location derive it from their items (mixed locations → default).
  Each **shipment item** stores its `warehouse` (snapshot of the product warehouse), so a shipment can contain items of
  several warehouses: `Shipment::get_warehouses()`. Sender address comes from the shipment's location if it has an address.

### 3.7 Backorders (implemented — warnings only)

1. **Record** the backordered quantity per order item when Woo reduces stock, in our own meta `_stc_backordered_qty`
   (Woo's `Backordered` meta key is translated and unreliable to read back). Restoring stock (e.g. cancelled order)
   clears it.
2. **Backordered units are never added by default**: automatic packing/shipment creation, "Create shipment(s)" and
   "Sync items" (and `wc_stc_create_shipment()` / REST without explicit items) leave them out. After creating/syncing
   shipments in the order screen, a warning lists the units left out. Users add them manually (raise the quantity
   within a shipment or "Add item", where they are labelled "(n on backorder)"); a later sync keeps them.
3. **No automatic release ("refill")** and **no hold handling**: allocating restocked units to orders is a stock
   management concern for Woo core, not Shiptastic (decided against it). As backorders are never released
   automatically, a "hold until complete" setting would block packages forever, so it was removed as well (global,
   location and per-order settings). Integrations may adjust the quantity via the filter
   `woocommerce_shiptastic_order_item_backordered_quantity`.
4. Fulfillment queue (later): show packages with backordered units with a warning; do not hold them.

### 3.8 Storage locations (implemented)

- **Per-warehouse schema** (not per group — storage locations are physically inside a warehouse), edited on the
  warehouse page (`Warehouse\LocationSchema`, stored in `_location_schema`):
  - separator (default `-`) and an ordered list of **levels** (e.g. Zone → Aisle → Rack → Bin), each with type
    *Number*, *Letters* or *List*, direction asc/desc and **Snake** (reverse the direction for every
    other value of the previous level, e.g. aisle 1 front→back, aisle 2 back→front).
  - **List orders**: one drag & drop table per List level ("Order: Zone", "Order: Floor"), created/renamed live
    while editing the levels. New warehouses default to Aisle + Rack (snake).
  - **Overrides**: location → "sort like" another location (for spots outside the pattern).
  - **Preview** (not saved, re-rendered via AJAX from the unsaved settings on every change):
    - *Walking path*: numbered order of example codes generated from the levels (2 × 3 × 2 × 1 grid) plus entered codes
      (highlighted) — shows direction and snake at a glance; no products are loaded.
- **Pick sequence**: each level is encoded as a fixed-width 6-digit segment (descending/snake = reversed, unknown values
  = 999999 → sorted last, missing trailing levels = 0 → first within their parent). Without levels: natural sort.
  Sequences are plain strings, sortable in SQL.
- **Products**: "Storage location" field next to the warehouse select (variations may override, otherwise inherit the
  parent location); validated against the schema on save (error notice, stored anyway). Also fixed: the product
  warehouse select was never saved.
- **No lookup table** (decided against it): pick sequences are computed on demand from product meta + schema, so they
  can never be stale (imports/direct DB writes) and schema changes apply immediately. Pick lists load their products
  anyway; sorting is a few string operations per product. Zone-based selection in SQL (later) will use denormalized
  columns on the fulfillment queue rows instead (§3.1).
- **API**: `StorageLocations::sort_product_ids()` (warehouse order, then pick sequence, products without location last),
  `get_product_locations()`, `Warehouse::get_pick_sequence()` / `validate_location_code()`. Invalid codes are reported
  when saving a product (no bulk check of existing product locations in the settings — decided against it).
- Later: zone picking (split pick lists per zone/first level), several locations per product.

### 3.9 REST API (single transport, replaces admin-ajax)

Namespace `wc/v3/shiptastic/fulfillment` (or own `shiptastic/v1`):

- `GET/POST/PUT/DELETE workflows`
- `GET queue` (overview: counts per status/workflow, paginated items)
- `POST workflows/{id}/claim` → claimed items incl. full view payloads (enables prefetch/offline)
- `POST queue/{id}/heartbeat`, `POST queue/{id}/release`
- `POST queue/{id}/steps/{step}` → submit step data (`lock_token`, `version`, `idempotency_key`)
- `POST batch` → replay many queued mutations in one request (offline sync)
- nonce refresh → WordPress core `admin-ajax.php?action=rest-nonce` (REST routes are unauthenticated with an expired nonce)

### 3.10 SPA / UI

- **Shell**: one HTML document (existing standalone page + own script loader). Server renders, once per load, the shell
  plus all action templates (`<template>` per action, with iAPI directives, filterable via PHP hooks). After that,
  moving between orders/steps is **client-side state change only** — no HTML round-trip. This is what makes offline
  and instant navigation possible while keeping PHP-side extensibility of the markup.
  (Today every step re-fetches full HTML via the iAPI router; the router can still be used for the coarse screens
  — queue overview, workflow editor, run view — but not per order/step.)
- **Screens**: Queue overview → Workflow list/editor → Run (claimed batch; current order; step view) → Exception inbox.
- **Look**: mimic DataViews/DataForm styling (list/grid/table layouts, filters, fields) with our own CSS; do **not**
  import `@wordpress/dataviews` (React) — too heavy for this app.
- **Input**: touch targets ≥ 44px, swipe for next/prev/skip, DnD for items between shipments (existing), barcode scanner
  support (keyboard-wedge scanners: global key buffer, match SKU/GTIN `globalUniqueId`) for pick/pack verification.
- **Performance budget**: shell + core store < ~50 kB gz JS, no wp-admin CSS; payload per order JSON only.

### 3.11 Offline & sync

- **Storage**: IndexedDB (replaces `localStorage`): claimed queue items + view payloads, packaging list, outbox.
- **Prefetch**: claim current + next N (configurable) so the next orders are available without network.
- **Outbox**: every mutation is stored with `idempotency_key`, `lock_token`, base `version`; replayed via `POST batch`
  when online (`online` event + periodic retry). Server dedupes by idempotency key so retries never create duplicate
  shipments.
- **Temp IDs**: shipments created offline get client UUIDs; server returns the id mapping on sync.
- **Conflicts**: `version` mismatch / lost lock → mark item conflicted, show diff/"reload from server" choice.
- **Offline is the default**: every step works offline unless `requires_network()` is true. Offline-capable in v1:
  create shipments (manual editor; auto packing runs client-side via binpackingjs, the result is synced), pick
  (per order and batch), pack, skip/notes. Requires network: label creation, document generation/printing, anything
  calling carrier or third-party APIs.
- **Network steps while offline**: the order's offline steps are completed and queued in the outbox; the network
  steps are marked "waiting for connection" and the user moves on to the next order. Once online the outbox is
  replayed, then the network steps run — in the background if the action supports it (errors → exception inbox),
  otherwise the order is shown again in a "ready to finish" list.
- **Locks while offline**: 30 min TTL after the last heartbeat (§3.3); longer offline periods are handled by conflict
  detection rather than longer locks. If a lock expired and someone else claimed the item, sync reports a conflict
  for that item instead of overwriting.
- **Service worker** for the app shell: register it from a URL under `/wp-admin/` (e.g. served by a dedicated endpoint
  with `Content-Type: application/javascript`) so its scope covers the fulfillment page; its fetch handler must only
  handle fulfillment-page/asset/REST requests and pass everything else through untouched.
- Nonces: REST nonce expires (12–24h) → refresh via `POST nonce` before replaying the outbox.

---

## 4. Milestones

1. ✅ **Warehouse packages + backorders** (§3.6, §3.7): warehouse groups/locations + settings UI, cart/order package split,
   shipping line meta, shipment `location` + item `warehouse`, backorder recording + warnings. Valuable on its own (also
   without bulk fulfillment) and the queue depends on it.
2. ✅ **Data model + sync + locking** (PHP only): tables `stc_fulfillment_queue`, `stc_fulfillment_steps`,
   `stc_fulfillment_workflows(+meta)`; classes `BulkFulfillments\Queue` (atomic FIFO claim via single
   `UPDATE … ORDER BY … LIMIT`, heartbeat, verify_lock/version, release, mark_error, reset, expired lock reset),
   `QueueItem`, `QueueSync` (events → Action Scheduler, 5 min reconcile with modification cursor, only while an active
   workflow exists), `StepLog`, `Workflow` + data store. Queue package key = `{location}|{shipping method}`; packages
   come from items not yet in shipments plus shipments not yet shipped. Old `BulkFulfillment(Order)` model, tables,
   data stores, REST controller and AJAX handler removed; the legacy page is no longer loaded — its action classes,
   View and Scripts stay in the repo (unloaded) as source for porting in milestones 3 and 5.
   Open for M3: shipment-scope steps (claim currently checks order-scope step entries only), PHP-evaluated filters.
3. ✅ **Actions API v2 + runner**: `Action` base (scopes, phases, modes, requires, repeatable, requires_network,
   settings schema; `run()` / `get_view_data()` / `process()`), `ActionRegistry` (filter
   `woocommerce_shiptastic_fulfillment_actions`), `Step`/`StepContext`/`StepResult`, `Runner` (step plan with injected
   dependencies + shipment scope expansion, execute until input/error/completion, order scope roll-up for shipment
   steps, `process_chunk()` with scheduled continue/stop and user-started stop-and-keep-locked, `skip()`), `Scheduler`
   (interval/daily schedules in site timezone via single actions, one run at a time, chunks of 25, run as user with
   capability check, pause + admin notice + e-mail, resume). Workflow: paused state, `validate()` (`create_shipments_mode` removed in M5: shipments are edited via the overlay).
   Ported `create_shipments` (per package, background + interactive layout with temp ids), `pick` (sorted pick list,
   short pick error), `pack` (shipment scope, packaging/measures; the status is changed by the separate `mark_ready` step). `Order::create_shipments()`
   can be restricted to a package. §2 bugs are gone with the removed legacy classes.
   Not yet: before/after queue phases are declared but only the item phase is executed (batch picking: M8), templates /
   script modules (M5), e-mail digest for continued scheduled runs, PHP-evaluated claim filters.
4. ✅ **REST API** (§3.9): `Rest\FulfillmentController` under `wc/v3/shiptastic/fulfillment`: workflows CRUD (+ validate,
   run_as = the user who saved last) + `claim` (runs each item until its first interactive step → prefetch payload)
   + `resume`; queue list (+ counts), item (+ steps/log for a workflow), `heartbeat` (token), `run`, `steps/{key}`
   (token, version, data), `release`, `skip`, `reset` (managers); `batch` (queue mutations only, max 50, independent).
   Idempotency via body `idempotency_key` / header `Idempotency-Key` (`BulkFulfillments\Idempotency`, 24 h, replayed
   responses carry `Idempotent-Replayed: true`). Lock errors → 409/403/404, step errors → 200 with `current.status =
   error`. Permissions: fulfill = `edit_shop_orders`, manage = `manage_woocommerce` (filterable). Nonce refresh after
   long offline periods: WordPress core `admin-ajax.php?action=rest-nonce` (a REST route cannot work with an expired
   nonce). The legacy admin-ajax handler was already removed in M2.
5. ✅ **SPA refactor**: `BulkFulfillments\App` (page `admin.php?page=wc-shiptastic-fulfillment`, submenu WooCommerce →
   Fulfillment, standalone document, no wp-admin CSS/JS) replaces the legacy `View` + `admin-fulfillments.js`. Shell
   `includes/admin/views/fulfillment/app.php` + one template per action (`Action::get_template()`, default
   `views/fulfillment/actions/{name}.php`, filter `woocommerce_shiptastic_fulfillment_action_template`; partials
   `partials/check-list.php`, `partials/shipment-fields.php`), processed once via `wp_interactivity_process_directives`.
   Script modules are served unbuilt from `assets/js/fulfillment/` (`app.js` store `shiptastic/fulfillment`, `api.js`
   fetch + nonce refresh, `db.js` IndexedDB kv, `gestures.js` pointer-based swipe (`data-stc-swipe` → `stcswipe`) and
   drag & drop (`data-stc-drag-handle` / `data-stc-drop` → `stcdrop`), `checklist.js` item verification shared by
   pick/pack (scan, swipe right +1 / left −1, steppers, all), `shipments.js` overlay; per action `actions/{name}.js` via
   `Action::get_script_module()` contributing `callbacks.initForm_{name}` / `collect_{name}`).
   **Shipments overlay** (`views/fulfillment/shipments.php`): available during the whole run (button in the order
   header, also in step errors) instead of a create-shipments step — `create_shipments` is background-only (default
   layout by packaging rules, backorders never added automatically). Pool of items not in a shipment (incl.
   backordered units) + shipments; items are added/removed/moved via drag & drop, swipe or steppers; per shipment
   packaging, shipping provider, content weight (empty = calculated) and dimensions (locked while a packaging is
   chosen). Saved via `POST queue/{id}/shipments` (`BulkFulfillments\ShipmentLayout::apply()`), which continues the
   run: changed shipments reopen their shipment-scope steps (+ roll-ups), changed totals reopen order-scope steps of
   actions with `Action::resets_on_shipment_changes()` (pick). Packed (ready-for-shipping) shipments without label
   stay editable and go back to the default status when their items change; shipped/labelled ones are locked
   (filter `woocommerce_shiptastic_fulfillment_can_edit_shipment`). The run payload carries `layout` per item.
   Pack verifies the shipment items (`packed`, error `short_pack`) and shows the packaging + package dimensions to use
   (read-only, "Change" opens the overlay where shipment details are edited).
   "Add shipment" is offered only while items are not part of a shipment (one empty shipment at a time).
   **Back navigation**: done interactive steps of the current order can be reopened (step bar or Back button,
   `POST queue/{id}/steps/{key}/reopen`, `Runner::reopen()`): the log entry becomes `open` and keeps its data, which is
   passed as `view_data.previous` to prefill the form (also after failed submits). Finished orders are released and
   cannot be reopened from the run.
   **Header**: no fixed top bar; a page header following the WordPress admin UI page header (light, breadcrumbs
   "Fulfillment / workflow / #order" — the root navigates back to the overview —, subtitle "Order x of y", actions
   Shipments + Exit). The overlay uses the same light header. Below the WordPress mobile breakpoint (782px) the run
   header collapses to "← #order" with "workflow · Order x of y" as subtitle (Exit only on the overview).
   **Step navigation** (scales to many steps, e.g. per-shipment label/document steps): "Step x of y" + current step
   title, a segmented progress bar (one segment per interactive step) and a step list on demand (done steps can be
   redone, upcoming ones are disabled; closes on outside click or Escape).
   **Validation**: steps may register `callbacks.validate_{action}()` (client side, nothing is sent while invalid; the
   message and highlighted rows update live). Pick/pack use `callbacks.validateChecklist()` and show "x/y items" + a
   progress bar. The shipments overlay covers the full viewport (content centered at the app width).
   **Queue overview**: skeleton rows while loading (stale responses are ignored), paginated (25 per page, `total`/`pages` + `X-WP-Total`), FIFO like claims, filterable by
   workflow (`workflow_id`: its filters + open steps), open orders per workflow (`open_count`, `open_counts`).
   Styles `assets/css/fulfillment.scss` → `build/static/fulfillment-styles.css`. Screens: overview (workflows, queue
   tabs with counts, reset of exceptions) and run (claim → current order → steps; refill at ≤ 5 left, heartbeat every
   2 min, put back = release + excluded from further claims of the run (`claim` arg `exclude`), back = batch release).
   The current run is stored in IndexedDB and refreshed via `run` after a reload. Errors of interactive steps (e.g.
   short pick) keep the form for corrections; background step errors offer retry / move to exceptions.
   Filters: `woocommerce_shiptastic_fulfillment_app_config`, `…_app_state`; actions `…_app_register_modules`,
   `…_fulfillment_head`, `…_fulfillment_print_footer_scripts`.
   Not yet: relative imports carry no `?ver` (cache busting), keyboard-wedge scanning outside the scan field.
5b. ✅ **Automation runs** (`BulkFulfillments\Run`, `RunProcessor`, tables `stc_fulfillment_runs` + `_run_errors`):
   a run automates a whole workflow (all steps support the background) or one automatable step of a workflow
   (`Queue::get_scope_conditions()`: earlier listed steps done, step open; injected dependencies run too) for all
   orders of the workflow. `RunProcessor::tick()` = one step (claim 1 → next step in scope → `Runner::run_step()`).
   Modes: **autonomous** (errors recorded per order + step, order → exceptions, run continues; report afterwards,
   retry per error = back to the queue) and **supervised** (stops at the first error, order stays locked for the run;
   retry / skip (→ exceptions) / stop). Runs are always processed in the background (Action Scheduler jobs, 20 s time
   box; the async queue runner is dispatched explicitly since runs are started/polled via REST, stalled runs are
   kicked on status requests). The automation screen in the app opens right after starting (progress, current
   order/step, pause/continue/stop, retry/skip, report; polled every 2 s, leaving it keeps the run going). Other admin
   pages show a minimal overlay for the starting user (`AutomationOverlay`, `assets/js/static/fulfillment-automation.js`:
   status, progress, current order/step, link to the screen, dismiss when finished). Scheduled workflows start runs
   as well (on_error continue → autonomous, stop → supervised + paused schedule); `Runner::process_chunk()` removed.
   **Fixing**: when a supervised run stops, the app jumps into the failed order (order screen with the failed step
   and its error, `GET runs/{id}/item` — the run keeps the lock): fix it (e.g. "Edit shipments", saved with
   `continue: false` so the order is not continued on its own, or within the order), then "Continue automation"
   (= resume/retry), "Skip order" or "Stop automation" → back to the automation's progress.
   Errors of the report (autonomous runs) can be fixed the same way: "Fix" locks the order from the exception
   inbox (`POST runs/{id}/errors/{error_id}/fix`, `Queue::lock_exception()`) and opens it at the failed step;
   "Retry" (`/retry-fix`) runs the run's (scope) steps for the order — on success it moves back to the queue, the error
   is marked fixed and the run counts it as succeeded, on a new error the order stays open; "Back to report"
   (`/abandon-fix`) returns it to the exception inbox.
   Retries happen automatically after a fix: saving the shipments (overlay) continues a stopped run / retries the order
   of a report error, the same happens when returning to the app after "Open order" (window focus/visibility). The
   Retry/Continue buttons remain as fallback.
   Run lists (app "Automations", admin overlay) only show active runs and finished runs with unresolved errors
   (`open_errors`: open or skipped). The admin overlay additionally hides finished runs 10 minutes after they finished
   (filter `woocommerce_shiptastic_fulfillment_overlay_finished_ttl`, runs carry `finished_at`).
   **Order details**: the order number in the header opens a full overlay (`views/fulfillment/order.php`, `order.js`)
   with fulfillment relevant data (date, status, shipping/payment method, customer note, link to the order) and the
   editable shipping address incl. email. Read/saved via the WooCommerce core REST API (`wc/v3/orders/{id}`,
   permissions checked by WooCommerce, e.g. `read_private_shop_orders` / `edit_shop_order`). The address form renders
   the edit order page's shipping fields incl. custom fields (`woocommerce_admin_shipping_fields`, with the order as
   context): `stc_address_fields=1` adds `stc_shipping_fields` (key, label, type, options, value, wide, required) to
   the core order response, `stc_shipping` (key => value) on update is saved like the edit order page does (update
   callback, setter or meta). Countries/states are loaded on demand from `wc/v3/data/countries`. Shiptastic syncs editable shipments on order updates;
   `OrderDetails` additionally updates packed (ready for shipping) shipments without label once the shipping address
   changed. While fixing an automation error, saving the address retries automatically.
   REST: `runs` (list/start), `runs/{id}` (incl. errors + `url` of its screen), `/pause|resume|skip|cancel|dismiss`,
   `runs/{id}/errors/{error_id}/retry`; workflows expose `can_automate` (background workflows run as a whole, no step scopes). App: "Automate" panel per workflow
   (autonomous/supervised, hidden while no orders are waiting), "Automations" list, automation screen/report (`?run=ID`).
   New automatable action `mark_ready` ("Mark as ready for shipping": validates items, shipping provider (setting),
   packaging (setting), filter `woocommerce_shiptastic_fulfillment_validate_ready_for_shipping`).
6. ✅ **Workflow editor** (managers, `workflows.js`, `views/fulfillment/workflow-editor.php`): "+ New workflow" and
   "Edit" in the overview (managers see inactive workflows as well), full overlay with General (title, active,
   interactive/background, priority), Steps (actions in order, reorder/remove/add, settings forms from
   `Action::get_settings_schema()`, mode/scope choice for actions supporting several, dependency hints; in background mode,
   steps requiring interaction are hidden, not offered and not saved, switching back restores them), Orders (locations, shipping providers,
   countries, items, weight, backorders) and Automation (schedule, scheduled runs on errors). Saved via the
   existing workflow CRUD (server-side validation messages are shown), delete with confirmation. Choices are loaded on
   demand: `GET workflows/editor` (actions incl. settings, locations, providers, users), countries
   (`wc/v3/data/countries`) from WooCommerce core. The Automation section shows up for background workflows and for
   interactive workflows with at least one step running automatically. No shipping method filter (removed).
   Step order follows the dependencies (`Workflow::sort_steps()` on save, `get_required_actions()` transitive): a
   required action (e.g. create_shipments) is inserted before the first step requiring it and steps cannot be moved
   across their dependencies. Interactive workflows always stop at the first error (`get_on_error()` = stop); "on
   errors" setting for background workflows only. No "run as" choice: scheduled runs execute as the user who saved the workflow last (`run_as` is set on save,
   the schedule pauses in case the user lost `manage_woocommerce`), filter
   `woocommerce_shiptastic_fulfillment_scheduled_run_user`.
   List filters (locations, shipping providers, countries) share one control: "All …" (default, empty filter) or
   "Only selected" (chips + add list); switching back to "All" clears the selection.
   Item count/weight: "from – to" inputs with unit, empty bound = no limit, summary text below; "from" > "to" blocks
   saving (also validated server-side: `workflow_invalid_item_range`/`workflow_invalid_weight_range`).
   Step mode/scope: each action declares its modes (first = default, `Action::get_default_mode()`) and scopes
   (default `order` if supported). Actions supporting both modes get an "Automatically / Interactive" switch per
   step (stored as step setting `mode`, set explicitly when the step is added), actions supporting both scopes a
   "Once per order / Once per shipment" switch (setting `scope`); single values are shown as hints. Background capable
   actions without a dedicated view use the generic review screen (`views/fulfillment/actions/confirm.php`, `process()`
   runs `run()`), e.g. `mark_ready` (modes background, interactive). Automation runs execute background capable steps
   regardless of the step mode.
6b. ✅ **Run exports** (`BulkFulfillments\RunExport`): actions add files to their result
   (`StepResult::add_file( $path, $name )`, `add_file_content( $name, $content )`), automation runs collect them in one
   zip per run (`fulfillment-exports/run-{id}-{random}.zip` within the Shiptastic upload dir, columns `run_export`,
   `run_export_files`), one folder per step (`02-create-label/`), files prefixed with order number + shipment id,
   duplicate names suffixed. Collected during tick and the error fix retries. Download via
   `admin-post.php?action=stc_fulfillment_run_export` (nonce, run user or shop managers) during and after the run:
   automation screen ("Download files (zip)") and the admin overlay (↓). REST run data: `export_files`, `export_url`.
   Exports of runs finished more than 7 days ago are removed daily (Action Scheduler,
   `woocommerce_shiptastic_fulfillment_export_retention_days`). Not yet: web server rules for nginx (the folder relies
   on `.htaccess` + random file names, like labels).
6c. ✅ **Interactive runs + URLs/history**: working through a workflow in the app is a server-side run (source/mode
   `interactive`, `RunProcessor::start_interactive()` returns the user's active run of the workflow instead of a
   second one). All claims of the run share the run's lock token (`Queue::claim( token )`), so the server attributes
   step results to it (`Runner::execute( run )`: export files, processed/succeeded counts; skip → failed, put back →
   total-1) and returns its locked orders for resuming (`GET runs/{id}/items`, e.g. reload without IndexedDB or
   another device). `POST runs/{id}/finish` ends it (orders released, export kept); idle runs (12 h,
   `woocommerce_shiptastic_fulfillment_interactive_run_idle_hours`) are finished by the daily cleanup. Interactive
   runs are excluded from automation lists/checks (`GET runs?source=interactive`), the workflow card shows
   "Continue" for an active one, the run screen offers "Download files (n)", ending a run with files shows its
   report; finished runs with files are listed in the overview for a day.
   URLs (`router.js`, query args of the admin page): `&workflow={id|new}` (editor), `&run={id}` (interactive run or
   automation screen/report), `&run={id}&item={queue id}&step={key}` (current order/step, replaceState only). Screens
   push history entries; back/forward/reload apply the URL (`actions.applyRoute`); browser back from a run ends it
   (like the header back button) without showing the report. Unsaved editor changes keep the editor on back.
6d. ✅ **Automatic steps within interactive work**: interactive workflows cannot be automated (no "Automate" button,
   `Processor::can_automate()` false → `POST runs` 400), only background workflows run as automation runs. Steps set
   to "Automatically" run within the app while working through an order (`Runner::execute()` runs them right after
   the preceding interactive step, `current.automated` lists the ones which succeeded). App: automatic steps are part
   of the step bar ("Automatic" tag, striped segment, not redoable), the action bar names the steps running
   automatically after the current one ("Then automatically: …" / "Running automatically: …" while submitting), a
   notice confirms them afterwards ("Done automatically: …", with the order number in case the order finished). A
   failed automatic step stops the order at that step (error + Retry / Move to exceptions).
   **One by one**: the app runs automatic steps one request each (`auto_steps` arg of `queue/{id}/steps/{key}` and
   `queue/{id}/run`: number of the workflow's automatic steps to run, 0 = return the next one with status
   `automatic`; injected dependencies always run). Progress panel (`actions.runAutomaticSteps()`): steps with their
   descriptions as waiting/running/done, "keep this window open" (longer hint after 5 s per step), check marks for a
   moment once done, then a notice. **Pause at errors**: the error shows inline at the step; Retry (runs the failed
   step again, then the remaining ones), "Open step" (actions supporting the interactive mode: `manual` arg =
   step key → the step is handled interactively with the error as hint, submitting it continues the automation) or
   "Show details" (error view incl. Edit shipments / Open order / Retry), Move to exceptions. Resuming (reload)
   continues pending automatic steps the same way. Notices on the run screen show in the bottom right corner.
6e. ✅ **Free/Pro split**: automations are part of **Shiptastic for WooCommerce Pro**
   (`~/dev/wp/plugins/shiptastic-for-woocommerce-pro`, github vendidero/shiptastic-for-woocommerce-pro, symlinked into
   the test site), similar to Germanized/Germanized Pro. Shiptastic (free) supports interactive workflows only: steps
   of actions supporting user interaction run interactively (no "Automatically" choice), actions without user
   interaction (e.g. creating shipments, injected dependencies) run automatically (one by one with progress panel),
   interactive runs incl. exports/report screen, URLs. Pro adds: background workflows, "Automatically" per step,
   automation runs (`Pro\Automation\Processor`, autonomous/supervised, Action Scheduler), schedules
   (`Pro\Automation\Scheduler`), workflow automation settings as workflow meta (`_on_error`, `_schedule`, `_run_as`,
   `_paused`, `_paused_reason` - same keys as before, `Pro\Automation\Settings`), run errors (table
   `woocommerce_stc_fulfillment_run_errors`, installed by pro), REST routes (`Pro\Automation\RestController` extends
   `FulfillmentController`: workflows/{id}/resume, runs/{id}/pause|resume|skip|cancel, runs/{id}/item,
   runs/{id}/errors/{id}/fix|retry-fix|abandon-fix|retry), the admin overlay and the app module/views/styles.
   **Extension points (free)**:
   - PHP filters: `woocommerce_shiptastic_fulfillment_workflow_modes`, `_workflow_mode_hints`, `_action_modes`
     (`Workflow::get_action_modes()`), `_workflow_step_mode`, `_validate_workflow` (action), `_rest_prepare_workflow`,
     `_rest_before_save_workflow` (action), `_rest_workflow_editor_data`, `_rest_run_modes`, `_rest_start_run`,
     `_rest_prepare_run`, `_rest_get_run` (action), existing `_app_config`, `_app_state`, `_app_register_modules`,
     `_head`.
   - Template actions: `woocommerce_shiptastic_fulfillment_app_workflow_card_actions`, `_workflow_card_after`,
     `_app_screens`, `_report_summary`, `_report_after`, `_step_error_before`, `_step_error_actions`,
     `_step_error_after`, `_workflow_editor_after_steps`, `_workflow_editor_after`.
   - JS: `hooks.js` (filters, sync/async), exported by the app module with `api`: `import { hooks, api } from
     'shiptastic/fulfillment'` (see the list of filters in hooks.js). `automation.js` became `report.js` (run list +
     report screen: `state.report`, screen `report`).
   - REST defaults changed: `POST runs` mode defaults to `interactive`, `GET runs` source defaults to `interactive`.
   Tests: automation tests live in the pro repo (`tests/`, run with Shiptastic's test container and bootstrap).
   **Pro markers** (like Germanized's pro markers): without Pro (`App::has_automation()` = no background mode), the
   workflow editor presents the "Background" mode and "Automatically" per step (actions supporting both modes, REST
   `pro_modes`) disabled with a "pro" badge (`App::get_pro_badge()`, `.stc-pro-badge`) linking to
   `App::get_pro_url()` (filter `woocommerce_shiptastic_fulfillment_pro_url`). Config `pro.automation`/`pro.url`.
7. ✅ **Offline**: queue items carry the view data of their open offline
   capable steps (`views`, `Runner::get_offline_views()`, filter `woocommerce_shiptastic_fulfillment_prefetch_views`;
   steps flag `offline` = interactive and `! Action::requires_network()`). Offline (or when a submit fails with a
   network error) the app stores the submit in the **outbox** (`outbox.js`, IndexedDB `outbox-{userId}`, entry id =
   idempotency key) and continues with the next step locally; orders reaching a step which needs the server
   (automatic steps, network steps, end of the workflow) wait for the connection (`run.waiting`, "n orders waiting for
   the connection") and the next order is shown. Skip/put back are queued as well. Once an order has unsynced entries,
   all its mutations go through the outbox (ordering). Back/redo of steps done offline reopens them locally (the
   step's entry and the following ones are discarded, the form is prefilled). **Replay** (`actions.sync`, on the
   `online` event, every 30 s, after offline submits, before ending a run; one request per entry, without
   `auto_steps` so automatic steps run on the server): completed orders leave the waiting list, orders needing input
   or failing on the server come back to the run ("needs your attention", failed: the item's later entries are
   discarded), lost locks (403/404/409) → **conflicts** list ("could not be saved"), network/5xx errors stop the
   replay (retried), an expired session (401/invalid nonce after the refresh) stops it with a notice (entries kept).
   Versions are not sent on replay (lock token only, own sequential submits would conflict). Header badge "n change(s)
   waiting to sync"/"Syncing…"; the shipments overlay and the order's server actions need a connection; ending a run
   requires an empty outbox. Reloading without connection resumes the locally stored run (`openRun`/`init`
   fallback). **Service worker** (`service-worker.js`, served via `admin-ajax.php?action=stc_fulfillment_sw&v=…`,
   scope = the app page URL so it controls the app only, filter `woocommerce_shiptastic_fulfillment_service_worker`):
   app page network first (offline fallback = last response, login redirects are not cached), same-origin
   scripts/styles/images/fonts network first with 4 s timeout, everything else untouched; old caches removed on
   activation. Service workers need a secure context: not active on http test sites.
   **Shipments offline** (`offline-layout.js`): saving the overlay offline stores the layout in the outbox (`type
   shipments`, same idempotency key in case the connection dropped mid-request) and applies it locally like
   `ShipmentLayout::apply()`: empty shipments removed, locked ones kept, shipment scope steps planned per shipment
   (new shipments use their temporary key `new-n-xxxxxxxx`, e.g. `pack:new-1-ab12cd34`), steps of changed shipments
   reopened, order scope steps of actions with `resets` reopened when the totals changed (steps carry `scope` +
   `resets`). Views of open offline steps are rebuilt by `callbacks.offlineView_{action}` (pick: totals of the
   layout, sorted by the server's `sort` key — layout items carry `location`/`warehouse`/`sort`, see
   `Pick::get_line_locations()`, `StorageLocations::get_sort_key()`; pack: shipment items, packaging, dimensions);
   actions without a builder lose their view (the order waits for the connection at that step). On replay the
   response's `id_map` replaces temporary keys in the item's later outbox entries and its local state
   (`mapShipmentIds`). Rejected entries (4xx other than lost locks, e.g. an invalid layout): the item's entries are
   discarded and it comes back with its server state and the error (`recoverItem`).
   **Ready to finish**: orders waiting for the connection stay listed after syncing in case they need the user
   (a step needing input or a failed step): "n order(s) ready to finish" with "Finish" (becomes the current order;
   automatically when nothing else is left). Orders moved to the waiting list without changes (their next step needs
   the server) return to the run once online (`restoreWaiting`). Orders resumed from the server without their current
   step load it once shown (`ensureCurrent`, offline: derived from the prefetched views).
   **Logout**: the login screen after logging out (`loggedout`, `login_footer`, filter
   `woocommerce_shiptastic_fulfillment_clear_local_data_on_logout`) removes the cached app page (service worker
   caches) and the stored runs (IndexedDB `run-*`); unsynced outboxes are kept (synced when that user opens the app
   again), empty ones removed. Sessions expiring without logging out keep the data.
8. **Batch picking** (storage location sorting ✅ done early, §3.8) (before_queue phase, pick list, sort-to-order, short picks) + **more actions**: labels, documents,
   mark shipped, after-queue (bulk print, manifest).
8b. ✅ **Packaging suggestions (bin packing)** (with M8 or after 6d): when shipments are edited in the app (shipments
   overlay, Pack step), suggest the best fitting packaging per shipment instead of the full packaging list; staff
   confirm or change it. Splitting into several shipments only happens when the shipments are first created (decided).
   **Client-side packing:** shipments are composed client-side (moving items between shipments in the overlay,
   offline-capable), so suggestions are computed in the browser with **binpackingjs** (npm `binpackingjs` ^4.1.0,
   MIT, ESM build vendored in `assets/js/static/binpackingjs/`, added in 877bf3f for the original fulfillment
   implementation; the 3D entry `binpackingjs/3d/index.js` exports `pack3D( { bins, items } )` →
   `{ packedBins, unfitItems }`; bins: name/width/height/depth/maxWeight, items: name/width/height/depth/weight).
   The app imports it relatively (`../static/binpackingjs/3d/index.js`, like the other app modules - no build step).
   The original attempt (877bf3f `admin-fulfillments.js` `getBestPackagingForShipment()`) stayed unfinished
   (console output only) and passed the outer packaging dimensions.
   **Server side stays for server-side creation:** `Order::create_shipments()` (Create shipments step, background
   automation) keeps using `src/Packing` (dvdoug/boxpacker, `Packing\Helper::pack()`, `PackagingSorter`,
   `Shipment::get_default_packaging()`) in case auto packing is enabled - a step setting "Packaging: best fit /
   default packaging / shop setting" may override the global option. Decided: the PHP packer stays the reference
   for server-side creation (no shared ranking definition); the client mirrors its ranking. Client and server should rank alike
   (mirror `PackagingSorter`: shipping class restricted boxes first, smallest volume, smallest empty weight, max
   weight, costs; prefer the default packaging setting on ties like `find_best_match_for_shipment()`). Decided: costs
   stay the last criterion.
   **To do:**
   - Layout data (`ShipmentLayout`): packaging with **inner** dimensions, max content weight, empty weight, costs,
     restrictions (shipping classes, providers) and the default packaging id; items with dimensions + weight
     (+ shipping class). All converted to one unit (packaging units: `wc_stc_get_packaging_dimension_unit()` /
     `wc_stc_get_packaging_weight_unit()`), products without dimensions flagged.
   - `assets/js/fulfillment/packing.js`: wraps `pack3D` - per shipment the fitting packaging (one `pack3D` call per
     candidate box with a single bin: all items packed, no unfit items; naive volume/weight pre-check first), ranked
     like the server; the suggestion = first fitting; fill rate (volume, weight) per option. Split (first creation
     only, see below): distribute the package items into several shipments (pack3D places each bin once - repeat the
     candidate bins as needed, prefer the fewest packages). Memoize per shipment composition (item ids + quantities), cap the item count
     (weight-only heuristic above, like `get_max_qty_to_pack()`).
   - Overlay + Pack step: the suggestion is **applied automatically** (decided): the shipment's packaging is set to the
     best fitting one whenever its items change, marked "Suggested"; the user may select any other option (fitting
     options first with fill indicator, others marked "does not fit"). A manual choice is kept while the items change
     as long as it still fits; otherwise the suggestion is applied again with a hint ("Box S no longer fits, changed
     to Box M").
   - Splitting only on first creation (decided): server-side by the Create shipments step (PHP packer, auto packing);
     client-side in the overlay when the package has no shipments yet and its items are assigned from the pool for
     the first time (packing.js split → the proposed shipments with their packaging, saved via `POST
     queue/{id}/shipments`). Afterwards no automatic splitting and no "Repack": moving items between existing
     shipments or adding a shipment manually only updates the packaging suggestion per shipment. Items fitting no
     packaging: warning ("Item too large for all packaging"), the Pack step requires a manual choice. Products
     without dimensions: weight only + hint.
   - Tests: JS unit tests for `packing.js` (single item fits / doesn't fit, split on first creation only, max weight,
     missing dimensions, ranking incl. default packaging, restrictions and costs last, keeping a fitting manual
     choice, memoization) + PHP test for the layout data (inner dimensions,
     units, item dimensions).
   **Done:**
   - `ShipmentLayout::get_item_packing_data()` / `get_packaging_packing_data()`: items (`packing`: width, length,
     depth, weight, shippingClass) and packaging (`packing`: inner width/length/depth, emptyWeight, maxWeight =
     content, 0 = no limit, costs, shippingClasses) in **mm/g** - the units of the server-side packer
     (`Packing\OrderItem`, `PackagingBox` incl. the inner buffer setting and its filters) instead of the packaging
     units. Filter `woocommerce_shiptastic_fulfillment_packaging_packing_data`. Config `packing`: defaultPackaging,
     maxItems (filter `woocommerce_shiptastic_fulfillment_packing_max_items`, 100), dimensionUnit.
   - `assets/js/fulfillment/packing.js` (pure, no store): `getPackagingOptions()` (availability: provider + shipping
     class restrictions, naive checks, `pack3D` with one bin per candidate, fill rates, tooLarge, missingDimensions),
     `compare()` (PackagingSorter), `proposeShipments()` (one shipment if possible, otherwise as few packages of the
     largest available packaging as possible, then the best packaging per package; split by shipping class in case
     no packaging holds all; too large items as a separate shipment without packaging), `choosePackaging()`
     (keep a fitting manual choice), memoized by composition incl. packing data. Above maxItems units: volume +
     weight only.
   - Default packaging: preferred whenever it fits (like `find_best_match_for_shipment()`, which prefers it among all
     fitting packaging - not only on ties).
   - Costs: depend on the shipping method rules (cart), not available in the app (0), the criterion is supported.
   - Overlay (`shipments.js`): suggestion applied whenever items/provider change ("Suggested" tag), stored packaging
     treated as a manual choice (kept while it fits), replaced manual choice → hint; select lists fitting options
     first with fill rate, others "(does not fit)"; warnings for too large items / items without dimensions.
     No shipments yet → shipments proposed on open (hint, dirty), no automatic splitting afterwards.
   - Pack step (`actions/pack.js`, view `packagingId`/`provider`): packaging select; stored packaging kept while it
     fits, otherwise the suggestion (submitted as `packaging_id` with the step); no packaging fits → validation
     requires a manual choice.
   - Tests: `npm run test:js` (node --test, `tests/js/packing.test.mjs`, 18 tests), PHP
     `tests/unit-tests/fulfillment-packing.php`.
   - Lists rendered client-side only (wp-each within selects) must be empty while the page hydrates, otherwise
     Preact reports "Expected a DOM node of type option but found template" (the Pack select's options are empty
     unless a pack step is shown).
8c. **Run phases `before_queue` / `after_queue`** (prerequisite for the Shiptastic Pro plugin
   `plugins/fulfillment-documents`: StoreaBill based picking lists + shipment lists per provider, requested
   2026-11-18). Today only the `item` phase is executed (`Action::get_phases()` is declared but unused). Needed:
   - **Execution points**:
     - `before_queue`: once per run over its batch, right after claiming and before any `item` step of the batch.
       Interactive runs: after the claim of the run (`RunProcessor::start_interactive()` / follow-up claims sharing the
       run token = new batch → runs again for the newly claimed items only). Background runs (Pro
       `Automation\Processor`): per claimed chunk/batch, before the first item tick of that batch.
     - `after_queue`: once per run after its items were processed, before the run is marked finished. Interactive:
       on `POST runs/{id}/finish` (and when the queue ran empty), background: when `RunProcessor::tick()` finds no
       more items. Stopped/cancelled runs: skipped (setting per step? see open questions).
   - **Run context**: steps of these phases get a run scoped context (e.g. `RunContext` or `StepContext` with
     `get_run()`, `get_queue_items()`, `get_shipments( $args )` (package shipments of all items of the run/batch,
     filterable by provider, status, `has_label`), `get_succeeded_queue_items()` (items whose item steps all
     succeeded - after_queue), `get_order_shipments()`, `source` and `token`). Actions keep the existing API:
     `run( $context )` (background), `get_view_data()` / `process()` (interactive).
   - **State / idempotency**: the step log is per queue item today. Run level steps need their own log, e.g. rows in
     `stc_fulfillment_steps` with `step_queue_id = 0` + a new `step_run_id` column (or run meta), so that ticks,
     retries and resumed interactive runs never execute a run level step twice for the same batch.
   - **Results**: `StepResult` incl. files → `RunExport` (one folder per step, file names without the order prefix),
     REST run data/report list run level steps + their results (done/skipped/error, files).
   - **Errors**: run errors without a queue item (`item_id = 0`, step key) shown within the report.
     Autonomous: recorded, the run continues (after_queue: the run finishes with errors). Supervised: the run stops,
     "Retry" re-runs the run level step, "Skip" continues without it.
   - **Workflow editor / validation**: actions supporting multiple phases (e.g. `pick`: `item` or `before_queue`)
     let editors choose the phase per step (`steps[].phase`, default = first supported phase); validation keeps
     `before_queue` steps first and `after_queue` steps last, `requires` of run level steps are satisfied by the item
     steps of the same workflow (e.g. a shipment list requires `create_label` to have run per item).
   - **Modes**: background capable run level steps run automatically in both run types (interactive runs: progress
     panel like 6d, `after_queue` while finishing - files are offered within the report). Interactive run level
     views (batch pick list, M8) are optional for the documents plugin.
   - **Extension points**: hooks before/after run level steps (`woocommerce_shiptastic_fulfillment_before_run_step`,
     `_after_run_step`), REST/app filters to render run level step results within the report.
   **Usage by `fulfillment-documents` (Pro)**, waiting for the above:
   - `create_picking_list` (phase `before_queue`, background + interactive confirm, repeatable, setting: include
     the per-shipment breakdown): one numbered StoreaBill document for the batch's shipments - aggregated pick lines
     (product / inner box, location, quantity, orders) sorted along the walking path + per-shipment section; PDF added
     to the run export (later: cloud printing).
   - `create_shipment_list` (phase `after_queue`, background, requires `create_label`, settings: providers,
     only shipments with tracking number): one numbered StoreaBill document per shipping provider of the run's
     shipments (shipment, order, recipient, packages, weight, dimensions, tracking number, signature fields);
     PDFs added to the run export.
   - Both are also available as bulk actions within the shipments table (no run needed).
   Open questions: after_queue for stopped/cancelled runs (all processed items vs. none)? Batch definition for
   background runs (whole run vs. claim chunk) - one picking list per chunk could be split across documents.
9. **Polish**: scanner support, gestures, a11y, performance budget check, docs for third-party actions.

---

## 5. Open questions

- Packaging suggestions (8b): remember manual overrides per product combination?
