<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\IBSngAdminClient;
use App\RadAcctReader;
use App\Security\ApiKeyAuth;

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/health') {
    // Deliberately no auth on /health beyond reachability, so DEPLOYMENT.md's tunnel
    // smoke-test can run before the API key is even configured on both sides. Every
    // other endpoint requires a valid key.
    respond(200, ['ok' => true]);
}

if (!ApiKeyAuth::check()) {
    respond(401, ['error' => 'invalid or missing X-Api-Key']);
}

$body = [];
if ($method !== 'GET') {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    $body = is_array($decoded) ? $decoded : [];
}

try {
    $client = new IBSngAdminClient();

    switch (true) {
        case $path === '/groups' && $method === 'GET':
            respond(200, ['groups' => $client->listGroups()]);
            break;

        case $path === '/isps' && $method === 'GET':
            respond(200, ['isps' => $client->listIsps()]);
            break;

        case $path === '/users/create' && $method === 'POST':
            $results = $client->createUsers(
                $body['items'] ?? [],
                (string) ($body['group'] ?? ''),
                (string) ($body['isp'] ?? ''),
                (float) ($body['credit1'] ?? 0),
                (float) ($body['credit2'] ?? 0)
            );
            respond(200, ['results' => $results]);
            break;

        case $path === '/users/delete' && $method === 'POST':
            $ok = $client->deleteUser((string) ($body['username'] ?? ''));
            respond(200, ['ok' => $ok]);
            break;

        case $path === '/users/renew' && $method === 'POST':
            $ok = $client->renewUser(
                (string) ($body['username'] ?? ''),
                (string) ($body['group'] ?? ''),
                (float) ($body['add_credit1'] ?? 0)
            );
            respond(200, ['ok' => $ok]);
            break;

        case $path === '/users/lock' && $method === 'POST':
            $ok = $client->lockUser((string) ($body['username'] ?? ''));
            respond(200, ['ok' => $ok]);
            break;

        case $path === '/users/unlock' && $method === 'POST':
            $ok = $client->unlockUser((string) ($body['username'] ?? ''));
            respond(200, ['ok' => $ok]);
            break;

        case $path === '/users/search' && $method === 'GET':
            $username = (string) ($_GET['username'] ?? '');
            $status = $client->searchUser($username);
            respond(200, array_merge(['username' => $username], $status));
            break;

        case $path === '/sessions/online' && $method === 'GET':
            $sessions = (new RadAcctReader())->onlineSessions();
            respond(200, ['sessions' => $sessions]);
            break;

        default:
            respond(404, ['error' => 'not found']);
    }
} catch (\Throwable $e) {
    error_log('[ibsng-agent] ' . $e->getMessage());
    respond(500, ['error' => $e->getMessage()]);
}
