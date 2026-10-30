# AskMyDocs API Contract

Source of truth for the API used by **askmydocs-frontend**. The two applications live in separate repositories, so any change here must be reflected in the frontend's API types and clients.

- **Base URL (local):** `http://localhost:8000`
- **API prefix:** `/api/v1`
- **Format:** JSON requests and responses. Send `Accept: application/json`.
- **Status:** Phase 2 — authentication, profile and documents. Conversations and messages are added in later phases.

## Authentication model

The SPA uses **Laravel Sanctum in SPA (cookie) mode**. There are no API tokens.

| Cookie | Set by | Readable by JS | Purpose |
|---|---|---|---|
| `askmydocs-session` | API | No (HttpOnly) | Identifies the logged-in session |
| `XSRF-TOKEN` | API | Yes | CSRF token; the client copies it into the `X-XSRF-TOKEN` request header |

Requirements for the client:

1. Use **`localhost`** for both the SPA and the API (never mix with `127.0.0.1`), or cookies will not be shared.
2. Send credentials with every request (axios: `withCredentials: true`, `withXSRFToken: true`).
3. Call `GET /sanctum/csrf-cookie` once before the first state-changing request (login/register). It returns `204` and sets `XSRF-TOKEN`.
4. Every `POST`/`PUT`/`PATCH`/`DELETE` must send the `X-XSRF-TOKEN` header (axios does this automatically when `withXSRFToken` is enabled).
5. The browser sends the `Origin` header automatically; only origins listed in `SANCTUM_STATEFUL_DOMAINS` (`localhost:3000`) get session authentication, and only `FRONTEND_URL` is allowed by CORS.

After logout, the CSRF token is rotated; call `/sanctum/csrf-cookie` again before the next login.

## Response conventions

### Success

Single resources are wrapped in `data`:

```json
{ "data": { "id": 1, "name": "Ana", "email": "ana@example.com", "created_at": "2026-10-26T09:51:46+00:00" } }
```

Actions without a resource return `204 No Content` or a `{ "message": "..." }` body. Timestamps are ISO 8601 strings.

### Errors

Every API error has the same shape:

```json
{
  "message": "Human-readable summary.",
  "code": "validation_failed",
  "errors": { "email": ["The email has already been taken."] }
}
```

`errors` is present only for validation failures (field → list of messages).

| Status | `code` | Meaning / client action |
|---|---|---|
| 401 | `unauthenticated` | No valid session → treat as logged out |
| 403 | `forbidden` | Authenticated but not allowed |
| 404 | `not_found` | Resource does not exist **or belongs to another user** (see Ownership) |
| 409 | `conflict` | The action does not fit the resource's current state (e.g. retrying a document that has not failed) |
| 413 | `payload_too_large` | Request body above the server's limit (normally caught earlier as a 422 on `file`) |
| 419 | `csrf_token_mismatch` | CSRF token missing/expired → call `/sanctum/csrf-cookie` and retry once |
| 422 | `validation_failed` | Show `errors` next to form fields |
| 429 | `too_many_requests` | Rate limited; `Retry-After` header gives seconds to wait |
| 500 | `server_error` | Generic message only; details are in the server log |

Clients should branch on `code` (stable), not on `message` (wording may change).

### Ownership rule (applies from Phase 2)

Users can only access their own documents, conversations and messages. A request for another user's resource returns **404 `not_found`** — identical to a resource that does not exist — so IDs cannot be probed. Resource IDs for documents, conversations and messages are ULIDs.

## Endpoints

### `GET /sanctum/csrf-cookie`

Sets the `XSRF-TOKEN` cookie. → `204`

### `POST /api/v1/auth/register`

Rate limit: 6/min per IP.

```json
{ "name": "Ana", "email": "ana@example.com", "password": "Secret123", "password_confirmation": "Secret123" }
```

- Email is trimmed and lowercased; must be unique (case-insensitive in practice).
- Password: at least 8 characters, upper- and lowercase letters, and a number (in production also checked against known breaches).

→ `201` `{ "data": User }` and the session is logged in. → `422` on validation errors.

### `POST /api/v1/auth/login`

Rate limit: 5/min per email + IP → `429` afterwards.

```json
{ "email": "ana@example.com", "password": "Secret123", "remember": false }
```

→ `200` `{ "data": User }`. → `422` with `errors.email` for invalid credentials (same message for unknown email and wrong password).

### `POST /api/v1/auth/logout` (authenticated)

→ `204`. The session is invalidated and the CSRF token rotated.

### `POST /api/v1/auth/forgot-password`

Rate limit: 6/min per IP.

```json
{ "email": "ana@example.com" }
```

→ `200` `{ "message": "If an account exists for this email address, a password reset link has been sent." }` — always the same response, whether or not the account exists.

