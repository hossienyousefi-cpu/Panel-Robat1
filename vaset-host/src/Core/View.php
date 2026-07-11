<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    private static string $baseDir = __DIR__ . '/../Templates';

    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = null): void
    {
        echo self::renderToString($template, $data, $layout);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function renderToString(string $template, array $data = [], ?string $layout = null): string
    {
        $content = self::capture($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture('layouts/' . $layout, $data + ['content' => $content]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function capture(string $template, array $data): string
    {
        $path = self::$baseDir . '/' . $template . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    public static function money(float $amount): string
    {
        return number_format($amount, 0) . ' تومان';
    }
}
