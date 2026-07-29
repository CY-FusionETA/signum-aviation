<?php
declare(strict_types=1);

namespace App\Service\Xero;

use App\Settings;

/** Live client when Xero is enabled + configured + connected, else the stub. */
final class XeroClientFactory
{
    public static function make(): XeroClientInterface
    {
        if (Settings::bool('xero.enabled') && XeroOAuth::isConfigured() && XeroOAuth::isConnected()) {
            return new XeroApiClient();
        }
        return new XeroStubClient();
    }
}
