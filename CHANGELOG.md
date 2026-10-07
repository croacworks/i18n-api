# Changelog

## CoreUI manager, import and database maintenance — 2026-10-07

- Replaced the custom manager screen with a local CoreUI Bootstrap interface.
- Added dashboard statistics, modal-based translation and user forms, filters, pagination and local CoreUI assets.
- Added CSV import with validation preview, transactional commit, conflict detection and optional overwrite.
- Added database sanitation preview/apply flow with normalization, duplicate/orphan cleanup and automatic backups.
- Added administrator user CRUD endpoints and UI, including password validation and last-admin protection.
- Enabled SQLite foreign keys, blocked direct access to sensitive files in the built-in server and added expiring user tokens.
- Added complete `.tar.gz` backup/restore routines for the CLI and administrator panel.
- Existing databases are no longer reseeded automatically on every container restart.
- Added CoreUI light, dark and system theme selection with browser persistence.
- Added an administrator Git updater with encrypted deploy-key storage, fast-forward-only updates and dirty-tree protection.

## Batch translation push — 2026-08-25

- `POST /api/push` now accepts a JSON array or an `{ "items": [...] }` envelope
  with up to 1,000 translation entries and a 5 MB request limit.
- Every batch is validated before writing and committed in one SQLite
  transaction; validation errors and translation conflicts roll back the whole
  request.
- Prepared statements are reused across the batch and the response reports the
  processed, created, updated and translated totals.
- The original single-item request and `{ "success": true, "id": ... }`
  response remain compatible.
- Added an isolated HTTP integration test covering authentication, both batch
  formats, legacy compatibility, validation and transactional rollback.
