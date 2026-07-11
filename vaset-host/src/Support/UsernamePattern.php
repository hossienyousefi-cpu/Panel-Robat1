<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Expands a username pattern such as "ali{01-20}" into ["ali01", "ali02", ..., "ali20"],
 * matching the group-creation UX described for the reseller panel (Count field + a
 * {start-end} range placeholder). A plain username with no {..} range is treated as a
 * single-user pattern.
 */
final class UsernamePattern
{
    /** @return string[] */
    public static function expand(string $pattern, int $expectedCount): array
    {
        if (!preg_match('/^(?<prefix>[^{}]*)\{(?<start>\d+)-(?<end>\d+)\}(?<suffix>[^{}]*)$/u', $pattern, $m)) {
            if ($expectedCount !== 1) {
                throw new InvalidArgumentException(
                    "برای Count بزرگتر از ۱ باید یک بازه مثل ali{01-{$expectedCount}} در یوزرنیم مشخص کنید."
                );
            }
            $trimmed = trim($pattern);
            if ($trimmed === '') {
                throw new InvalidArgumentException('یوزرنیم نمی‌تواند خالی باشد.');
            }
            return [$trimmed];
        }

        $start = (int) $m['start'];
        $end = (int) $m['end'];
        $width = max(strlen($m['start']), strlen($m['end']));

        if ($end < $start) {
            throw new InvalidArgumentException('بازهٔ یوزرنیم نامعتبر است: عدد پایانی باید بزرگتر از عدد شروع باشد.');
        }

        $count = $end - $start + 1;
        if ($count !== $expectedCount) {
            throw new InvalidArgumentException(
                "بازهٔ یوزرنیم {$m['start']}-{$m['end']} شامل {$count} یوزر است ولی Count برابر {$expectedCount} وارد شده."
            );
        }

        $usernames = [];
        for ($i = $start; $i <= $end; $i++) {
            $number = str_pad((string) $i, $width, '0', STR_PAD_LEFT);
            $usernames[] = $m['prefix'] . $number . $m['suffix'];
        }

        return $usernames;
    }
}
