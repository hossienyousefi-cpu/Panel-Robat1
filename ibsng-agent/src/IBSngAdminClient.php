<?php

declare(strict_types=1);

namespace App;

/**
 * Automates the IBSng admin web panel (admin/*.php) by replaying the same HTTP
 * requests a browser sends when you click through the UI by hand. Every URL, form
 * field name and success/error marker is read from config.php - see
 * config.php.example and ../docs/IBSNG_INTEGRATION.md for how to fill them in for
 * your actual install. Nothing here talks to IBSng's database directly.
 */
final class IBSngAdminClient
{
    private array $cfg;
    private bool $loggedIn = false;

    public function __construct(?array $config = null)
    {
        $this->cfg = ($config ?? Config::load())['ibsng'];
        @unlink((string) Config::get('cookie_jar', '/tmp/ibsng-agent-cookies.txt'));
    }

    public function login(): bool
    {
        $login = $this->cfg['login'];
        $fields = $login['extra_fields'] ?? [];
        $fields[$login['username_field']] = $login['username'];
        $fields[$login['password_field']] = $login['password'];

        $html = $this->post($login['url'], $fields);
        $this->loggedIn = str_contains($html, (string) $login['success_marker']);

        return $this->loggedIn;
    }

    private function ensureLoggedIn(): void
    {
        if (!$this->loggedIn && !$this->login()) {
            throw new \RuntimeException('IBSng admin login failed - check credentials/field names in config.php.');
        }
    }

    /**
     * @param array<int,array{username:string,password:string}> $items
     * @return array<int,array{username:string,ok:bool,message:?string}>
     */
    public function createUsers(array $items, string $group, string $isp, float $credit1, float $credit2): array
    {
        $this->ensureLoggedIn();
        $add = $this->cfg['add_user'];
        $map = $add['field_map'];

        $results = [];
        foreach ($items as $item) {
            $fields = [
                $map['username'] => $item['username'],
                $map['password'] => $item['password'],
                $map['group'] => $group,
                $map['isp'] => $isp,
                $map['credit1'] => (string) $credit1,
                $map['credit2'] => (string) $credit2,
            ];
            if (!empty($add['count_field'])) {
                $fields[$add['count_field']] = '1';
            }

            $html = $this->post($add['url'], $fields);

            $ok = str_contains($html, (string) $add['success_marker'])
                && !str_contains($html, (string) $add['error_marker']);

            $results[] = [
                'username' => $item['username'],
                'ok' => $ok,
                'message' => $ok ? null : 'IBSng did not confirm creation - check add_user markers/field_map in config.php',
            ];
        }

        return $results;
    }

    public function deleteUser(string $username): bool
    {
        $this->ensureLoggedIn();
        $userId = $this->resolveUserId($username);
        if ($userId === null) {
            return false;
        }

        $del = $this->cfg['delete_user'];
        $html = $this->post($del['url'], [$del['id_field'] => $userId]);

        return str_contains($html, (string) $del['success_marker']);
    }

    public function lockUser(string $username): bool
    {
        return $this->setLocked($username, true);
    }

    public function unlockUser(string $username): bool
    {
        return $this->setLocked($username, false);
    }

    private function setLocked(string $username, bool $locked): bool
    {
        $this->ensureLoggedIn();
        $userId = $this->resolveUserId($username);
        if ($userId === null) {
            return false;
        }

        $update = $this->cfg['update_user'];
        $editPageHtml = $this->fetchEditPage($userId);
        $fields = $this->extractHiddenFields($editPageHtml);
        $fields[$update['id_field']] = $userId;
        $fields[$update['locked_field']] = $locked ? $update['locked_on_value'] : $update['locked_off_value'];

        $html = $this->post($update['url'], $fields);

        return str_contains($html, (string) $update['success_marker']);
    }

    public function renewUser(string $username, string $group, float $addCredit1): bool
    {
        $this->ensureLoggedIn();
        $userId = $this->resolveUserId($username);
        if ($userId === null) {
            return false;
        }

        $update = $this->cfg['update_user'];
        $editPageHtml = $this->fetchEditPage($userId);
        $fields = $this->extractHiddenFields($editPageHtml);
        $fields[$update['id_field']] = $userId;

        $currentCredit1 = $this->extractByPattern($editPageHtml, $this->cfg['user_edit_page']['credit1_pattern'] ?? null);
        $newCredit1 = (float) ($currentCredit1 ?? 0) + $addCredit1;
        $fields[$update['credit1_field']] = (string) $newCredit1;

        $html = $this->post($update['url'], $fields);

        return str_contains($html, (string) $update['success_marker']);
    }

