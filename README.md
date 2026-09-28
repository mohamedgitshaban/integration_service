# Instructor Revenue Ledger

The money core of an LMS: students pay for a subscription up front, instructors earn a share of it as the term is delivered, and the platform pays instructors what they are owed through an unreliable external provider — without ever paying twice, paying the wrong amount, or losing a piastre to rounding.

- **Architecture, decisions and trade-offs:** [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- **How AI was used:** [docs/AI_USAGE.md](docs/AI_USAGE.md)

## What is in the box

| Requirement | Where |
|---|---|
| Schema + migrations | `database/migrations` — subscriptions, allocations, append-only `ledger_entries`, `instructor_balances` (cached projection), `payouts`, `payout_runs` |
| Revenue allocation | `app/Services/RevenueAllocationService.php`, `app/Support/RevenueMath.php` |
| Refunds (incl. mid-term) | `app/Services/RefundService.php` |
| Payout process | `php artisan payouts:run` + `SendPayoutJob`, `php artisan payouts:reconcile` + `ResolvePayoutJob`, `app/Services/PayoutService.php` |
| Mock payment provider | `app/Services/Payments/MockPaymentProvider.php` — succeeds, fails permanently, times out after the money moved, or times out before it moved |
| Tests | 104 tests (unit + feature) — see [Tests](#tests) |
| Filament screen | `/admin` — instructor balances, payout history, ledger, platform totals (read-only) |
| REST API (extra) | `/api/v1` — student subscribe/refund, instructor balance/withdraw — see [API](#api) |

## Stack

PHP 8.3 · Laravel 13 · MySQL/MariaDB · Filament 3.3 · Livewire 3 · Sanctum 4 · PHPUnit 12

> The brief lists Laravel 11 and Pest. This project started on Laravel 13 (Filament 3.3 supports it), and uses PHPUnit, which the brief also accepts ("Pest or PHPUnit").

## Setup

Requirements: PHP 8.3 with `pdo_mysql` and `intl`, Composer, MySQL 8 or MariaDB 10.6+.

```bash
composer install
cp .env.example .env
php artisan key:generate

# point DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env at your database, then:
php artisan migrate:fresh --seed
```

Seeding takes a few seconds and is reproducible (fixed random seed). It creates:

| Account (password `password`) | Role |
|---|---|
| `admin@example.com` | admin — can open `/admin` |
| `instructor@example.com` | instructor with bank details |
| `student@example.com` | student on an annual plan including the demo instructor's courses |
| `no-bank@example.com` | instructor with no bank details (shows payouts skipping them) |

…plus 25 instructors, ~120 courses, 300 students on mixed plans started over the last 8 months, revenue recognised up to yesterday, a historic payout run 30 days ago, 15 refunds (some full, clawing back money already paid out), and a final integrity rebuild that fails loudly if any balance disagrees with the ledger.

### Running it

```bash
php artisan serve              # http://localhost:8000/admin
php artisan queue:work         # sends payouts and runs allocation jobs
php artisan schedule:work      # or a cron for `php artisan schedule:run`
```

Scheduled tasks (`routes/console.php`), each `withoutOverlapping()->onOneServer()`:

| Command | When | What |
|---|---|---|
| `revenue:allocate [--through=Y-m-d]` | daily 00:30 | recognise served days (default: through yesterday) |
| `payouts:run` | Mondays 03:00 | reserve every eligible balance and queue the transfers |
| `payouts:reconcile` | every 5 minutes | re-send lost pending jobs, resolve unknown outcomes by status check |

Manual: `php artisan subscriptions:refund {id} [--on=Y-m-d] [--full]`.

### Try the failure handling

```bash
php artisan payouts:run          # some payouts succeed, some fail, some end "unknown"
php artisan payouts:run          # queues 0 — nothing is paid twice
php artisan payouts:reconcile    # unknowns resolved by status check, never re-sent
```

Watch it happen in `/admin/payouts`. Provider behaviour is configurable in `config/ledger.php` (`mock_provider.weights`, `confirmation_delay_seconds`).

## Tests

Tests run against MySQL, not SQLite: the concurrency guarantees depend on real row locks (`SELECT … FOR UPDATE`), and SQLite does not have them.

```bash
# once: create the test database
mysql -e "CREATE DATABASE instructor_revenue_ledger_testing"

php artisan test
# if your .env DB_HOST/DB_PORT differ from the test database's, override them:
DB_HOST=127.0.0.1 DB_PORT=3307 php artisan test
```

| File | Proves |
|---|---|
| `Unit/RevenueMathTest` | integer-only recognition and splitting; parts always sum to the whole; deterministic rounding (incl. 500 randomised splits) |
| `Unit/SubscriptionPlanTest` | term lengths across month ends and leap years |
| `Feature/RevenueAllocationTest` | only served days are earned; re-running is a no-op; a whole term accounts for every piastre; catch-up equals daily |
| `Feature/RefundTest` | pro-rata / backdated / full refunds; clawbacks never exceed what an instructor earned; platform + instructors + refund = amount paid |
| `Feature/PayoutTest` | **running twice never double-pays; duplicate and retried jobs never call the provider twice; timeouts are resolved by status check, never re-sent**; crash mid-call; refund after payout |
| `Feature/PayoutChaosTest` | random provider outcomes, double runs and duplicate jobs over 8 rounds: money moved per instructor = ledger "paid", never more than earned |
| `Feature/BalanceRebuildTest` | cached balances can be rebuilt from source records; a corrupted ledger is reported, not papered over |
| `Feature/LedgerSchemaTest` | ledger is append-only; the same business event cannot be recorded twice |
| `Feature/Api/*` | auth and role separation, subscribe (price from server, payment replay), refunds, withdrawals (idempotency key, no double pay with the scheduled run, destination snapshot), full student → instructor scenario |
| `Feature/AdminPanelTest` | admin-only access; balances, filters, payout history, dashboard totals |
| `Feature/DatabaseSeederTest`, `FactoryTest` | demo data is consistent; every factory state is valid |

## API

Base URL `/api/v1`, JSON, bearer tokens (Sanctum). Money is always an integer in minor units (piastres) plus a `currency`.

| Who | Method & path | Notes |
|---|---|---|
| Public | `POST /register`, `POST /login` | returns `token`; role `student` or `instructor` (never admin) |
| Public | `GET /plans`, `GET /courses[?search=]`, `GET /courses/{id}` | prices come from config |
| Any | `GET /me`, `POST /logout` | |
| Student | `POST /student/subscriptions` | `{plan, course_ids[], payment_reference}` — 201 created, 200 if the same reference is replayed |
| Student | `GET /student/subscriptions`, `GET /student/subscriptions/{id}` | own only |
| Student | `POST /student/subscriptions/{id}/courses`, `DELETE …/courses/{course}` | changes the split of future days |
| Student | `POST /student/subscriptions/{id}/refund` | pro-rata; idempotent |
| Instructor | `GET /instructor/balance` | earned, in flight, paid, outstanding, `can_withdraw` |
| Instructor | `POST /instructor/withdrawals` | withdraw the whole outstanding balance; 202 + payout; send `Idempotency-Key` to make retries safe |
| Instructor | `GET /instructor/payouts`, `GET /instructor/payouts/{id}` | scheduled and withdrawal payouts, account masked |
| Instructor | `GET /instructor/ledger[?type=]` | every movement behind the balance |
| Instructor | `GET`/`PUT /instructor/payout-details` | IBAN-shaped `bank_account_number` |
| Instructor | `GET`/`POST /instructor/courses`, `PUT /instructor/courses/{id}` | own courses, with student counts |

Example:

```bash
TOKEN=$(curl -s -X POST localhost:8000/api/v1/login -H 'Accept: application/json' \
  -d email=instructor@example.com -d password=password | jq -r .token)

curl -s localhost:8000/api/v1/instructor/balance -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
curl -s -X POST localhost:8000/api/v1/instructor/withdrawals -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' -H 'Idempotency-Key: 7f3c1e2a'
```

## Assumptions

The brief leaves rules open on purpose. The main calls made (reasoning in [ARCHITECTURE.md](docs/ARCHITECTURE.md)):

1. **Revenue is earned day by day**, not on payment. An up-front payment is a liability until each day of access is delivered. Days are calendar days in UTC.
2. **Platform share is 30%** (configurable), **snapshotted per subscription** at purchase.
3. **The instructor pool is split by the student's courses**: each instructor is weighted by how many of the student's courses they teach. A student with no courses: the platform keeps that day's revenue.
4. **Rounding:** all money is integer piastres; cumulative flooring for time, largest remainder for splits. Fractions of a piastre go to instructors, never lost.
5. **Refunds are pro-rata by default:** served days stay earned, the unserved remainder is refunded and never reaches instructors. A **full** refund (admin decision) claws back everything; an instructor already paid goes into a negative balance recovered from future earnings.
6. **Payouts** pay an instructor's whole outstanding balance if it is at least EGP 100 and bank details are on file; weekly, or on demand via the API.
7. **Payment capture happens at a hosted checkout** before the API is called; the API records the resulting `payment_reference`. Verifying it with the provider is out of scope.
8. **One subscription in service per student.** Plan changes mid-term are discussed (not built) in ARCHITECTURE.md.
