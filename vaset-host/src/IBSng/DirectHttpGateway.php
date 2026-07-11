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
            $ok = isset($hidden['user_id']) && ctype_digit((string) $hidden['user_id']);

            $results[] = new CreateUserResultItem(
                $item->username,
                $ok,
                $ok ? null : 'IBSng شناسه کاربر جدید را برنگرداند - ممکن است یوزرنیم تکراری باشد یا گروه/ISP نامعتبر باشد.',
            );
        }

        return $results;
    }

    public function deleteUser(string $username): bool
    {
        throw new RuntimeException('حذف کاربر هنوز از طریق اتصال مستقیم پیاده‌سازی نشده - نیاز به گرفتن نمونه واقعی HTML صفحه جستجو/حذف کاربر در IBSng داریم.');
    }

    public function renewUser(string $username, string $group, float $addCredit1): bool
    {
        throw new RuntimeException('تمدید کاربر هنوز از طریق اتصال مستقیم پیاده‌سازی نشده - نیاز به گرفتن نمونه واقعی HTML ویرایش Credit1 در IBSng داریم.');
    }

    public function lockUser(string $username): bool
    {
        throw new RuntimeException('قفل‌کردن کاربر هنوز از طریق اتصال مستقیم پیاده‌سازی نشده - نیاز به گرفتن آدرس دقیق و فیلدهای مخفی صفحه ویرایش کاربر در IBSng داریم.');
    }

    public function unlockUser(string $username): bool
    {
        throw new RuntimeException('بازکردن قفل کاربر هنوز از طریق اتصال مستقیم پیاده‌سازی نشده - نیاز به گرفتن آدرس دقیق و فیلدهای مخفی صفحه ویرایش کاربر در IBSng داریم.');
    }

    public function getUserStatus(string $username): ?UserStatus
    {
        throw new RuntimeException('استعلام وضعیت کاربر هنوز از طریق اتصال مستقیم پیاده‌سازی نشده - نیاز به گرفتن نمونه واقعی HTML نتیجه جستجوی کاربر در IBSng داریم.');
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