The email links to the SPA: `{FRONTEND_URL}/reset-password?token=...&email=...`. The SPA reads both query parameters and calls the endpoint below. (Locally, emails are written to `storage/logs/laravel.log`.)

### `POST /api/v1/auth/reset-password`

Rate limit: 6/min per IP.

```json
{ "token": "...", "email": "ana@example.com", "password": "NewSecret123", "password_confirmation": "NewSecret123" }
```

→ `200` `{ "message": "Your password has been reset." }` (the user is **not** logged in; the SPA should go to login). Other sessions of that user are logged out.
→ `422` with `errors.token` when the token is invalid/expired or the email is unknown (same message).

### `GET /api/v1/user` (authenticated)

→ `200` `{ "data": User }`. → `401` when not logged in (the SPA uses this on startup to restore the session).

### `PATCH /api/v1/user` (authenticated)

Any subset of:

```json
{ "name": "New Name", "email": "new@example.com" }
```

→ `200` `{ "data": User }`. → `422` if the email is taken.

### `PUT /api/v1/user/password` (authenticated)

```json
{ "current_password": "Secret123", "password": "NewSecret123", "password_confirmation": "NewSecret123" }
```

→ `204`. → `422` with `errors.current_password` if the current password is wrong. Other sessions of the user are logged out on their next request.

### Documents (all authenticated)

A document goes through these statuses after upload:

```
queued -> extracting -> ready
              \-> failed   (error_code + error_message say why)
```

Clients poll `GET /documents/{id}` until `is_finished` is `true` (suggested: every 2–3 s, backing off). When `failed`, show `error_message`; branch on `error_code`:

| `error_code` | Meaning |
|---|---|
| `invalid_pdf` | File is damaged or not a real PDF |
| `encrypted_pdf` | Needs a password to open |
| `extraction_not_permitted` | The PDF forbids copying its text |
| `no_text_layer` | No selectable text (e.g. a scan); OCR is not supported |
| `extraction_timeout` / `extraction_failed` | Processing failed after 3 attempts; retrying later may help |
| `file_missing` | The stored file is gone; upload again |

#### `GET /api/v1/documents`

The user's documents, newest first, 20 per page (`?page=2`).

→ `200` `{ "data": Document[], "links": {...}, "meta": { "current_page", "last_page", "per_page", "total", ... } }`

#### `POST /api/v1/documents`

`multipart/form-data`. Rate limit: 10/min per user.

| Field | Rules |
|---|---|
| `file` | Required. A PDF (checked by content, not just the name), at most 20 MB and 300 pages, not password-protected |
| `title` | Optional, max 255 characters. Default: the filename without `.pdf` |

→ `202` `{ "data": Document }` with `status: "queued"`: stored, text extraction pending.
→ `422` with `errors.file` (single message), e.g. "The file must be a PDF.", "The file may not be larger than 20 MB.", "This PDF has 412 pages; the maximum is 300.", "This PDF is password-protected. Remove the password and upload it again."

#### `GET /api/v1/documents/{id}`

→ `200` `{ "data": Document }`

#### `GET /api/v1/documents/{id}/file`

The original PDF: `Content-Type: application/pdf`, `Content-Disposition: inline`, not cacheable. → `200` (binary). Fetch it with credentials (e.g. axios `responseType: 'blob'`) and display it via an object URL.

#### `GET /api/v1/documents/{id}/pages/{n}`

Extracted text of page `n` (1-based); available once the document is `ready`. Used to show a citation's context.

→ `200` `{ "data": DocumentPage }`. → `404` if the page does not exist (yet).

#### `POST /api/v1/documents/{id}/retry`

Re-queues processing of a `failed` document (rate limit shared with uploads). Retrying makes most sense for `extraction_timeout` / `extraction_failed`; problems with the file itself fail again.

→ `202` `{ "data": Document }` with `status: "queued"`. → `409 conflict` if the document is not `failed`.

#### `DELETE /api/v1/documents/{id}`

Permanently deletes the document, its file and its extracted text (later also its conversations). → `204`

All `{id}` endpoints answer `404 not_found` for unknown, malformed and other users' IDs.

## Types

```ts
interface User {
  id: number
  name: string
  email: string
  created_at: string // ISO 8601
}

type DocumentStatus = 'queued' | 'extracting' | 'ready' | 'failed'

interface Document {
  id: string // ULID, lowercase
  title: string
  original_filename: string
  size_bytes: number
  page_count: number | null
  status: DocumentStatus
  is_finished: boolean // ready or failed: stop polling
  error_code: string | null
  error_message: string | null
  created_at: string
  processed_at: string | null
}

interface DocumentPage {
  document_id: string
  page_number: number
  text: string
  char_count: number
}

interface Paginated<T> {
  data: T[]
  links: { first: string; last: string; prev: string | null; next: string | null }
  meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null }
}

interface ApiError {
  message: string
  code: string
  errors?: Record<string, string[]>
}
```
