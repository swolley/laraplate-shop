# Shop Module: Developer Reference

Online sales channel for Laraplate. Orchestrates CMS content, ERP items and SAO
support into a storefront.

## Status

Catalog foundation only. The module ships the product catalog core (product,
variant, variant composition) and the seed of the product content entity. It has
no stock, cart, checkout or payment behaviour, and no write, API or Filament
surface yet (see «Not in this module yet»). It is enabled in
`modules_statuses.json`. `routes/web.php` and `routes/api.php` still mount the
scaffold `ShopController` stub.

## Boundaries

- **Depends on Core, CMS, ERP and SAO** (one-way: none of them knows Shop).
- Every table is prefixed `shop_` and centralised in `ShopTables`
  (`app/Enums/ShopTables.php`).
- Content belongs to CMS, items, stock and orders belong to ERP, support belongs
  to SAO. Shop composes them and must not duplicate their logic.
- CMS never references a Shop class. The only link is the content-extension seam,
  where Shop registers the opaque alias `shop.product`.

## Catalog model

```
CMS Content  <--1:1-->  Product  --1:n-->  ProductVariant  --1:n-->  VariantItem  -->  ERP Item
 (the body)          shop_products       shop_product_variants      shop_variant_items   erp_items
```

- The **body** of a product (title, slug, description, validity, translations,
  dynamic fields) is a CMS `Content`. Shop does not store it.
- The **`Product`** is the commercial anchor that extends that content.
- A **`ProductVariant`** is the sellable unit. A simple product has one variant.
- A **`VariantItem`** composes a variant from ERP `Item`s: one row is a simple
  product, several rows are a virtual bundle, no row is an item-less variant.
- `ProductKind` (`app/Enums/ProductKind.php`) says what the product is:
  `physical` (ships, stocked, variants resolve to ERP items), `digital`
  (delivered as a download or entitlement, no ERP item) and `display_only`
  (shown in the catalog, not sold through the shop). The kind, not the row
  count, tells an item-less variant apart.

## Product content entity

Shop owns the dynamic-content entity its products are created against (decision
E20). It is deliberately **not** a CMS `EntityType` case.

- `app/Casts/EntityType.php`: the Shop entity types, today only `products`.
- `app/Models/Entity.php`, `Preset.php`, `Pivot/Presettable.php`: Shop
  specializations of Core's abstract `Entity`, `Preset` and `Presettable`. Core
  resolves them by module namespace through `DynamicContentsService`
  (`fetchAvailableEntities(EntityType::Products)` returns the Shop `Entity`, and
  likewise for presets and presettables). `Entity` is scoped to the Shop entity
  types and `Preset` to presets of such entities, so the Shop and CMS entity
  layers do not see each other's rows. `Preset::getRelatedModelClass()` is the CMS
  `Content`: the dynamic content a Shop preset describes is a CMS content.
- `ShopDatabaseSeeder` (`database/seeders`) creates the `product` entity
  (`is_default`) and its `standard` preset, attaches the body field (`content`,
  type `Editor`, translatable, required on the preset) and freezes the preset
  snapshot with `PresetVersioningService`. It is idempotent on the entity **type**:
  an operator renaming or deactivating the entity does not get a second entity and
  preset on the next seed. The `content` field is shared with CMS by name: the
  seeder reuses an existing one (restoring it when soft-deleted) and creates it
  only when none exists, so the order in which Shop and CMS are seeded does not
  matter.
- Storefront classification will be a CMS `Category` and per-category presets
  (attribute sets) come later. Today the product has this single default preset.

To create a product content by hand, create a CMS `Content` with
`presettable_id` set to the Shop product presettable (this also syncs
`entity_id`) and let the product persist it (see «Creating a product»).

## Product

`app/Models/Product.php`, table `shop_products`. Extends Core's `Model` and
implements CMS `ExtendsContent` with `ExtendsContentTrait`. It is the first
production consumer of the content-extension seam (`Modules/CMS/docs/CONTENT_EXTENSION.md`).

Columns:

| Column | Notes |
|--------|-------|
| `company_id` | Tenant, through ERP `BelongsToCompany` (E23): company global scope, `creating` auto-fill from the current company, `company_id` added to `$fillable` |
| `content_id` | NOT NULL and UNIQUE (E16): one extender per content, a product always has a body. FK to CMS `contents`, `cascadeOnDelete` |
| `kind` | `ProductKind`, default `physical` |
| `is_published_in_shop` | boolean, default false |
| `featured` | boolean, default false |
| `release_date` | nullable date |
| `metadata` | nullable json, cast to array |

Index `shop_products_company_published_IDX` on `(company_id, is_published_in_shop)`.
Validation lives in `getRules()` (`kind` required on create and bounded to
`ProductKind`, `content_id` must exist in `contents`).

### Seam registration

