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
 * Talks directly to IBSng's own admin web panel (admin/*.php) over HTTPS, replaying
 * the same HTTP requests a browser sends when clicking through the UI by hand -
 * nothing is installed or run on the IBSng server itself. Every URL and form field
 * name below was taken from real "View Page Source" / Inspect Element captures of
 * the live panel, not guessed. Methods that don't have a confirmed capture yet throw
 * a clear exception naming what's still needed, rather than silently doing the wrong
 * thing with a guessed field name.
 */
final class DirectHttpGateway implements IBSngGatewayInterface
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private bool $verifySsl;
    private string $cookieJar;
    private bool $loggedIn = false;

    public function __construct(?RuntimeSettings $settings = null)
    {
        $settings ??= new RuntimeSettings();
        $this->baseUrl = rtrim((string) $settings->get(RuntimeSettings::IBSNG_ADMIN_BASE_URL, 'https://194.59.214.84/IBSng/admin'), '/');
        $this->username = (string) $settings->get(RuntimeSettings::IBSNG_ADMIN_USERNAME, '');
        $this->password = (string) $settings->get(RuntimeSettings::IBSNG_ADMIN_PASSWORD, '');
        $this->verifySsl = ((string) $settings->get(RuntimeSettings::IBSNG_ADMIN_VERIFY_SSL, 'false')) === 'true';

        $dir = dirname(__DIR__, 2) . '/storage/ibsng';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $this->cookieJar = $dir . '/cookies_' . bin2hex(random_bytes(8)) . '.txt';
    }

    public function __destruct()
    {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    public function login(): bool
    {
        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('اطلاعات ورود IBSng (IBSNG_ADMIN_USERNAME/IBSNG_ADMIN_PASSWORD) تنظیم نشده است.');
        }

        $html = $this->post($this->baseUrl . '/', [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        // Every logged-in admin page carries this logout link in its header; the
        // login form re-appears verbatim (same markup, no such link) on failure.
        $this->loggedIn = str_contains($html, '?logout=1');

        return $this->loggedIn;
    }

    private function ensureLoggedIn(): void
    {
        if (!$this->loggedIn && !$this->login()) {
            throw new RuntimeException('ورود به پنل مدیریت IBSng ناموفق بود - نام کاربری/رمز عبور را بررسی کنید.');
        }
    }

    public function listGroups(): array
    {
        $options = $this->fetchAddUserOptions('group_name');
        return array_map(fn (array $o) => ['name' => $o['value'], 'description' => null], $options);
    }

    public function listIsps(): array
    {
        $options = $this->fetchAddUserOptions('isp_name');
        return array_map(fn (array $o) => ['name' => $o['value']], $options);
    }

    /** @return array<int,array{value:string,label:string}> */
    private function fetchAddUserOptions(string $selectName): array
    {
        $this->ensureLoggedIn();
        $html = $this->get($this->baseUrl . '/user/add_new_users.php');
        return $this->extractSelectOptions($html, $selectName);
    }

    public function createUsers(array $items, string $group, string $isp, float $credit1, float $credit2): array
    {
        $this->ensureLoggedIn();
        $url = $this->baseUrl . '/user/add_new_users.php';

        $results = [];
        foreach ($items as $item) {
            $fields = [
                'submit_form' => '1',
                'add' => '1',
                'interface_memento' => '1',
                'count' => '1',
                'group_name' => $group,
                'credit1' => $this->formatNumber($credit1),
                'credit2' => $this->formatNumber($credit2),
                'isp_name' => $isp,
                'edit__normal_username' => 'normal_username',
                'normal_username' => $item->username,
                'normal_password' => $item->password,
            ];

            $html = $this->post($url, $fields);
            $hidden = $this->extractHiddenFields($html);
            $ibsngUserId = isset($hidden['user_id']) && ctype_digit((string) $hidden['user_id']) ? (int) $hidden['user_id'] : null;

            $results[] = new CreateUserResultItem(
                $item->username,
                $ibsngUserId !== null,
                $ibsngUserId !== null ? null : 'IBSng شناسه کاربر جدید را برنگرداند - ممکن است یوزرنیم تکراری باشد یا گروه/ISP نامعتبر باشد.',
                $ibsngUserId,
            );
        }

        return $results;
    }

    /**
     * Confirmed via a real capture: the sidebar "Delete User" link is a plain GET to
     * del_user.php?user_id=&user_repr= and that single request deletes the user
     * immediately - the response page opens with "User(s) Deleted Successfully",
     * no separate confirmation step is involved.
     */
    public function deleteUser(string $username, ?int $ibsngUserId = null): bool
    {
        $this->ensureLoggedIn();
        if ($ibsngUserId === null) {
            throw new RuntimeException('برای این کاربر شناسه عددی IBSng ثبت نشده - امکان حذف از این طریق نیست.');
        }

        $url = $this->baseUrl . '/user/del_user.php?' . http_build_query([
            'user_id' => $ibsngUserId,
            'user_repr' => $username,
        ]);
        $html = $this->get($url);

        return str_contains($html, 'Deleted Successfully');
    }

    /**
     * Confirmed with the account owner: renewal on this install means resetting the
     * user's "Package First Login" date (attr_edit_checkbox_30, value=first_login),
     * so the group's relative expiration recalculates from the next login - not
     * topping up Credit1. Posted the same way as the lock toggle: the checkbox
     * (reset_first_login=t) is only present in the static HTML after IBSng's JS
     * reveals it, but the underlying submit is a single POST like any other
     * attr_edit_checkbox field, so no separate page fetch is needed. $addCredit1 is
     * unused here - this integration has no evidence any group is credit-based for
     * renewal, so it does not guess a second code path.
     */
    public function renewUser(string $username, string $group, float $addCredit1, ?int $ibsngUserId = null): bool
    {
        $this->ensureLoggedIn();
        if ($ibsngUserId === null) {
            throw new RuntimeException('برای این کاربر شناسه عددی IBSng ثبت نشده - امکان تمدید از این طریق نیست.');
        }

        $fields = [
            'user_id' => (string) $ibsngUserId,
            'user_repr' => $username,
            'edit_user' => '1',
            'attr_edit_checkbox_30' => 'first_login',
            'reset_first_login' => 't',
        ];

        $html = $this->post($this->baseUrl . '/plugins/edit.php', $fields);

        // No distinct before/after text marks a successful reset (the field reads
        // "---------------" both before the first login and immediately after a
        // reset, until the user actually logs in again), so this only confirms the
        // POST was accepted while still authenticated - not that the value changed.
        return str_contains($html, '?logout=1');
    }

    public function lockUser(string $username, ?int $ibsngUserId = null): bool
    {
        return $this->setLocked($username, true, $ibsngUserId);
    }

    public function unlockUser(string $username, ?int $ibsngUserId = null): bool
    {
        return $this->setLocked($username, false, $ibsngUserId);
    }

    /**
     * Confirmed via a real captured edit page: the "select attribute to edit" checkbox
     * (attr_edit_checkbox_2, value=lock) plus the actual toggle (has_lock=t, sent only
     * when locking - an unchecked HTML checkbox is simply omitted) posted together to
     * plugins/edit.php is enough; no separate page fetch is needed since user_id/
     * user_repr/edit_user are just fixed hidden fields, not page-specific tokens.
     */
    private function setLocked(string $username, bool $locked, ?int $ibsngUserId): bool
    {
        $this->ensureLoggedIn();
        if ($ibsngUserId === null) {
            throw new RuntimeException('برای این کاربر شناسه عددی IBSng ثبت نشده (احتمالاً قبل از فعال‌سازی اتصال مستقیم ساخته شده) - امکان قفل/بازکردن قفل از این طریق نیست.');
        }

        $fields = [
            'user_id' => (string) $ibsngUserId,
            'user_repr' => $username,
            'edit_user' => '1',
            'attr_edit_checkbox_2' => 'lock',
        ];
        if ($locked) {
            $fields['has_lock'] = 't';
        }

        $html = $this->post($this->baseUrl . '/plugins/edit.php', $fields);
        $status = $this->parseLockStatus($html);

        return $status === $locked;
    }

    private function parseLockStatus(string $html): ?bool
    {
        if (preg_match('/User is Locked\s*:<\/td>.*?Form_Content_Row_Right_textarea_td_\w+">\s*(Yes|No)/is', $html, $m)) {
            return strtolower($m[1]) === 'yes';
        }
        return null;
    }

    public function getUserStatus(string $username, ?int $ibsngUserId = null): ?UserStatus
    {
        $this->ensureLoggedIn();
        if ($ibsngUserId === null) {
            throw new RuntimeException('برای این کاربر شناسه عددی IBSng ثبت نشده - امکان استعلام وضعیت از این طریق نیست.');
        }

        $url = $this->baseUrl . '/user/user_info.php?' . http_build_query([
            'user_id_multi' => $ibsngUserId,
        ]);
        $html = $this->get($url);

        $credits = [];
        if (preg_match_all('/<span id="credit">([\-\d.]+)<\/span>/i', $html, $m)) {
            $credits = $m[1];
        }
        $group = null;
        if (preg_match('/id="group_name"[^>]*>([^<]+)<\/a>/i', $html, $m)) {
            $group = trim($m[1]);
        }
        $isp = null;
        if (preg_match('/Owner ISP\s*:<\/td>\s*<td class="[^"]*"\s*>([^<]*)<\/td>/is', $html, $m)) {
            $isp = trim($m[1]);
        }
        $locked = $this->parseLockStatus($html) ?? false;

        return new UserStatus(
            $username,
            true,
            $group,
            $isp,
            isset($credits[0]) ? (float) $credits[0] : null,
            isset($credits[1]) ? (float) $credits[1] : null,
            $locked,
            null,
        );
    }

    public function listOnlineSessions(): array
    {
        throw new RuntimeException('لیست کاربران آنلاین هنوز از طریق اتصال مستقیم پیاده‌سازی نشده.');
    }

    public function healthCheck(): bool
    {
        try {
            return $this->login();
        } catch (RuntimeException) {
            return false;
        }
    }

    private function formatNumber(float $value): string
    {
        return $value === floor($value) ? (string) (int) $value : rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');
    }

    /** Reads name="value" (quoted, single-quoted, or bare) off a raw HTML tag string. */
    private function extractAttr(string $tag, string $attr): ?string
    {
        $attr = preg_quote($attr, '/');
        if (preg_match('/\b' . $attr . '\s*=\s*"([^"]*)"/i', $tag, $m)) {
            return $m[1];
        }
        if (preg_match('/\b' . $attr . "\\s*=\\s*'([^']*)'/i", $tag, $m)) {
            return $m[1];
        }
        if (preg_match('/\b' . $attr . '\s*=\s*([^\s"\'>]+)/i', $tag, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Every <input type=hidden ...> on a page, name => value (handles both quoted and bare attribute values). */
    private function extractHiddenFields(string $html): array
    {
        $fields = [];
        if (preg_match_all('/<input\b[^>]*>/i', $html, $inputs)) {
            foreach ($inputs[0] as $tag) {
                if (strtolower((string) $this->extractAttr($tag, 'type')) !== 'hidden') {
                    continue;
                }
                $name = $this->extractAttr($tag, 'name');
                if ($name === null) {
                    continue;
                }
                $fields[$name] = html_entity_decode((string) $this->extractAttr($tag, 'value'));
            }
        }
        return $fields;
    }

    /** @return array<int,array{value:string,label:string}> */
    private function extractSelectOptions(string $html, string $selectName): array
    {
        if (!preg_match('/<select\b[^>]*\bname\s*=\s*["\']?' . preg_quote($selectName, '/') . '["\'\s>][^>]*>(.*?)<\/select>/is', $html, $selectMatch)) {
            return [];
        }

        $options = [];
        if (preg_match_all('/<option\b[^>]*>([^<]*)<\/option>/i', $selectMatch[1], $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $value = $this->extractAttr($m[0], 'value') ?? trim($m[1]);
                if ($value === '') {
                    continue;
                }
                $options[] = ['value' => $value, 'label' => trim(html_entity_decode($m[1]))];
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("اتصال به پنل IBSng ({$url}) برقرار نشد: {$error}");
        }

        return (string) $body;
    }
}
