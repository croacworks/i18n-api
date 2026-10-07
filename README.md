# i18n Central Translation API

A microservice acting as a centralized source of truth for i18n translation keys and values across multiple applications.

## Table of Contents
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Running with Docker](#running-with-docker)
- [API Endpoints](#api-endpoints)
- [CSV Seed & Export Workflow](#csv-seed--export-workflow)
- [Token Management](#token-management)
- [Project Configuration](#project-configuration)

## Architecture
- **Language**: PHP 8.4 (Vanilla)
- **Database**: SQLite (`database.sqlite`)
- **Web Server**: Built-in PHP Development Server (via Docker)
- **Image**: `php:8.4-cli-alpine`

## Requirements
- Docker and Docker Compose

## Running with Docker
1. Navigate to the project directory:
   ```bash
   cd /home/honaldcs/Development/PHP/Vanilla/i18n-api
   ```
2. Start the service:
   ```bash
   docker compose up -d --build
   ```
   The API will be available at `http://localhost:8008`.

## API Endpoints

All requests (except `/api/login` and `/api/auth/login`) require the header `Authorization: Bearer <TOKEN>`.
There are two user roles:
- `admin` can create, update, delete, auto-translate, pull and export translations.
- `user` can only read translations through read-only endpoints.

### `POST /api/login`
Alias: `POST /api/auth/login`
Authenticates a user and returns an access token with the user's role.

**Body (JSON):**
```json
{
  "username": "admin",
  "password": "your_password"
}
```

**Response:**
```json
{ "success": true, "token": "<TOKEN>", "role": "admin", "username": "admin" }
```

---

### `GET /api/pull`
Returns all translation keys with their translations grouped by source message.

Allowed roles: `admin`, `user`.

**Response:**
```json
[
  {
    "category": "app",
    "message": "Dashboard",
    "translations": {
      "pt-BR": "Painel",
      "es": "Panel"
    }
  }
]
```

---

### `POST /api/push`
Creates or updates one or more translation keys. A batch is validated completely
before writing and is committed in a single SQLite transaction. If any item
conflicts or fails, the complete batch is rolled back.

Allowed roles: `admin`.

**Single-item body (legacy-compatible JSON):**
```json
{
  "category": "app",
  "message": "Dashboard",
  "translations": {
    "pt-BR": "Painel",
    "es": "Panel"
  },
  "overwrite": true
}
```

**Single-item response:**
```json
{ "success": true, "id": 42 }
```

**Batch body:**
```json
[
  {
    "category": "app",
    "message": "Dashboard",
    "translations": {"pt-BR": "Painel"},
    "overwrite": true
  },
  {
    "category": "app",
    "message": "Settings",
    "translations": {"pt-BR": "Configurações"},
    "overwrite": true
  }
]
```

The equivalent envelope `{ "items": [...] }` is also accepted.

**Batch response:**
```json
{
  "success": true,
  "processed": 2,
  "created": 2,
  "updated": 0,
  "translations": 2,
  "ids": [42, 43]
}
```

A request may contain at most 1,000 items and 5 MB. Language identifiers are
normalized from underscore to dash. `overwrite: false` returns HTTP 409 when a
translation for the same source message and language already exists.

---

### `GET /api/export`
Exports all translation keys and their values as a downloadable CSV file, ready to be used as a seed file.

Allowed roles: `admin`, `user`.

**Response:** `text/csv` — file `i18n_messages_app.csv`

**CSV format:**
```
category,message,translation_pt-BR,translation_es
app,Dashboard,Painel,Panel
```

> Language columns follow the pattern `translation_<language-code>` using the BCP-47 format with dash (e.g. `pt-BR`, not `pt_BR`).

### `GET /api/stats`
Returns the catalog totals used by the CoreUI dashboard. Available to `admin` and `user`.

### `POST /api/import`
Imports a CSV using `multipart/form-data` with the field `file`. Administrators can first send
`preview=1` to validate the file and inspect new keys, existing keys, conflicts and invalid rows.
The real import is transactional. `overwrite=1` is required to replace existing translations.
Files are limited to 10 MB.

### `GET /api/sanitize/preview` and `POST /api/sanitize`
Administrators can inspect and apply a database cleanup. The sanitizer trims source fields and
translations, converts language underscores to dashes, removes empty/orphan records, merges
normalized duplicate keys and keeps the first translation when a conflict is found. Applying the
cleanup creates a timestamped copy in `database.backups/` and rebuilds the translation tables in a
single transaction. Send `{ "apply": true }` to the `POST` endpoint to commit the operation.

### User management

Administrators can use `GET /api/users`, `POST /api/users`, `PUT /api/users/<id>` and
`DELETE /api/users/<id>`. New passwords must contain at least eight characters. The API prevents
removing the last administrator and prevents an administrator from removing their own access.

### Backup and restore

The administrator panel provides buttons to download and restore a complete backup. The archive
contains `database.sqlite`, `.env` and a manifest. The restore operation validates the SQLite
integrity and creates an emergency backup before replacing the current files.
The corresponding admin-only endpoints are `GET /api/backup`, `GET /api/backups` and
`POST /api/restore`.

The same workflow is available from the server shell:

```bash
# Create a portable archive in ./backups/
docker compose exec api php backup.php --create

# List local archives
docker compose exec api php backup.php --list

# Restore an archive copied into the new checkout
docker compose exec api php backup.php --restore /app/backups/i18n-api-YYYYMMDD-HHMMSS-xxxxxx.tar.gz
```

Copy the generated archive to storage outside the application directory before removing the old
checkout. After cloning the new Git repository, place the archive in its `backups/` directory and
restore it before normal use. The container entrypoint seeds only when `database.sqlite` does not
exist, so a restored database is not overwritten by the CSV shipped in the new repository.

### Application updater

The administrator interface has an **Atualizações** screen. Configure an HTTPS repository URL,
branch and a GitHub fine-grained access token, then use **Verificar atualizações** or **Atualizar
aplicação**. The token should have access to the target repository and `Contents: Read-only`
permission, which is enough to fetch and update the checkout.

The updater stores the token encrypted in `app_settings`, never returns it to the browser, refuses
to update a dirty working tree and uses `git pull --ff-only`; it never resets or discards local
changes. Git is installed in the API image by [Dockerfile](Dockerfile).

For stable encryption across API-token rotations, optionally define `I18N_UPDATER_ENCRYPTION_KEY`
in `.env`. If omitted, the API token is used as the encryption secret, so changing the API token
requires saving the updater configuration again.

---

## CSV Seed & Export Workflow

The `seed.php` script populates the database from CSV files in the project root. This is the recommended way to bulk-import or update translations.

### CSV format

The file must have the following columns:
- `category` — translation category (e.g. `app`)
- `message` — source string in English
- `translation_<lang>` — one column per language using BCP-47 dash format (e.g. `translation_pt-BR`, `translation_es`)

Example (`i18n_messages_app.csv`):
```csv
category,message,translation_pt-BR,translation_es
app,Auth,Autenticação,Autenticación
app,Description,Descrição,Descripción
```

### Exporting the current state

To update the CSV with all current translations from the database:

```bash
curl -H "Authorization: Bearer <TOKEN>" http://localhost:8008/api/export -o i18n_messages_app.csv
```

Or via the Docker container (from within the same network):

```bash
curl -H "Authorization: Bearer <TOKEN>" http://i18n-api-server/api/export -o i18n_messages_app.csv
```

### Running the seed

```bash
docker compose exec api php seed.php
```

### Recommended workflow

1. **Export** the current CSV: `GET /api/export`
2. **Edit** the CSV locally — add missing translations or new keys
3. **Re-seed** the database: `php seed.php`

## Tests

Run the HTTP integration test against an isolated API instance configured with a
disposable SQLite database:

```bash
I18N_TEST_BASE_URL=http://127.0.0.1:8008 \
I18N_TEST_TOKEN=test-token \
php tests/push_batch_test.php
```

The test writes translation fixtures, so it must not point to the production API.

---

## User Management

The `user.php` script manages users who can authenticate via `/api/login`.

```bash
# Create a user
docker compose exec api php user.php --create admin admin123 admin
docker compose exec api php user.php --create viewer viewer123 user

# List all users
docker compose exec api php user.php --list

# Change a user's role
docker compose exec api php user.php --role viewer admin

# Delete a user
docker compose exec api php user.php --delete admin
```

## Token Management

The `token.php` script manages the master API token stored in `.env`.

User login tokens expire after 24 hours by default. Set `I18N_TOKEN_TTL` in `.env` to customize
the lifetime in seconds; accepted values are clamped between five minutes and thirty days.

```bash
# Show current token
docker compose exec api php token.php --show

# Generate a new token
docker compose exec api php token.php --generate
```

## Project Configuration

The API token is defined in the `.env` file as `I18N_API_TOKEN`. Users are stored in `database.sqlite`.
The management interface is available at `/` or `/login.php` and uses the local CoreUI assets in
`assets/coreui/`; it does not depend on a CDN at runtime. Sensitive files such as `.env`, the
SQLite database and PHP source files are not served directly by the built-in server. The official
CoreUI 5.9.0 package, including its source and distribution files, is kept in
`assets/coreui/package/`. The interface supports light, dark and system themes; the preference is
stored in the browser.

## Add to proxy
```bash
./add_app.sh /home/honaldcs/Development/PHP/Vanilla/i18n-api i18n.essential.com
```
