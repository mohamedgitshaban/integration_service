# AI Usage

Sections marked **✍️ TO WRITE** are about my own reasoning and must be written by me before submission. Everything else is a factual record of how the work was done.

## Tools

- **Claude Code (Anthropic)**, running in VS Code with access to the repository, terminal, test database, and Laravel Boost for version-matched Laravel/Filament documentation.
- **Laravel generators** (`make:model`, `make:migration`, `make:test`, etc.) for scaffolding.

## Workflow

The project was developed incrementally, starting with my existing implementation. Claude Code reviewed the code against the project brief, performed a gap analysis, identified code-quality and correctness issues, and helped bring the implementation up to the required standard.

Each step followed this loop:

1. **Review and gap analysis.** Claude Code reviewed the existing implementation against the requirements, identified missing functionality, incorrect behavior, architectural weaknesses, and potential financial-integrity issues.
2. **Proposal and decision.** The AI proposed implementation approaches, explaining relevant design choices and trade-offs. I reviewed the proposals and approved, modified, or redirected them.
3. **Implementation and tests.** The AI implemented the agreed changes and added or updated tests alongside the code. The full test suite was run after each step, reaching 104 tests in the final version.
4. **Mutation checks.** For critical guarantees, the implementation was deliberately modified to remove safety mechanisms—for example, removing the atomic payout claim, resending payouts with unknown outcomes, removing the grace period, skipping the payout destination snapshot, or disabling password hashing. The corresponding tests failed as expected, and the original implementation was restored.
5. **Review and commit.** Changes were reviewed and committed in separate steps so the Git history documents the implementation's evolution.

## Main prompts

The prompts were concise, with most of the detailed design discussion taking place through the AI's proposals and my responses. Representative prompts, in order:

- **Initial review and gap analysis:**
  > I made this project for this task — read the full project, fix the gap analysis, and review the quality of code.

- **Seed data and test accounts:**
  > I need a seeder for every model and accounts in the factory so I can use them.

The initial review led to changes across the existing implementation, including the revenue allocation logic, monetary precision, payout processing, idempotency, concurrency protection, and test coverage.

## What was generated vs. designed or modified by me

**✍️ TO WRITE.** Be specific and honest about your original contribution and how you used AI.

Useful facts for this section:

- My initial implementation already contained models for subscriptions, courses, earnings, balances, payout batches, and transactions; a `RevenueAllocatorService` that used equal allocation and recognized all revenue on the payment date; a `PayoutService`; a `MockPaymentGateway`; and the `payouts:run` command.
- During the review, Claude Code performed the gap analysis, identified defects and design weaknesses, and implemented most of the subsequent code changes and tests.
- The revenue allocation logic was revised to support daily revenue recognition, cumulative rounding, a snapshotted platform share, and largest-remainder allocation based on course weights.
- The payout workflow was revised to address transaction boundaries, uncertain provider outcomes, duplicate transfers, concurrency, and payout destination consistency.
- In this session, the AI wrote most of the implementation changes and tests. I reviewed the proposed designs, made decisions about the requirements and trade-offs, and used the test suite and mutation checks to verify the resulting behavior.

**✍️ State explicitly how you wrote your initial implementation:** by hand, with AI assistance, or through a combination of both.

## Problems identified during the initial code review

The initial gap analysis and code-quality review identified the following issues. These findings informed the subsequent design and implementation changes.

1. **Monetary precision:** Money was stored as `decimal` in the database but handled using PHP `float`, introducing potential precision errors in financial calculations.
2. **External provider calls inside database transactions:** The payment provider was called while a database transaction was open. When a timeout occurred, the code wrote `status = 'timeout'` and re-threw the exception, causing the transaction to roll back the status update. A subsequent run could therefore attempt the same payment again, even if the provider had already transferred the money.
3. **Unsafe retries for uncertain payouts:** Only payouts with `success` status were skipped. Payouts with `timeout` or `pending` statuses could be submitted again despite the possibility that the original transfer had succeeded.
4. **Missing atomic payout claims:** There was no atomic mechanism to claim a payout before processing it. Two concurrent workers could therefore process and send the same payout.
5. **Excessive provider transfers:** The original approach created one transfer per earning row instead of aggregating the earnings into one payout per instructor, potentially producing an impractical number of provider transfers at scale.
6. **Non-idempotent revenue allocation:** Running the allocation process more than once could duplicate instructor earnings because the operation did not reliably prevent an allocation from being applied twice.
7. **Allocation and financial-model limitations:** The original allocation logic split revenue equally and recognized the full subscription revenue on the payment date. It did not implement daily recognition with cumulative flooring or weighted allocation based on the student's courses.

