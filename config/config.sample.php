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
        // Admin login (email + password). Preferred: set these at runtime with
        //   php cli/set-admin.php <email> <password>   (stored in app_settings).
        // These config values are a fallback if app_settings has none.
        'admin_email'         => '',                 // e.g. simon@fusioneta.com
        'admin_password_hash' => '',                 // php -r "echo password_hash('pw', PASSWORD_DEFAULT);"
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
        // Purchase orders are reached via accounting.invoices — accounting.transactions
        // is not granted by these Xero apps and makes the consent screen fail to load.
        'scopes'        => 'openid profile email accounting.invoices accounting.contacts accounting.settings accounting.attachments offline_access',
    ],

    // Which trip currency to stamp on the PO, by source entity. Leave a value
    // empty to omit CurrencyCode entirely and let Xero use the org base currency
    // (safest for a fresh demo org that has no foreign currencies added yet).
    'currency' => [
        'inc' => '',   // e.g. 'USD' for Signum Aviation Inc once USD is enabled in Xero
        'ltd' => '',   // e.g. 'GBP' for Signum Aviation Ltd
    ],

    // Module 5 client-invoice rules (also editable in the Settings UI).
    'invoice' => [
        'markup'       => 1.02,   // recharge each supplier cost × this (FX/margin buffer)
        'admin_pct'    => 11,     // admin charge as a % of the recharge subtotal
        'support_fee'  => 0,      // flat trip-support fee per invoice (0 = none)
        'account_code' => '',     // Xero revenue account code for the lines (e.g. '200')
    ],

    // File-drop endpoint: the Gmail intake script POSTs each attachment to
    // <base_url>/drop and gets back a short-lived public URL, which it hands to
    // Wazzup to send to the OCR service over WhatsApp. Set a long random shared key here
    // and the SAME value as DROP_KEY in the Apps Script. Empty = endpoint disabled.
    // Inbox (Module 1 execution log). The Gmail intake script POSTs each send to
    // <base_url>/inbox/log, and Wazzup POSTs the processor's WhatsApp replies to
    // <base_url>/wazzup/webhook — both authenticated with drop.key above. Replies
    // are recognised by their content (the bill result), not by phone number.
    'drop' => [
        'key' => '',   // e.g. bin2hex(random_bytes(24))
    ],
];