    /** @return array{exists:bool,group:?string,isp:?string,credit1:?float,credit2:?float,is_locked:bool,expires_at:?string} */
    public function searchUser(string $username): array
    {
        $this->ensureLoggedIn();
        $userId = $this->resolveUserId($username);
        if ($userId === null) {
            return ['exists' => false, 'group' => null, 'isp' => null, 'credit1' => null, 'credit2' => null, 'is_locked' => false, 'expires_at' => null];
        }

        $html = $this->fetchEditPage($userId);
        $page = $this->cfg['user_edit_page'];

        return [
            'exists' => true,
            'group' => $this->extractByPattern($html, $page['group_pattern'] ?? null),
            'isp' => $this->extractByPattern($html, $page['isp_pattern'] ?? null),
            'credit1' => ($v = $this->extractByPattern($html, $page['credit1_pattern'] ?? null)) !== null ? (float) $v : null,
            'credit2' => ($v = $this->extractByPattern($html, $page['credit2_pattern'] ?? null)) !== null ? (float) $v : null,
            'is_locked' => $this->extractByPattern($html, $page['locked_pattern'] ?? null) !== null,
            'expires_at' => $this->extractByPattern($html, $page['expire_pattern'] ?? null),
        ];
    }

    /** @return array<int,array{name:string,description:?string}> */
    public function listGroups(): array
    {
        $this->ensureLoggedIn();
        $cfg = $this->cfg['groups_list'];
        $html = $this->get($cfg['url']);
        $options = $this->extractSelectOptions($html, $cfg['select_name']);

        return array_map(fn ($o) => ['name' => $o['label'], 'description' => null], $options);
    }

    /** @return array<int,array{name:string}> */
    public function listIsps(): array
    {
        $this->ensureLoggedIn();
        $cfg = $this->cfg['isps_list'];
        $html = $this->get($cfg['url']);
        $options = $this->extractSelectOptions($html, $cfg['select_name']);

        return array_map(fn ($o) => ['name' => $o['label']], $options);
    }

    private function resolveUserId(string $username): ?string
    {
        $search = $this->cfg['search_user'];
        $url = $search['url'] . '?' . http_build_query([$search['query_field'] => $username]);
        $html = $this->get($url);

        if (preg_match($search['user_id_pattern'], $html, $m)) {
            return $m['id'] ?? ($m[1] ?? null);
        }

        return null;
    }

    private function fetchEditPage(string $userId): string
    {
        $page = $this->cfg['user_edit_page'];
        $url = $page['url'] . '?' . http_build_query([$page['id_field'] => $userId]);
        return $this->get($url);
    }

    private function extractByPattern(string $html, ?string $pattern): ?string
    {
        if ($pattern === null) {
            return null;
        }
        if (preg_match($pattern, $html, $m)) {
            return trim($m['value'] ?? ($m[1] ?? ''));
        }
        return null;
    }

    /** Generic structural parser - finds every <input type="hidden" name="..." value="..."> on a page. */
    private function extractHiddenFields(string $html): array
    {
        $fields = [];
        if (preg_match_all('/<input[^>]+type=["\']hidden["\'][^>]*>/i', $html, $inputs)) {
            foreach ($inputs[0] as $inputTag) {
                if (preg_match('/name=["\']([^"\']+)["\']/i', $inputTag, $nameMatch)
                    && preg_match('/value=["\']([^"\']*)["\']/i', $inputTag, $valueMatch)) {
                    $fields[$nameMatch[1]] = html_entity_decode($valueMatch[1]);
                }
            }
        }
        return $fields;
    }

    /** Generic structural parser - reads <option value="..">label</option> out of a named <select>. */
    private function extractSelectOptions(string $html, string $selectName): array
    {
        if (!preg_match('/<select[^>]+name=["\']' . preg_quote($selectName, '/') . '["\'][^>]*>(.*?)<\/select>/is', $html, $selectMatch)) {
            return [];
        }

        $options = [];
        if (preg_match_all('/<option[^>]*value=["\']([^"\']*)["\'][^>]*>([^<]*)<\/option>/i', $selectMatch[1], $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $value = trim($m[1]);
                if ($value === '') {
                    continue;
                }
                $options[] = ['value' => $value, 'label' => trim(html_entity_decode($m[2]))];
            }
        }

        return $options;
    }

    private function get(string $url): string
    {
        return $this->request('GET', $url);
    }

    private function post(string $url, array $fields): string
    {
        return $this->request('POST', $url, $fields);
    }

    private function request(string $method, string $url, array $fields = []): string
    {
        $cookieJar = (string) Config::get('cookie_jar', '/tmp/ibsng-agent-cookies.txt');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("Could not reach IBSng admin panel ({$url}): {$error}");
        }

        return (string) $body;
    }
}
