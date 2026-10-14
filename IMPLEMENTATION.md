# SDD Implementation Notes

This repository adapts the reference spec to Laravel. The English interpretation lives in
`../test-manager-library-3145555-docs/react-laravel-sdd/` and was committed before code.

Implemented baseline:

- Laravel 11 skeleton for `/api/v1`.
- PostgreSQL schema `library` with the six specified tables only.
- Fixed category seed in the Laravel migration, not in infra.
- Unified error body.
- Bearer JWT authentication with `sub`, `role`, and 8-hour expiration.
- Initial admin bootstrap from `ADMIN_USERNAME` and `ADMIN_PASSWORD`.
- Core catalog, members, loans, returns, overdue list, and most-borrowed report endpoints.

Local host validation is limited because this workstation does not have `php` or `composer`.
Use Docker or the infra repository to install dependencies and run migrations.
