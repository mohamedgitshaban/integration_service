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

];