`ShopServiceProvider::register()` registers `Product::CONTENT_ALIAS`
(`shop.product`) in the CMS `ContentExtenderRegistry`, both when the registry
resolves and, if it already resolved earlier, immediately, so registration does
not depend on provider order. The trait stamps `contents.extended_type` with that
alias on the first save.

Consequences of the seam to keep in mind:

- An extended content is **hidden** from a default `Content` query
  (`HidesExtendedContent` scope) and from generic CMS search. Read products
  through `Product`, or use `Content::withExtended()` to reach the contents.
- `Product::query()` always eager-loads `content` (`$with`). Upcasting a page of
  extended contents with the CMS `ContentExtensionResolver` costs a constant
  number of queries (one for the page, one batched query for the extenders), not
  one per row.
- `$product->content` always resolves, even though the hide scope is on.

### Creating a product

The pair is created through the extender, in one transaction, via the seam's
`save()` bridge:

```php
$content = new Content();
$content->presettable_id = $presettable->id; // Shop product presettable
$content->valid_from = now();

$product = new Product(['kind' => ProductKind::Physical]);
$product->setTempContent($content);
$product->save(); // saves the content (stamps extended_type), links it, saves the product
```

`company_id` is NOT NULL: it is auto-filled from the current ERP company on
`creating`, so with no active company it has to be passed explicitly (it is
fillable).

`ProductFactory` does exactly this against the seeded Shop product entity (call
`ShopDatabaseSeeder` first) and adds a default-locale translation to the content.

### Transparent content merge (E2a)

Content-owned attributes read and write as if they were native on the product,
the way CMS `Contributor` surfaces its `User`.

- `getAttribute()` / `setAttribute()` route any key outside the `OWN_COLUMNS`
  whitelist (the product's own columns, timestamps, `deleted_at`, `is_deleted`) to
  the merged `Content`. A key that is an accessor, a mutator, a relation or a
  method of the product stays native.
- The merged content is the loaded `content` relation, or the content staged by
  `setTempContent()` for a first save. The merge never triggers a lazy load, so
  attribute access on an unretrieved product stays query-free.
- A write through the product root raises the `contentDirtyViaMerge` flag. The
  `saving` hook persists the merged content when it is dirty **or** the flag is
  set. The flag is what carries **translatable** fields (`title`, `slug`,
  translatable dynamic fields): they buffer in the content's pending translations
  and never make the content dirty. A product save with no content write does not
  save the content, so it never spawns a spurious content version or approval.
- The first save is the seam trait's job (it stages and persists the temp
  content); the hook only clears the flag in that case.

### Lifecycle

Product and content are one unit with a symmetric lifecycle (E17):

- Product soft delete, force delete and restore cascade to the content
  (`ExtendsContentTrait`; restore goes through the content's `reviveInMemory()`
  and `save()`).
- Deleting the content cascades to the product. A shared re-entrancy guard
  (`ContentExtensionCascade`) stops the two directions from looping.
- A hard-deleted content cannot leave a bodiless product: the `content_id` FK
  cascades on delete.
- Shop code does not cascade a product soft delete to its variants. Variants of a
  trashed product are rejected on create (see below); a hard delete cascades at
  the FK level.

### Search

The seam merges `Product::searchableExtension()` into the content's search
document under the nested `extension` object (`kind`, `is_published_in_shop`,
`featured`), and `searchableExtensionMapping()` contributes the typed mapping
(keyword and boolean filters). The trait re-sends the content to the index after
every product save, so the document never lags behind the extension.

## ProductVariant

`app/Models/ProductVariant.php`, table `shop_product_variants`. The sellable unit
under a product.

| Column | Notes |
|--------|-------|
| `product_id` | FK to `shop_products`, `cascadeOnDelete`, indexed |
| `is_default` | boolean, default false |
| `attributes` | nullable json, cast to array: the purchase-selection axis (size, colour, ...). Label and projection only, never a source of truth and not relational (E22) |

- **No `company_id`**: the tenant is derived from the product (E23).
- **One default per product** is enforced at the model level, because a partial
  unique index is not portable across the supported drivers. A `saved` hook demotes
  every other variant of the product (trashed ones included, so a restore cannot
  bring back a second default) when the saved variant is the default **and** its
  `is_default` or `product_id` is dirty. It runs on `saved`, not `saving`, because
  Core validates in `creating`/`updating`: a rejected write must not wipe the
  current default. A plain re-save of a stale in-memory default does not demote
  the real default. The demotion is a bulk update, so it fires no model events.
- A product can have **zero defaults** until one is set. A variant starts as
  non-default.
- A variant never changes parent: `product_id` is prohibited on update when it
  is dirty. A variant cannot be created under a trashed (or missing) product.
- `Product::variants()` is the `hasMany`; `Product::defaultVariant()` is the
  `hasOne` filtered on `is_default`.

## VariantItem

