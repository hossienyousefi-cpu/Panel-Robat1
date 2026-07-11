<?php

declare(strict_types=1);

namespace App\IBSng;

use App\Services\RuntimeSettings;

/**
 * Single place that decides which IBSngGatewayInterface implementation the app talks
 * to, so it can be switched at runtime (from the admin panel, no redeploy needed)
 * between DirectHttpGateway (straight HTTPS to the IBSng admin panel, nothing
 * installed on that server) and the legacy HttpAgentGateway (SSH-tunneled
 * ibsng-agent). Direct is the default per the current integration approach.
 */
final class IBSngGatewayFactory
{
    public static function create(): IBSngGatewayInterface
    {
        $settings = new RuntimeSettings();
        $mode = (string) $settings->get(RuntimeSettings::IBSNG_CONNECTION_MODE, 'direct');

        return $mode === 'agent'
            ? new HttpAgentGateway()
            : new DirectHttpGateway($settings);
    }
}
