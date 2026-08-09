<?php
// آیکن‌های SVG ساده (خط‌محور، هم‌خانواده‌ی سبک Outline) برای جایگزینی
// ایموجی‌های تزئینی توی ناوبری/کارت‌ها - طبق توصیه‌ی سیستم طراحی (ui-ux-pro-max):
// «از ایموجی به‌عنوان آیکن استفاده نشه». هر فراخوانی فقط یک رشته‌ی ثابت و
// امن برمی‌گردونه، نیازی به escape نداره.
function svgIcon(string $name, string $class = 'icon'): string {
    $paths = [
        'dashboard'   => '<path d="M4 19V11M10 19V5M16 19v-7M21 19H3"/>',
        'users'       => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.6 2.7-6.5 6-6.5s6 2.9 6 6.5"/><path d="M15.5 8.2a2.6 2.6 0 1 1 0 5.2"/><path d="M15 14c2.7.4 4.8 2.8 5 6"/>',
        'user'        => '<circle cx="12" cy="8" r="3.6"/><path d="M5 20c0-4 3.1-7 7-7s7 3 7 7"/>',
        'online'      => '<path d="M2 9.5c5.8-5.4 14.2-5.4 20 0"/><path d="M5.5 13.3c3.8-3.4 9.2-3.4 13 0"/><path d="M9 17c1.7-1.4 4.3-1.4 6 0"/><circle cx="12" cy="20" r="1" fill="currentColor" stroke="none"/>',
        'warning'     => '<path d="M12 3.5 21.5 20h-19L12 3.5z"/><path d="M12 10v4.5"/><circle cx="12" cy="17.3" r=".9" fill="currentColor" stroke="none"/>',
        'card'        => '<rect x="3" y="6" width="18" height="13" rx="2.2"/><path d="M3 10.5h18"/><circle cx="17" cy="15" r="1.1" fill="currentColor" stroke="none"/>',
        'refresh'     => '<path d="M4.5 12a7.5 7.5 0 0 1 13-5.1M20 5v5.5h-5.5"/><path d="M19.5 12a7.5 7.5 0 0 1-13 5.1M4 19v-5.5h5.5"/>',
        'wallet'      => '<circle cx="12" cy="12" r="9"/><path d="M9 9.6c0-1.2 1.3-2.1 3-2.1s3 .9 3 2.1-1.3 1.6-3 2-3 .8-3 2 1.3 2.1 3 2.1 3-.9 3-2.1"/>',
        'receipt'     => '<path d="M6 3h12v18l-2.5-1.5L13 21l-2.5-1.5L8 21l-2-1.5V3z"/><path d="M9 8h6M9 12h6"/>',
        'box'         => '<path d="M3 8 12 3l9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/><path d="M12 13v9"/>',
        'cart'        => '<circle cx="9" cy="20" r="1.3"/><circle cx="17" cy="20" r="1.3"/><path d="M3 4h2l2.4 12.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L21 8H6"/>',
        'bot'         => '<rect x="4" y="7" width="16" height="12" rx="3"/><circle cx="9" cy="13" r="1.3" fill="currentColor" stroke="none"/><circle cx="15" cy="13" r="1.3" fill="currentColor" stroke="none"/><path d="M12 7V4"/><circle cx="12" cy="3" r="1" fill="currentColor" stroke="none"/>',
        'list'        => '<path d="M4 6h16M4 12h16M4 18h10"/>',
        'gear'        => '<circle cx="12" cy="12" r="3.2"/><path d="M12 3v2.4M12 18.6V21M4.9 4.9l1.7 1.7M17.4 17.4l1.7 1.7M3 12h2.4M18.6 12H21M4.9 19.1l1.7-1.7M17.4 6.6l1.7-1.7"/>',
        'logout'      => '<path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'shield'      => '<path d="M12 3 19 6v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/>',
        'bolt'        => '<path d="M13 2 4 14h6l-1 8 9-12h-6l1-8z"/>',
        'plus'        => '<path d="M12 5v14M5 12h14"/>',
        'eye'         => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
        'signal'      => '<path d="M4 18v-3M9 18v-6M14 18v-9M19 18V6"/>',
        'trend'       => '<path d="M3 17l6-6 4 4 8-9"/><path d="M15 6h6v6"/>',
        'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'ticket'      => '<path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a1.6 1.6 0 0 0 0 3v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a1.6 1.6 0 0 0 0-3V8z"/>',
        'megaphone'   => '<path d="M3 10v4h3l6 4V6l-6 4H3z"/><path d="M14.5 9.3a3.3 3.3 0 0 1 0 5.4"/><path d="M17 6.8a6.6 6.6 0 0 1 0 10.4"/>',
        'link'        => '<path d="M9 15l6-6"/><path d="M8 13l-2 2a3.5 3.5 0 0 0 5 5l2-2"/><path d="M16 11l2-2a3.5 3.5 0 0 0-5-5l-2 2"/>',
        'chat'        => '<path d="M4 5h16v11H8l-4 4V5z"/>',
        'tag'         => '<path d="M20 12.5 11.5 21 3 12.5V4h8.5L20 12.5z"/><circle cx="7.5" cy="7.5" r="1.3" fill="currentColor" stroke="none"/>',
        'search'      => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'menu'        => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'globe'       => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 4 6 4 9s-1.5 6.4-4 9c-2.5-2.6-4-6-4-9s1.5-6.4 4-9z"/>',
        'wrench'      => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2-2 2.6-2.6z"/>',
        'bank'        => '<path d="M3 10 12 4l9 6"/><path d="M5 10v9M9.5 10v9M14.5 10v9M19 10v9"/><path d="M3 19h18"/>',
        'download'    => '<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 19h14"/>',
        'upload'      => '<path d="M12 21V9"/><path d="M7 14l5-5 5 5"/><path d="M5 21h14"/>',
        'trash'       => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/>',
        'check'       => '<path d="M4 12l6 6L20 6"/>',
        'key'         => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9"/><path d="M16 7l3 3"/><path d="M13 10l2.5 2.5"/>',
        'database'    => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'palette'     => '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.4-.3-.4-.5-.8-.5-1.4 0-1.1.9-2 2-2h2.3c1.8 0 3.2-1.6 2.9-3.4C19.6 6.6 16.2 3 12 3z"/><circle cx="7.5" cy="10.5" r="1" fill="currentColor" stroke="none"/><circle cx="10.5" cy="7" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="8" r="1" fill="currentColor" stroke="none"/>',
        'book'        => '<path d="M4 5.5C4 4.7 4.7 4 5.5 4H12v16H5.5c-.8 0-1.5-.7-1.5-1.5v-13z"/><path d="M20 5.5c0-.8-.7-1.5-1.5-1.5H12v16h6.5c.8 0 1.5-.7 1.5-1.5v-13z"/>',
    ];
    $body = $paths[$name] ?? $paths['dashboard'];
    return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}
