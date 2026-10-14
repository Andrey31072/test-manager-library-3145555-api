# Manager Library API

Laravel owns the Manager Library business rules, HTTP contract, authentication, cover storage, and the complete `library` PostgreSQL schema.

## Boundaries

- Exactly six product tables; no audit, report, payment, reservation, or framework entities in the product schema.
- Routes are exposed below `/api/v1` and use the unified JSON error contract.
- Database migrations and fixed categories belong here, never in infrastructure.
- User-facing errors are Spanish; implementation documentation and code identifiers are English.

See `IMPLEMENTATION.md` and the docs repository before changing behavior.