`app/Models/VariantItem.php`, table `shop_variant_items`. Composes a variant from
ERP `Item`s (E7b).

| Column | Notes |
|--------|-------|
| `variant_id` | FK to `shop_product_variants`, `cascadeOnDelete`, indexed |
| `item_id` | FK to `erp_items`, `restrictOnDelete`, indexed |
| `quantity` | `decimal(15,4)`, cast `decimal:4`; validated as at most four decimals, minimum `0.0001`, maximum `99999999999.9999` |
| `role` | closed set `main` / `component` (E31): `VariantItem::ROLE_MAIN`, `ROLE_COMPONENT`. A validated string, not an enum |

- `main` is the sole sellable item of a simple product (or the anchor of a
  range), `component` is a constituent of a bundle. Storage accepts a bundle of
  `component` rows and a variant with no row at all. That a simple product has
  exactly one `main` row, and that the item and the product belong to the same
  company, are checkout concerns and are not enforced here.
- No `company_id`: the tenant is derived through the variant and its product.
- **Uniqueness** of `(variant_id, item_id)` among **live** rows is enforced by
  validation (a unique rule scoped to the variant and `deleted_at IS NULL`), not
  by a database index, so a soft-deleted row does not block re-adding the item.
  Swapping the item of a row re-applies the rule only when the item changes.
- A row never moves to another variant. `item_id` must exist and be live (not
  soft-deleted) when a row is created or its item is swapped. `variant_id` must
  exist and not be trashed.
- `restrictOnDelete` on `item_id` guards **hard** deletes only: force-deleting an
  ERP item that still composes a variant fails with a query exception, so a bundle
  component cannot silently vanish. A soft-deleted item stays referenced on
  purpose. A hard-deleted variant removes its composition rows by FK cascade.

### Soft-delete contract for the composition

- `ProductVariant::items()` returns the **full** composition (the
  `VariantItem` rows, soft-deleted rows excluded by the model's soft delete).
- `VariantItem::item()` uses `withTrashed()`: a row always resolves its ERP item,
  even a soft-deleted (discontinued) one, so reading the composition never hits a
  null. Check `$row->item->trashed()` for the item lifecycle. Use this relation
  for writes too.
- `ProductVariant::erpItems()` is the **live-only, purchasable** view: a
  `belongsToMany` through `shop_variant_items` (pivot `quantity`, `role`,
  timestamps) that leaves out soft-deleted items and soft-deleted composition
  rows.

## Not in this module yet

- Stock and availability. They will consume the ERP stock reservation, not
  duplicate it.
- Cart, checkout and payments.
- Digital fulfilment (download grants for `digital` products).
- Reviews, order and delivery read models, customer support through SAO.
- Storefront classification: CMS categories, facets and per-category presets.
- Any write, API or Filament surface: no Form Requests, API resources, policies
  or domain actions for the catalog, and no seeded permissions. The only HTTP
  surface is the scaffold stub below.

## Developer caveats

- **Content keys are not fillable.** A product's content-owned fields route only
  through direct property assignment (`$product->title = '...'`) and `forceFill`.
  They do not route through `create([...])` / `update([...])` arrays, because those
  keys are not in `$fillable` and `fill()` does not know about the merge. A future
  write surface must wire content-key routing in `fill()` first.
- **Entity and preset are not resolved from the content.** A CMS `Content`
  extended by a `Product` resolves its presettable, entity and preset through CMS
  classes, not Shop's. A product-specific accessor for the Shop entity and preset
  comes with the storefront plan. Until then reach them through
  `Shop\Models\Entity` / `Preset` or `DynamicContentsService`.
- **Preset-version migrations must see extended contents.** The seam's global
  scope hides extended contents, so any preset-version migration that walks
  product contents has to go through `Content::withExtended()`.
- **Create a product through the product.** Saving a bare `Content` does not make
  it a product, and `extended_type` is guarded: it is set only by the seam's
  create path.

## Routes

| Surface | Prefix | Middleware | Name |
|---------|--------|------------|------|
| Web | `shops` | `auth`, `verified` | `shop.*` |
| API | `v1/shops` | `auth:sanctum` | `shop.*` |

Both are the scaffold `ShopController` resource stub (`app/Http/Controllers`),
not a catalog surface.

## Configuration

`config/config.php` holds only the module name. The module defines no environment
variables and no `shop.*` configuration keys yet.

## Locked decisions

See `docs/superpowers/specs/2026-09-17-shop-module-design.md` (E-series) for the
module design. The ones this foundation implements: E2a (transparent content
merge), E4 (content-extension seam), E7b (variant to item pivot), E16 and E17
(mandatory content, symmetric lifecycle), E20 (Shop-owned product entity), E22
(attributes are labels), E23 (`company_id` on the product, derived below it), E31
(`role` value set). The delivery record is
`docs/superpowers/plans/2026-10-06-shop-catalog-foundation.md`.
