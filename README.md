# AskMyDocs — Backend

Laravel REST API for AskMyDocs: upload PDF documents and ask questions about them, with answers grounded in the documents and cited by page (Retrieval-Augmented Generation).

The UI is a separate application in its own repository: **askmydocs-frontend** (Vue 3 + Vuetify). The API contract shared by both is documented in [`docs/api.md`](docs/api.md).

> Status: Phase 2 (document upload and text extraction) in progress.

## Stack

| Concern | Choice |
|---|---|
| Framework | Laravel 13, PHP 8.4 |
| Database | PostgreSQL 18 + pgvector (database-only Docker container during development) |
| Queue | Laravel `database` driver, workers on the `answers` and `documents` queues |
| Tests | Pest, against a real PostgreSQL test database |
| Formatting | Laravel Pint |

## Local development (Windows)

Laravel runs **natively**. Only PostgreSQL runs in Docker, because pgvector is not readily available for native PostgreSQL on Windows. The full application is containerized later (Phase 9).

### Prerequisites

- PHP 8.4 with the `pdo_pgsql`, `pgsql`, `mbstring`, `fileinfo` and `curl` extensions
- Composer 2
- Docker Desktop (running)
- Poppler (`pdftotext`, `pdfinfo`) for PDF text extraction — see below
- `php.ini`: `upload_max_filesize = 21M` and `post_max_size = 25M` (PHP's defaults of 2M/8M reject 20 MB PDFs before Laravel sees them)

### Poppler (PDF text extraction)

`pdftotext` extracts text page by page (pages are separated by form feeds, which is how answers can cite page numbers); `pdfinfo` reads the page count during upload validation.

On Windows, no PATH change is needed:

1. Download the latest `Release-*.zip` from <https://github.com/oschwartz10612/poppler-windows/releases>.
2. Extract it, e.g. to `C:\tools\poppler-26.09.0`.
3. Set absolute paths in `.env` — **use forward slashes** (inside double quotes a backslash is an escape character):

   ```dotenv
   PDFTOTEXT_BINARY=C:/tools/poppler-26.09.0/Library/bin/pdftotext.exe
   PDFINFO_BINARY=C:/tools/poppler-26.09.0/Library/bin/pdfinfo.exe
   ```

4. Check: `& C:\tools\poppler-26.09.0\Library\bin\pdftotext.exe -v`

On Linux/Docker, install `poppler-utils` and keep the defaults (`pdftotext` / `pdfinfo` from PATH). Tests that need the real binaries are skipped automatically when Poppler is not available.

### Ports

| Service | Port | Configured in |
|---|---|---|
| Laravel API | `8000` | `php artisan serve --port=8000` |
| PostgreSQL (container) | host `5433` → container `5432` | `DB_PORT` / `DB_HOST_PORT` in `.env` |
| Frontend SPA (other repo) | `3000` | `FRONTEND_URL` in `.env` |

Host port `5432` is deliberately avoided because it may already be used by a native PostgreSQL installation. Windows can also reserve port ranges (Hyper-V/WinNAT), so check a port before using it:

```powershell
Get-NetTCPConnection -State Listen -LocalPort 5433 -ErrorAction SilentlyContinue   # no output = free
netsh interface ipv4 show excludedportrange protocol=tcp                           # 5433 must not fall in a listed range
```

If `5433` is unavailable, choose another free port and set **both** `DB_PORT` and `DB_HOST_PORT` in `.env`.

Always use `localhost` (not `127.0.0.1`) for the API and SPA URLs in the browser — session-cookie authentication depends on it.

### First-time setup

```powershell
copy .env.example .env          # then set DB_PASSWORD to a strong random value
composer install
php artisan key:generate
docker compose -f docker-compose.db.yml up -d
php artisan migrate
php artisan boost:install       # optional: regenerates Laravel Boost AI skills (.claude/skills, not committed)
```

### Daily workflow

Run each in its own terminal (from this directory):

```powershell
docker compose -f docker-compose.db.yml up -d              # database (if not already running)
php artisan serve --port=8000                               # API
php artisan queue:work --queue=answers --tries=3            # answer-generation worker
php artisan queue:work --queue=documents --tries=3          # document-processing worker
```

`queue:work` keeps the application in memory: after changing code, run `php artisan queue:restart` (or restart the worker terminals).

Every job must declare its queue (`answers` or `documents`). A job pushed to the `default` queue is never picked up by these workers.

**Windows note:** PHP on Windows has no `pcntl` extension, so the worker cannot enforce a job's own `timeout`. Extraction is still bounded by the Poppler process timeout (`PDF_PROCESS_TIMEOUT`, 90 s) and embedding by the OpenAI request timeout (`OPENAI_TIMEOUT`, 30 s per request). On Linux/Docker the job timeouts (extraction 120 s, embedding 240 s) apply as well. Keep `DB_QUEUE_RETRY_AFTER` (300 s) above the longest job timeout.

### Scheduled tasks

`documents:prune-orphans` (hourly) deletes stored PDFs that no document refers to any more, e.g. after a failed file deletion. Run the scheduler in another terminal when you need it locally:

```powershell
php artisan schedule:work
php artisan documents:prune-orphans --dry-run   # or run it once by hand; lists without deleting
```

### Developer tools

```powershell
php artisan documents:search <document-id> "What is the refund policy?" --limit=5
```

Shows which chunks a question retrieves from a ready document, with page and similarity. It embeds the question through OpenAI, so it needs `OPENAI_API_KEY` and costs a tiny amount per run.

## Local database

The database container is defined in [`docker-compose.db.yml`](docker-compose.db.yml). It reads its credentials from `.env` — the same values Laravel uses.

| Task | Command |
|---|---|
| Start | `docker compose -f docker-compose.db.yml up -d` |
| Status / health | `docker compose -f docker-compose.db.yml ps` |
| Logs | `docker compose -f docker-compose.db.yml logs -f db` |
| SQL shell | `docker compose -f docker-compose.db.yml exec db psql -U askmydocs -d askmydocs` |
| Check pgvector | `docker compose -f docker-compose.db.yml exec db psql -U askmydocs -d askmydocs -c "select extversion from pg_extension where extname = 'vector';"` |
| Stop (keeps data) | `docker compose -f docker-compose.db.yml stop` |
| Remove container (keeps data) | `docker compose -f docker-compose.db.yml down` |
| Back up | `docker compose -f docker-compose.db.yml exec -T db pg_dump -U askmydocs -Fc askmydocs > askmydocs.dump` |

**Data** lives in the named volume `askmydocs_pgdata` and survives `stop`, `down` and restarts.

⚠️ `docker compose -f docker-compose.db.yml down -v` **deletes the volume and all data.**

**First start only:** scripts in `docker/postgres/init/` run only when the volume is empty. They create the `askmydocs_test` database. Changing `DB_USERNAME`/`DB_PASSWORD` in `.env` after the first start does **not** change the existing database user; either alter it with `psql` or recreate the volume.

**Upgrading pgvector:** change the image tag in `docker-compose.db.yml`, run `up -d`, then `ALTER EXTENSION vector UPDATE;` in each database. A major PostgreSQL upgrade (e.g. 18 → 19) requires a dump and restore.

The container restarts automatically with Docker Desktop (`restart: unless-stopped`) unless you stopped it explicitly.

## Tests

```powershell
php artisan test        # or: vendor/bin/pest
vendor/bin/pint --test  # formatting check (vendor/bin/pint to fix)
```

Feature tests run against the `askmydocs_test` database (see `phpunit.xml`), never the development database: `tests/TestCase.php` aborts if the configured database name does not end in `_test`. The database container must be running.

## Security notes

- `.env` is not committed. It holds the database password and, later, the OpenAI API key.
- The database port is bound to `127.0.0.1` only.
