# Service Request & Approval API

A Laravel REST API for a business SaaS module where employees submit internal service
requests (IT support, equipment, maintenance, access) and managers and operations teams
review, approve, assign and complete them. Every request has a status, an owner and a
complete activity history, and the API enforces who may do what.

**Stack:** Laravel 11 · PHP 8.2+ · MySQL · Laravel Sanctum · PHPUnit · Pint · Larastan · Scramble

## Quick start

Requirements: PHP 8.2+, Composer, MySQL.

```bash
git clone https://github.com/Tharindu-devinda/quantix-service-requests.git
cd quantix-service-requests

composer install
cp .env.example .env          # Windows PowerShell: copy .env.example .env
php artisan key:generate
```

Create an empty MySQL database (for example `quantix_requests`) and set the `DB_*` values
in `.env`. Then:

```bash
php artisan migrate:fresh --seed
php artisan serve             # http://127.0.0.1:8000
```

In a second terminal, start the queue worker (notifications are queued):

```bash
php artisan queue:work
```

Open http://127.0.0.1:8000/docs/api for the interactive API docs.

## Seeded logins

All passwords are `password`.

| Role | Email | Notes |
|---|---|---|
| Manager | manager@example.com | Manages Alice and Bob |
| Employee | alice@example.com | Direct report of the manager |
| Employee | bob@example.com | Direct report of the manager |
| Operations | operations@example.com | |

The seeder also creates 10 requests spread across all 8 statuses, each with a matching
activity history.

## Trying the API

The quickest way is the interactive docs at http://127.0.0.1:8000/docs/api: call
`POST /api/login`, paste the returned token into **Authorize**, then call any endpoint.

Seeded user ids (after `migrate:fresh --seed`): manager = 1, Alice = 2, Bob = 3,
operations = 4. Use `4` as `assignee_id` when assigning a request.

Notifications are listed at `GET /api/notifications` (run `php artisan queue:work` first).

## Running the tests

```bash
php artisan test        # or: composer test
composer check          # Pint (style) + Larastan (static analysis) + tests
```

Tests use an in-memory SQLite database, so they need no MySQL setup.

| Suite | What it covers |
|---|---|
| Unit | All 64 status transition pairs, the workflow service, every policy method |
| Feature | Happy path for each role, 401/403/404/422 matrices for every endpoint, queued notifications, error format, query counts (no N+1) |

## API overview

Log in with `POST /api/login`, then send `Authorization: Bearer <token>`.

| Method | Endpoint | Role | Purpose |
|---|---|---|---|
| POST | /api/login | Any | Issue an access token (10 attempts/min) |
| GET | /api/me | Any | Current user |
| POST | /api/logout | Any | Revoke the current token |
| POST | /api/requests | Employee | Create a draft, or send `submit: true` to submit immediately |
| GET | /api/requests | All | List with `search`, `status`, `sort`, `direction`, `per_page`, scoped by role |
| GET | /api/requests/{id} | All (policy) | Details with comments and activity history |
| PUT | /api/requests/{id} | Employee (owner) | Edit a draft or returned request |
| POST | /api/requests/{id}/submit | Employee (owner) | Send for manager review |
| POST | /api/requests/{id}/decision | Manager | `approve`, `reject` or `return` (comment required for the last two) |
| POST | /api/requests/{id}/assign | Operations | Assign an owner (`assignee_id`, must be an Operations user) |
| POST | /api/requests/{id}/status | Operations | `in_progress` or `completed` |
| POST | /api/requests/{id}/comments | Participants | Add a comment |
| GET | /api/notifications | All | The current user's notifications |

**Documentation:** interactive docs at `/docs/api` (served when `APP_ENV=local`) and an
exported OpenAPI file at [`api.json`](api.json). Regenerate it with `composer docs`.

**Response format:** single items and lists are wrapped in `data`; lists add `links` and
`meta` (pagination). Every error is JSON with a `message`, and validation errors add `errors`.

| Status | Meaning |
|---|---|
| 401 | Missing or invalid token |
| 403 | The role is not allowed to do this |
| 404 | Unknown route or record |
| 422 | Validation failed, or the status transition is invalid |
| 429 | Too many requests |

## Workflow

```
draft -> submitted -> approved -> assigned -> in_progress -> completed
             |  ^
             |  +---- returned (employee edits, then resubmits)
             +------> rejected
```

`rejected` and `completed` are final. Valid transitions are defined once, in the
`RequestStatus` enum, and applied by `RequestWorkflowService`.

## Assumptions and decisions

The brief left these open:

- **No front end.** The brief asks for a backend for a client built by another team, so this
  project is API only. Interactive OpenAPI docs let reviewers try every endpoint.
- **`assigned` is its own status** (`approved -> assigned -> in_progress -> completed`), which
  matches the six-step journey and makes the history clearer.
- **Who sees what in the list:** employees see their own requests (including drafts);
  managers see their direct reports' requests **except drafts**; Operations users see
  requests from `approved` onwards.
- **403 vs 422:** policies decide *who* may act (403). The workflow service decides whether
  the *move is legal* (422), so a manager approving an already-approved request gets a 422.
- **Editing:** only the requester can edit, and only in `draft` or `returned`. Trying to edit
  any other status, including final ones, returns 403.
- **Operations actions:** any Operations user who can see a request may assign, start or
  complete it (not only the assignee). The assignee must have the Operations role.
- **Decision comments:** required for `reject` and `return`, optional for `approve`.
- **Comments:** allowed for anyone who can view the request, including on final requests,
  because a comment does not change its state. Comments are immutable.
- **Create and submit:** `POST /api/requests` with `submit: true` does both in one
  transaction.
- **`submitted_at`** is refreshed when a returned request is resubmitted.
- **Notifications** are sent by the database channel through the queue, after the
  transaction commits. The person who made the change is never notified. Edits and comments
  do not notify.

| New status | Notified |
|---|---|
| submitted | The requester's manager |
| approved | The requester and all Operations users |
| rejected / returned | The requester |
| assigned | The assignee and the requester |
| in progress | The requester |
| completed | The requester and their manager |

## Code quality

- **Pint** (Laravel preset): `composer lint` / `composer format`
- **Larastan** at level 6, with no baseline: `composer analyse`
- Thin controllers, Form Requests, API Resources, Policies, a workflow service, enums.
  See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## What I would do next

- Raise Larastan to level 8 and add coverage reporting to CI.
- Add a users endpoint so clients can pick an assignee, and allow Operations to start or
  complete only requests assigned to them.
- Notify on new comments, add email as a second notification channel, and mark
  notifications as read.
- Optional stretch goals: file attachments, SLA due dates with an overdue flag, a Docker or
  Sail setup, and rate limiting on the whole API (only login is limited today).
- Add a front end (Blade or a SPA) as a thin client over this API.

## References, packages and AI usage

**Packages:** laravel/framework, laravel/sanctum, dedoc/scramble (API docs),
laravel/pint, larastan/larastan.

**References:** the Laravel 11 documentation (Sanctum, authorization, queues,
notifications, API resources, testing) and the Scramble documentation.

**AI usage:** I used Claude (Anthropic) as a pair-programming assistant to plan the
architecture, draft code and tests step by step, and review error output.