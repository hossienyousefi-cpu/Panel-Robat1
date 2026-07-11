<?php

declare(strict_types=1);

namespace App\IBSng;

use App\IBSng\Dto\CreateUserItem;
use App\IBSng\Dto\CreateUserResultItem;
use App\IBSng\Dto\OnlineSession;
use App\IBSng\Dto\UserStatus;
use App\Services\RuntimeSettings;
use RuntimeException;

/**
 * Talks to the IBSng Agent (see /ibsng-agent) over the local end of the SSH tunnel.
 * The tunnel makes the agent's 127.0.0.1-only HTTP API reachable at IBSNG_AGENT_URL
 * (typically http://127.0.0.1:9091) from this host, without ever exposing a port on
 * the IBSng server itself to anything but SSH. The URL/API key come from
 * RuntimeSettings (DB-backed, overridable from the admin panel or the admin Telegram
 * bot control panel) with .env as the initial default, so the connection can be
 * repointed without a redeploy if it ever breaks.
 */
final class HttpAgentGateway implements IBSngGatewayInterface
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeoutSeconds;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, int $timeoutSeconds = 20)
    {
        $settings = new RuntimeSettings();
        $this->baseUrl = rtrim($baseUrl ?? (string) $settings->get(RuntimeSettings::IBSNG_AGENT_URL, 'http://127.0.0.1:9091'), '/');
        $this->apiKey = $apiKey ?? (string) $settings->get(RuntimeSettings::IBSNG_AGENT_API_KEY, '');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function listGroups(): array
    {
        $response = $this->request('GET', '/groups');
        return $response['groups'] ?? [];
    }

    public function listIsps(): array
    {
        $response = $this->request('GET', '/isps');
        return $response['isps'] ?? [];
    }

    public function createUsers(array $items, string $group, string $isp, float $credit1, float $credit2): array
    {
        $payload = [
            'items' => array_map(fn (CreateUserItem $item) => $item->toArray(), $items),
            'group' => $group,
            'isp' => $isp,
            'credit1' => $credit1,
            'credit2' => $credit2,
        ];
        $response = $this->request('POST', '/users/create', $payload);
        $results = [];
        foreach ($response['results'] ?? [] as $row) {
            $results[] = CreateUserResultItem::fromArray($row);
        }
        return $results;
    }

    public function deleteUser(string $username, ?int $ibsngUserId = null): bool
    {
        $response = $this->request('POST', '/users/delete', ['username' => $username]);
        return (bool) ($response['ok'] ?? false);
    }

    public function renewUser(string $username, string $group, float $addCredit1, ?int $ibsngUserId = null): bool
    {
        $response = $this->request('POST', '/users/renew', [
            'username' => $username,
            'group' => $group,
            'add_credit1' => $addCredit1,
        ]);
        return (bool) ($response['ok'] ?? false);
    }

    public function lockUser(string $username, ?int $ibsngUserId = null): bool
    {
        $response = $this->request('POST', '/users/lock', ['username' => $username]);
        return (bool) ($response['ok'] ?? false);
    }

    public function unlockUser(string $username, ?int $ibsngUserId = null): bool
    {
        $response = $this->request('POST', '/users/unlock', ['username' => $username]);
        return (bool) ($response['ok'] ?? false);
    }

    public function getUserStatus(string $username, ?int $ibsngUserId = null): ?UserStatus
    {
        $response = $this->request('GET', '/users/search', ['username' => $username]);
        if (!($response['exists'] ?? false)) {
            return null;
        }
        return UserStatus::fromArray($response);
    }

    public function listOnlineSessions(): array
    {
        $response = $this->request('GET', '/sessions/online');
        $sessions = [];
        foreach ($response['sessions'] ?? [] as $row) {
            $sessions[] = OnlineSession::fromArray($row);
        }
        return $sessions;
    }

    public function healthCheck(): bool
    {
        try {
            $response = $this->request('GET', '/health');
            return (bool) ($response['ok'] ?? false);
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $url = $this->baseUrl . $path;
        if ($method === 'GET' && $payload !== []) {
            $url .= '?' . http_build_query($payload);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("IBSng Agent unreachable ({$path}): {$error}. آیا تونل SSH بین Host vaset و سرور IBSng برقرار است؟");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("پاسخ نامعتبر از IBSng Agent ({$path}), HTTP {$status}");
        }
        if ($status >= 400) {
            $message = $decoded['error'] ?? "خطای HTTP {$status} از IBSng Agent";
            throw new RuntimeException((string) $message);
        }

        return $decoded;
    }
}
