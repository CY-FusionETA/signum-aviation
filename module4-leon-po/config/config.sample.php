<?php
/**
 * Copy to config/config.php and fill in. config.php is git-ignored.
 * Xero client_id/secret can also be set at runtime in the web UI (Settings),
 * which is the recommended way to point the module at a new organisation.
 */
return [
    'app' => [
        'env'      => 'production',                 // 'local' shows real error messages
        'base_url' => 'http://localhost:8000',      // public URL, NO trailing slash
        'timezone' => 'Asia/Kuala_Lumpur',
        // Light gate for the web UI (OAuth + processing must not be public).
        // Generate: php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
        'admin_password_hash' => '',                 // empty = UI refuses to run
    ],

    'db' => [
        'path' => '',                                // empty = storage/signum_leon.sqlite
    ],

    'xero' => [
        // Leave these here as a fallback; the web Settings tab overrides them so
        // you can reconnect to a new org without editing files.
        'enabled'       => false,                    // set true (or connect in the UI) to use the live client
        'client_id'     => '',
        'client_secret' => '',
        // Must match the redirect URI registered in your Xero app EXACTLY.
        // If blank, defaults to <base_url>/xero/callback.
        'redirect_uri'  => '',
        'scopes'        => 'openid profile email accounting.transactions accounting.contacts accounting.settings offline_access',
    ],

    // Which trip currency to stamp on the PO, by source entity. Leave a value
    // empty to omit CurrencyCode entirely and let Xero use the org base currency
    // (safest for a fresh demo org that has no foreign currencies added yet).
    'currency' => [
        'inc' => '',   // e.g. 'USD' for Signum Aviation Inc once USD is enabled in Xero
        'ltd' => '',   // e.g. 'GBP' for Signum Aviation Ltd
    ],
];
