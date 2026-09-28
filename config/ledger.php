<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | All amounts are stored as integers in the currency's minor unit
    | (piastres for EGP). The system is single-currency by design.
    |
    */

    'currency' => env('LEDGER_CURRENCY', 'EGP'),

    /*
    |--------------------------------------------------------------------------
    | Platform Share
    |--------------------------------------------------------------------------
    |
    | The platform's cut of subscription revenue in basis points (3000 = 30%).
    | It is copied onto each subscription at purchase; changing it here only
    | affects subscriptions bought afterwards.
    |
    */

    'platform_share_bps' => (int) env('LEDGER_PLATFORM_SHARE_BPS', 3000),

    /*
    |--------------------------------------------------------------------------
    | Payouts
    |--------------------------------------------------------------------------
    |
    | minimum_payout_minor: balances below this roll over to the next run, so
    | the platform does not pay transfer fees on trivial amounts.
    |
    | stale_processing_minutes: a payout still "processing" after this long
    | means the worker died mid-call; it is treated as outcome-unknown.
    |
    | not_found_grace_minutes: a provider may take time to show a transfer.
    | "Not found" only counts as failed once the payout is older than this.
    |
    */

    'minimum_payout_minor' => (int) env('LEDGER_MINIMUM_PAYOUT_MINOR', 10_000),

    'stale_processing_minutes' => (int) env('LEDGER_STALE_PROCESSING_MINUTES', 15),

    'not_found_grace_minutes' => (int) env('LEDGER_NOT_FOUND_GRACE_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Mock Payment Provider
    |--------------------------------------------------------------------------
    |
    | Relative weights of each simulated outcome, and how long a success
    | that timed out stays invisible to status checks.
    |
    */

    'mock_provider' => [
        'weights' => [
            'success' => (int) env('MOCK_PROVIDER_SUCCESS_WEIGHT', 60),
            'permanent_failure' => (int) env('MOCK_PROVIDER_FAILURE_WEIGHT', 15),
            'timeout_after_success' => (int) env('MOCK_PROVIDER_TIMEOUT_AFTER_SUCCESS_WEIGHT', 15),
            'timeout_before_success' => (int) env('MOCK_PROVIDER_TIMEOUT_BEFORE_SUCCESS_WEIGHT', 10),
        ],
        'confirmation_delay_seconds' => (int) env('MOCK_PROVIDER_CONFIRMATION_DELAY_SECONDS', 120),
    ],

];
