<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Domain\Repositories\SettingsRepository;

/**
 * Connection settings such as the IBSng Agent URL/API key can be changed at runtime
 * (from the admin web panel or the admin Telegram bot control panel) without editing
 * .env or redeploying - useful if the SSH tunnel/agent address needs to change and the
 * admin doesn't have shell access at that moment. A DB value always wins; .env is only
 * the initial default the first time a value is read before anyone has overridden it.
 */
final class RuntimeSettings
{
    public const IBSNG_AGENT_URL = 'IBSNG_AGENT_URL';
    public const IBSNG_AGENT_API_KEY = 'IBSNG_AGENT_API_KEY';

    /** 'direct' (talk straight to the IBSng admin panel over HTTPS, no install on that server) or 'agent' (legacy SSH-tunnel ibsng-agent). */
    public const IBSNG_CONNECTION_MODE = 'IBSNG_CONNECTION_MODE';
    public const IBSNG_ADMIN_BASE_URL = 'IBSNG_ADMIN_BASE_URL';
    public const IBSNG_ADMIN_USERNAME = 'IBSNG_ADMIN_USERNAME';
    public const IBSNG_ADMIN_PASSWORD = 'IBSNG_ADMIN_PASSWORD';
    public const IBSNG_ADMIN_VERIFY_SSL = 'IBSNG_ADMIN_VERIFY_SSL';

    public function __construct(private readonly SettingsRepository $repository = new SettingsRepository())
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $dbValue = $this->repository->get($key);
        if ($dbValue !== null && $dbValue !== '') {
            return $dbValue;
        }
        return Config::get($key, $default);
    }

    public function set(string $key, string $value): void
    {
        $this->repository->set($key, $value);
    }

    /** True if this key has been overridden in the DB (as opposed to still using the .env default). */
    public function isOverridden(string $key): bool
    {
        $value = $this->repository->get($key);
        return $value !== null && $value !== '';
    }
}
