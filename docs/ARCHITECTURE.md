# Architecture

This document explains how the ledger works, why it is built this way, and what it does not do. Every guarantee below is backed by a named test.

**Contents:** [Principles](#principles) · [Domain model](#domain-model) · [Money representation](#money-representation) · [Revenue allocation](#revenue-allocation) · [Refunds](#refunds) · [Balances](#instructor-balances) · [Payouts](#payouts) · [Idempotency](#idempotency) · [Provider timeouts](#provider-timeout-handling) · [Failure scenarios](#failure-scenarios) · [Scaling](#scaling-considerations) · [Known limitations](#known-limitations) · [Plan changes (bonus)](#bonus-changing-plans-mid-term)

## Principles

1. **Money is never a float.** Every amount is an integer number of piastres.
2. **Money is never lost or invented by rounding.** Every split returns parts that sum exactly to the whole; every time-based calculation works on cumulative totals.
3. **History is append-only.** Money movements are ledger rows that are never updated or deleted. Corrections are new rows.
4. **Every write is idempotent by construction**, enforced by the database (unique keys, row locks, atomic state transitions), not by hoping a job runs once.
5. **When unsure whether money moved, never send it again.** Ask the provider.

## Domain model

```
users (role: student | instructor | admin)
  ├─ subscriptions ──< subscription_courses >── courses ── instructor (users)
  │     └─< revenue_allocations (recognition | reversal)
  │               └─< ledger_entries (earning | clawback)
  └─ instructor ─< ledger_entries (earning | clawback | payout | payout_reversal)
                ─── instructor_balances (1 row, cached projection)
                ─< payouts >── payout_runs (null for on-demand withdrawals)
```

| Table | Role |
|---|---|
| `subscriptions` | What the student paid, the term, and two cursors: `service_ends_on` (term end, or day before a refund) and `recognized_through` (last day already turned into earnings). Snapshots `platform_share_bps` and carries a unique `payment_reference`. |
| `revenue_allocations` | One row per recognition run (or reversal) of a subscription: the days covered and how the gross split into platform cut and instructor pool. `gross = platform + pool`, always. |
| `ledger_entries` | Signed, append-only money movements per instructor. **The source of truth.** |
| `instructor_balances` | Cached `earned / reserved / paid` per instructor, written in the same transaction as the ledger row that changes it. Rebuildable. |
| `payouts` | One transfer attempt to an instructor: amount, snapshotted destination, fixed provider idempotency key, status. |
| `payout_runs` | A scheduled run (for reporting). Withdrawals have no run. |

Foreign keys on financial tables are `restrictOnDelete`: nothing that money depends on can be deleted.

## Money representation

- `unsignedBigInteger` / `bigInteger` columns holding **piastres** (EGP minor units). Single currency by design (`config('ledger.currency')`).
- Casts are `integer`; there is no `decimal` or `float` anywhere in the money path.
- Percentages are **basis points** (`3000` = 30%) so they are integers too.
- The API returns `amount_minor` + `currency`; the admin panel formats with `intl`.

## Revenue allocation

### When is money earned? — day by day

A student pays for the whole term on day one, but the platform has not delivered anything yet. We treat the payment as a liability that turns into revenue **one day at a time** as access is delivered. The nightly `revenue:allocate` job recognises every **fully served** day (through yesterday).

Why not recognise everything on payment day?
- A mid-term refund would then require clawing money back from instructors who may already have been paid. With daily recognition, the unserved part was **never** allocated, so the common refund case touches no instructor at all.
- It matches how subscription revenue is recognised in accounting (IFRS 15 / ASC 606: over the service period).

Trade-off: instructors wait for their money to be earned, and the system does more bookkeeping (see [Scaling](#scaling-considerations)).

### How much is earned by day *d*? — cumulative flooring

```
earned(d) = floor(amount × days_served(d) / term_days),   and = amount on the last day
period_gross = earned(period_end) − earned(period_start − 1)
```

Recognising the *difference of cumulative totals* means the per-period amounts always sum to exactly `amount` over the term, **no matter how the days are batched**. A catch-up run covering 45 days gives exactly the same total as 45 daily runs (`RevenueAllocationTest::test_catching_up_in_one_run_recognises_the_same_totals_as_running_daily`). A 100,003-piastre annual plan recognised daily accounts for every piastre (`test_daily_recognition_over_a_whole_term_accounts_for_every_piastre`).

### Platform cut

`platform(d) = floor(earned(d) × bps / 10000)`, also cumulative. The floored fraction goes to **instructors**, not the platform. The `bps` value is **snapshotted on the subscription** at purchase: changing the platform rate later never re-prices a term already paid for, and cannot break the cumulative arithmetic mid-term.

### Splitting the pool between instructors

**Weight = number of the student's courses the instructor teaches.** A student taking 2 courses from A and 1 from B gives A ⅔ and B ⅓ of each day's pool.

Alternatives considered:

| Rule | Why not (for now) |
|---|---|
| Equal per instructor | An instructor with one course on the student's list earns as much as one with five. |
| Watch time / engagement | Fairest, but needs consumption data this system does not have; also opens gaming (looping videos). The weight function is isolated in `RevenueAllocationService::instructorWeights()`, so it can be swapped. |
| Per-course price | The product sells one subscription, not courses. |

**Rounding: largest remainder** (`RevenueMath::splitByWeight`). Everyone gets the floor of their exact share; leftover piastres go to the largest fractional remainders; ties go to the lowest instructor id, so results are deterministic and reproducible. Parts always sum exactly to the pool (verified over 500 randomised splits in `RevenueMathTest`).

**No courses selected:** the platform keeps that day's pool — there is no instructor to pay, and the money must not disappear.

### Concurrency and idempotency of allocation

`allocateThrough()` locks the subscription row, reads `recognized_through`, writes the allocation and ledger rows, and advances the cursor, **all in one transaction**. A repeated or overlapping call finds nothing due. The unique key `(subscription_id, kind, period_start)` on allocations and the unique ledger `idempotency_key` (`earning:{allocation}:{instructor}`) back this up at the database level.

## Refunds

Handled by `RefundService`, idempotent (the subscription row is locked and its status checked).

| Case | Behaviour |
|---|---|
| **Pro-rata (default)** — refund effective on day D | Days up to D−1 were served and stay earned. Student gets `amount − earned(D−1)`. Instructors untouched: the remainder was never allocated. |
| Before the term starts | Everything back; nothing was ever earned. |
| **Backdated** past days already allocated | A **reversal** allocation undoes the extra days. Platform cut reversed cumulatively; instructor part reversed as **clawback** ledger entries. |
| **Full** (admin decision: cooling-off, fraud, service failure) | Every earning from the subscription is reversed; student gets 100%. |
| Term fully served | Rejected — nothing refundable. |

**Who loses what in a clawback?** The clawback is split **in proportion to what each instructor actually earned from that subscription**, not by today's course list. So nobody loses more than they received from it, and a full refund returns every instructor to exactly zero for that subscription (`RefundTest::test_a_full_refund_reverses_every_instructor_earning_exactly`, `test_a_partial_clawback_never_takes_more_from_an_instructor_than_they_earned`).

**Refund after payout.** If the instructor was already paid, the clawback makes their balance **negative**. The platform does not chase them for money; the debt is netted against future earnings, and payouts only ever pay a positive balance (`PayoutTest::test_a_refund_after_payout_leaves_a_debt_that_is_recovered_from_future_earnings`).

Every refund test ends with a conservation check: **platform + instructors + refund = amount paid**, to the piastre.

## Instructor balances

The ledger is the truth; the balance table is a cache of it.

| Ledger entry | Sign | Balance column |
|---|---|---|
| `earning` | + | `earned += amount` |
| `clawback` | − | `earned += amount` |
| `payout` (at reservation) | − | `reserved += |amount|` |
| `payout_reversal` (definite failure) | + | `reserved −= amount` |
| payout confirmed (no ledger row) | | `reserved −= amount, paid += amount` |

Answers to the brief's questions, at any time:

- **Owed (earned):** `earned_minor`
- **Already paid:** `paid_minor`
- **Outstanding:** `earned − reserved − paid`, which **always equals `SUM(ledger_entries.amount_minor)`** for that instructor. `reserved` is money committed to a payout whose outcome is not final yet.

Why a cached projection and not `SUM()` on every read? With tens of millions of ledger rows, summing per request is too slow; one row per instructor is O(1). The cost is keeping them in sync, which is solved by a single writer: **only `LedgerService` writes either table**, always both in the same transaction, and it refuses to run outside one.

`LedgerService::rebuildBalance()` recomputes a balance from the ledger and payouts and **throws if they disagree** instead of silently "fixing" numbers. `InstructorBalanceSeeder` runs it for every instructor as a final integrity check.

## Payouts

```
payouts:run ─(balance lock)─► payout: pending ──SendPayoutJob──► processing ──provider──┬─► succeeded
POST /withdrawals ──────────┘   + ledger debit       (atomic claim)                     ├─► failed ─► ledger reversal
                                                                                         └─► unknown
payouts:reconcile ─► stale pending → re-dispatch                                              │
                  ─► stale processing (worker died) → unknown                                 │
                  ─► unknown ─ResolvePayoutJob─► provider status(key) ─► succeeded / failed ◄─┘
                                                  (not found only counts as failed after a grace period)
```

1. **Reserve.** In one transaction holding the instructor's balance row lock: compute outstanding, create a `pending` payout with a freshly generated, permanently stored `idempotency_key`, snapshot the destination account, and write the `payout` ledger debit. Instructors below the minimum (EGP 100) or without bank details are skipped.
2. **Claim.** `UPDATE payouts SET status='processing' WHERE id=? AND status='pending'`. Only the worker whose update affects a row may continue.
3. **Send**, outside any transaction (never hold DB locks across a network call), with the stored key and destination.
4. **Settle** under a row lock on the payout: success → reserved moves to paid; definite failure → `payout_reversal` returns the money to outstanding, and the next run pays it under a **new** key; ambiguous → `unknown`.

Scheduled runs and on-demand withdrawals share steps 1–4, so they can never both reserve the same money.

## Idempotency

| Threat | Protection | Proven by |
|---|---|---|
| `payouts:run` twice, overlapping, or on two servers | Reservation under the balance row lock: the second run sees outstanding 0. A cache lock on the command only saves wasted work; correctness doesn't depend on it. | `PayoutTest::test_running_payouts_twice_never_double_pays`, `test_an_overlapping_run_finds_nothing_left_to_reserve` |
| Same job delivered twice / retried by the queue | Atomic `pending → processing` claim; `ShouldBeUnique` only reduces noise. | `test_duplicate_and_retried_send_jobs_never_call_the_provider_twice` (bypasses queue uniqueness on purpose) |
| Worker crashes after calling the provider | Row stays `processing`; reconcile marks it `unknown` after 15 min and resolves by status check. The retried job cannot claim it. | `test_a_worker_that_dies_mid_call_is_resolved_by_status_check_not_resent` |
| Allocation job re-run | Subscription row lock + `recognized_through` cursor + unique keys. | `RevenueAllocationTest::test_allocating_the_same_day_twice_changes_nothing` |
| Same ledger event written twice | Unique `ledger_entries.idempotency_key` derived from the business event (`earning:{alloc}:{instructor}`, `payout:{id}`, `payout_reversal:{id}`, `clawback:{alloc}:{instructor}`). | `LedgerSchemaTest::test_the_same_business_event_cannot_be_recorded_twice` |
| Refund requested twice | Status check under row lock. | `RefundTest::test_refunding_twice_changes_nothing` |
| Student replays a payment | Unique `subscriptions.payment_reference`; replay returns the original. | `StudentApiTest::test_replaying_the_same_payment_returns_the_original_subscription` |
| Instructor double-taps "withdraw" | `Idempotency-Key` header stored as `(instructor_id, withdrawal_request_key)` unique; replay returns the original payout. | `WithdrawalApiTest::test_repeating_a_withdrawal_with_the_same_idempotency_key_returns_the_same_payout` |
| Withdrawal + scheduled run | Same reservation path and lock. | `test_a_withdrawal_and_the_scheduled_run_never_pay_the_same_money_twice` |

## Provider timeout handling

A timeout means **"I don't know"**, not "it failed". Treating it as a failure and retrying is exactly how instructors get paid twice.

- Any exception from `transfer()` (timeout, connection error, anything unexpected) marks the payout `unknown`. **Unknown payouts are never re-sent.** The money stays `reserved`, so it cannot be paid by a later run either.
- `payouts:reconcile` (every 5 minutes) calls `status(idempotency_key)`:
  - `succeeded` → paid. `failed` → reversed, repaid by the next run under a new key.
  - `not found` → **stay unknown** until the payout is older than the grace period (30 min). Providers can take time to make a transfer visible; the mock simulates this with a confirmation delay. Only after the grace period is "not found" treated as "never happened".
  - status check itself times out → stay unknown, try again next time.
- The mock provider honours idempotency keys like real providers do, but **the design does not rely on it**: tests assert `transfer()` is called at most once per payout.
- If the provider ever contradicts a settled payout (e.g. says succeeded for one we marked failed), it is logged as critical for a human; it is not auto-corrected.

## Failure scenarios

| Scenario | Outcome | Test |
|---|---|---|
| Running payouts twice | One payout, one provider call | `PayoutTest::test_running_payouts_twice_never_double_pays` |
| Duplicate job execution | One provider call | `test_duplicate_and_retried_send_jobs_never_call_the_provider_twice` |
| Worker retry after crash | Resolved by status check, not re-sent | `test_a_worker_that_dies_mid_call_is_resolved_by_status_check_not_resent` |
| Provider timeout, money moved | Unknown → confirmed, paid once | `test_a_timeout_after_the_money_moved_is_never_resent_and_is_confirmed_by_a_status_check` |
| Provider timeout, money not moved | Unknown → failed after grace → repaid | `test_a_timeout_before_the_money_moved_fails_only_after_the_grace_period_and_is_then_repaid` |
| Success with delayed confirmation | Not failed prematurely | same as above (status "not found" within grace) |
| Permanent failure | Money back to outstanding, repaid under a new key | `test_a_permanent_failure_returns_the_money_to_outstanding_and_the_next_run_pays_it` |
| Lost dispatch (crash between commit and dispatch) | Reconcile re-dispatches | `test_a_pending_payout_whose_job_was_lost_is_sent_by_reconcile` |
| Refund after payout | Negative balance, recovered from future earnings | `test_a_refund_after_payout_leaves_a_debt_that_is_recovered_from_future_earnings` |
| Rounding edge cases | Every piastre accounted for | `RevenueMathTest`, `RevenueAllocationTest::test_daily_recognition_over_a_whole_term_accounts_for_every_piastre` |
| All of the above at random | Money moved = ledger "paid" per instructor | `PayoutChaosTest` |

## Scaling considerations

Target: 500,000 active subscriptions, tens of millions of records.

- **Allocation fan-out.** `revenue:allocate` pages through due subscriptions with an indexed query (`recognized_through`, plus `service_ends_on` so finished and refunded subscriptions are not rescanned) and queues one job per 1,000 → ~500 jobs per night. Each subscription commits separately, so a crashed job only redoes unfinished work.
- **Ledger growth.** Daily recognition writes roughly one earning row per (subscription × instructor) per run: ~1.5M rows/day at 500k subscriptions with ~3 instructors each. Because catch-up is exact, **recognition cadence is a free parameter**: running allocation weekly (or just before each payout run) gives the same money with ~7× fewer rows. At production scale I would run it weekly and partition `ledger_entries` by month.
- **Hot rows.** A popular instructor's balance row is locked by every allocation touching them. Rows are locked in ascending instructor id to avoid deadlocks, but it is still contention. Next step: aggregate earnings per instructor per job chunk and write one ledger row per instructor per chunk.
- **Reads are O(1).** Balances, the admin dashboard and the API read `instructor_balances` (one row per instructor), not the ledger.
- **Payout runs** scan `instructor_balances` (thousands of rows, not millions); transfers go out as independent jobs.
- **Queue.** `retry_after` must stay above the longest job runtime; the design is safe even if it doesn't (duplicate jobs are no-ops), just wasteful.

## Known limitations

1. **Course weights are read at allocation time.** If a student changes courses between runs, the whole period since the last run uses the new list. Fix: store enrolment history with dates and weight per day (or run allocation daily, as scheduled).
2. **`payment_reference` is not verified** with the checkout provider; in production it would be confirmed via the provider API or created from its webhook.
3. **Refunds to students are recorded, not executed.** `refund_amount_minor` is computed; sending the money back to the card would go through the provider.
4. **"Not found after grace = failed"** assumes the provider makes every accepted transfer visible within 30 minutes. A provider that is both slower than that *and* ignores idempotency keys could still double-pay; the grace period is configurable.
5. **Withdrawals are all-or-nothing** (the whole outstanding balance). Partial withdrawals would need an amount parameter validated against outstanding.
6. **Single currency, UTC day boundaries.**
7. **Mock provider state lives in the cache** (so all workers share it); clearing the cache "forgets" the mock bank.
8. **The admin panel is read-only**; there is no UI for refunds or manual payout retries (they are Artisan commands).
9. **Tests need MySQL/MariaDB** (row locks); there is no in-memory SQLite option.

## Bonus: changing plans mid-term

*Not built — design only.*

**Principle:** an upgrade is a pro-rata refund of the old subscription, paid out as **credit** instead of cash, plus a new subscription that consumes that credit. Nothing new is needed in the ledger.

Example: annual plan, EGP 2,999, upgraded to a hypothetical premium annual at day 120 of 365.

1. **Close the old subscription** with `RefundService` pro-rata on the upgrade day. Days 1–119 were served and stay earned by the instructors who taught them. The unserved value, `2,999 − earned(119)` ≈ EGP 2,021, becomes **credit**, not a cash refund. No clawback: those days were never allocated.
2. **Open the new subscription** starting today at the new plan's price. The student pays `new price − credit` (the top-up) at checkout. The new subscription's `amount_minor` is the full new price (credit + top-up), and it recognises day by day at the new daily rate with the same allocation code.
3. **Pricing choice to make with product:**
   - *Restart the term* (new 12 months from today) — simplest, what most platforms do.
   - *Keep the original end date* and charge only for the remaining days at the new rate: `new daily rate × remaining days − credit`.
4. **Downgrades** produce credit larger than the new price. Either keep it as account credit for renewals, or refund the difference in cash — a business decision; the ledger handles both.
5. **Schema additions:** `subscriptions.replaces_subscription_id` (audit trail), `subscriptions.credit_applied_minor`, and a `student_credits` table if credit can outlive a single upgrade.

Why this shape: instructors keep exactly what was earned under the old plan, the new plan's revenue is split by the new plan's courses, and the conservation check still holds per subscription: **platform + instructors + credit/refund = amount paid**.
