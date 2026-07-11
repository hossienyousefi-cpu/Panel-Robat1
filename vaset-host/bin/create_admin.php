<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Domain\Repositories\AdminRepository;

$options = getopt('', ['username:', 'password:', 'name::']);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (!isset($options['username'], $options['password'])) {
    fwrite(STDERR, "Usage: php bin/create_admin.php --username=admin --password='StrongPass123!' --name=\"مدیر\"\n");
    exit(1);
}

$repo = new AdminRepository();
if ($repo->findByUsername($options['username']) !== null) {
    fwrite(STDERR, "Admin '{$options['username']}' already exists.\n");
    exit(1);
}

$id = $repo->create($options['username'], $options['password'], $options['name'] ?? $options['username']);

echo "Admin created with id {$id}.\n";
