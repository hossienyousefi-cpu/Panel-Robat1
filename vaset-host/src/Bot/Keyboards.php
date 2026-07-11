<?php

declare(strict_types=1);

namespace App\Bot;

final class Keyboards
{
    public static function mainMenu(): array
    {
        return [
            'keyboard' => [
                ['🛍 مشاهده سرویس‌ها و خرید', '♻️ تمدید سرویس'],
                ['💳 ثبت فیش پرداخت', '👤 حساب من'],
                ['☎️ پشتیبانی'],
            ],
            'resize_keyboard' => true,
        ];
    }

    /** @param array<int,array{group_name:string,display_name?:?string,price:float}> $catalog */
    public static function catalogInline(array $catalog, string $prefix): array
    {
        $rows = [];
        foreach ($catalog as $item) {
            $label = ($item['display_name'] ?: $item['group_name']) . ' - ' . number_format((float) $item['price']) . ' تومان';
            $rows[] = [['text' => $label, 'callback_data' => $prefix . ':' . $item['group_name']]];
        }
        return ['inline_keyboard' => $rows];
    }

    /** @param array<int,array{ibsng_username:string,group_name:string}> $users */
    public static function myServicesInline(array $users, string $prefix): array
    {
        $rows = [];
        foreach ($users as $user) {
            $label = $user['ibsng_username'] . ' (' . $user['group_name'] . ')';
            $rows[] = [['text' => $label, 'callback_data' => $prefix . ':' . $user['ibsng_username']]];
        }
        return ['inline_keyboard' => $rows];
    }

    /** Task 4: admin-only control panel menu (DB export/import, IBSng Agent connection settings). */
    public static function adminMenu(): array
    {
        return [
            'keyboard' => [
                ['📤 Export دیتابیس', '📥 Import دیتابیس'],
                ['⚙️ آدرس IBSng Agent', '🔑 کلید API Agent'],
                ['📶 تست اتصال', '❌ لغو عملیات جاری'],
            ],
            'resize_keyboard' => true,
        ];
    }
}
