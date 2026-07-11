<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Domain\Repositories\PricingRepository;

$admin = Auth::requireAdmin();
$pricingRepo = new PricingRepository();

if (Request::isPost()) {
    Csrf::requireValid();
    $groups = Request::post('groups', []);
    $names = Request::post('names', []);
    $prices = Request::post('prices', []);
    $visible = Request::post('visible', []);

    foreach ((array) $groups as $i => $groupName) {
        $groupName = trim((string) $groupName);
        if ($groupName === '') {
            continue;
        }
        $pricingRepo->upsertDirect($groupName, trim((string) ($names[$i] ?? '')) ?: null, (float) ($prices[$i] ?? 0), isset($visible[$i]));
    }

    Session::flash('success', 'قیمت‌گذاری کاتالوگ ربات تلگرام بروزرسانی شد.');
    header('Location: /admin/catalog.php');
    exit;
}

$cachedGroups = $pricingRepo->cachedGroups();
$existing = $pricingRepo->directCatalog(false);
$byGroup = [];
foreach ($existing as $row) {
    $byGroup[$row['group_name']] = $row;
}
foreach ($cachedGroups as $g) {
    if (!isset($byGroup[$g['group_name']])) {
        $byGroup[$g['group_name']] = ['group_name' => $g['group_name'], 'display_name' => null, 'price' => 0, 'is_visible' => 0];
    }
}

View::render('admin/catalog', [
    'pageTitle' => 'قیمت‌گذاری ربات تلگرام',
    'rows' => array_values($byGroup),
], 'admin');
