# Shop Module — Developer Reference

Online sales channel for Laraplate. Orchestrates CMS content, ERP items and SAO
support into a storefront.

## Status

Scaffold only. The module ships providers, a resource controller stub, routes
and config, but no domain models, migrations or services yet. It is not enabled
in `modules_statuses.json`.

## Boundaries

- **Depends on Core, CMS, ERP and SAO** (one-way: none of them knows Shop).
- Every table will be prefixed `shop_`.
- Catalog, stock and orders belong to ERP; content belongs to CMS; support
  belongs to SAO. Shop composes them and must not duplicate their logic.

## Routes

| Surface | Prefix | Middleware | Name |
|---------|--------|------------|------|
| Web | `shops` | `auth`, `verified` | `shop.*` |
| API | `v1/shops` | `auth:sanctum` | `shop.*` |
