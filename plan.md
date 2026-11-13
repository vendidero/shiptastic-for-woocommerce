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
- Filters: warehouse(s) (multi-select; most workflows will be per warehouse), shipping provider, shipping method, country/zone, item count/weight ranges, order date,
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
- `POST nonce` → refresh REST nonce after long offline periods

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
3. **Actions API v2 + runner**: registry, phases/scopes/modes, step log, Action Scheduler background runner,
   exception inbox data. Port `create_shipments`, `pick`, `pack`. Fix the bugs listed in §2.
4. **REST API** (§3.9) incl. batch + idempotency; drop admin-ajax handler.
5. **SPA refactor**: shell + action templates, JSON-driven client-side navigation, IndexedDB store; queue overview +
   run screen.
6. **Workflow editor** (filters + steps + settings forms).
7. **Offline**: outbox replay, prefetch, conflicts, service worker.
8. **Batch picking** (storage location sorting ✅ done early, §3.8) (before_queue phase, pick list, sort-to-order, short picks) + **more actions**: labels, documents,
   mark shipped, after-queue (bulk print, manifest).
9. **Polish**: scanner support, gestures, a11y, performance budget check, docs for third-party actions.

---

## 5. Open questions

- None at the moment.