## Issues caught during implementation and testing

- **Passwords were not hashed by default.** The `User` model had no `casts()` definition for password hashing. The factories masked this issue by hashing passwords explicitly. It was discovered when adding the registration endpoint and is now covered by a test.
- **Payout destination was not snapshotted.** Payout processing read the instructor's current bank details at send time. Changing those details after a payout was reserved could redirect the transfer. The destination is now snapshotted on the payout record.
- **`WithoutModelEvents` disabled important model hooks.** Its use in the default `DatabaseSeeder` would have disabled model hooks relied upon by the ledger, including the append-only guard and platform-share snapshot. It was removed.
- **A flaky chaos test generated invalid test data.** The test occasionally failed because it generated a zero-amount earning, which the ledger correctly rejects. After correcting the test data generation, the test was run 60 times without failure.
- **SQLite was unavailable and insufficient for concurrency verification.** The environment did not have the SQLite driver, and the concurrency guarantees depend on real database row locks. Tests therefore run against a dedicated MySQL database.
- **Duplicate Filament panels existed.** A duplicated installation had created two Filament panels, one with an ID entered using the Arabic keyboard layout. These were consolidated into a single `admin` panel.

## Engineering decisions I made

**✍️ TO WRITE.** Explain the decisions you personally made or approved. For each, describe the selected approach, an alternative, and the reason for the decision.

Potential topics from this implementation:

- Daily revenue recognition rather than recognizing all subscription revenue on the payment date.
- Weighted allocation based on course participation rather than equal splitting or watch-time-based allocation.
- Largest-remainder rounding to distribute monetary fractions while preserving the total amount allocated.
- Pro-rata refunds by default, full refunds as an admin-only decision, and negative instructor balances rather than attempting to recover funds immediately.
- Treating uncertain payout outcomes as unknown and avoiding automatic resubmission; accepting a definitive "not found" result only after a grace period.
- Using an append-only ledger with cached balances instead of relying exclusively on mutable balance counters.
- Developing incrementally, with tests and mutation checks for critical financial guarantees.
- Adding the API, withdrawals, and seed data while keeping the scope manageable; documenting functionality and scaling concerns that remain outside the implementation.

## AI suggestions I rejected or changed

**✍️ TO WRITE.** List specific cases where you questioned, rejected, or redirected an AI proposal and explain why.

If there were few or no rejected suggestions, state that honestly. Explain how you evaluated the suggestions you accepted—for example, by reviewing the code, checking the Laravel documentation, running the full test suite, and using mutation checks to verify that critical tests detect broken safeguards.

## What differentiates this solution

**✍️ TO WRITE in your own words.** Describe the characteristics you consider important and explain how you verified them.

The implementation provides several concrete properties you can discuss:

- **Financial conservation checks:** Refund tests verify that the platform's retained amount, instructor earnings, and refund amount reconcile with the amount originally paid. The chaos test compares the money actually transferred by the provider with the corresponding ledger records.
- **Mutation-tested safeguards:** Critical protections were deliberately removed to confirm that the corresponding tests detect the resulting failures.
- **Safe handling of uncertain payouts:** Timeouts are treated as unknown outcomes, with a grace period for delayed provider confirmations, rather than as automatic failures that can be retried immediately.
- **Rebuildable balances:** Cached balances can be rebuilt and checked against the underlying source records.
- **Idempotent allocation:** Repeated allocation runs are designed to avoid creating duplicate instructor earnings.
- **Aggregated payouts:** Instructor earnings are combined into payouts rather than creating a separate provider transfer for every earning row.

## Trade-offs and improvements I chose

**✍️ TO WRITE.** Summarize the trade-offs you accepted, the limitations you identified, and the improvements you would prioritize next.

You can refer to [ARCHITECTURE.md — Known limitations](ARCHITECTURE.md#known-limitations) and [ARCHITECTURE.md — Scaling considerations](ARCHITECTURE.md#scaling-considerations) for the documented limitations and future improvements.