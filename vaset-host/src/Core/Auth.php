<?php

declare(strict_types=1);

namespace App\Core;

use App\Domain\Repositories\AdminRepository;
use App\Domain\Repositories\ResellerRepository;

/**
 * Two independent auth guards ("admin" and "reseller") sharing the same PHP session
 * but stored under different keys, so an admin and a reseller login never collide.
 */
final class Auth
{
    public static function attemptAdmin(string $username, string $password): bool
    {
        $admin = (new AdminRepository())->findByUsername($username);
        if ($admin === null || !$admin['is_active']) {
            return false;
        }
        if (!password_verify($password, $admin['password_hash'])) {
            return false;
        }
        Session::regenerate();
        Session::set('admin_id', (int) $admin['id']);
        return true;
    }

    public static function attemptReseller(string $username, string $password): bool
    {
        $reseller = (new ResellerRepository())->findByUsername($username);
        if ($reseller === null || !$reseller['is_active']) {
            return false;
        }
        if (!password_verify($password, $reseller['password_hash'])) {
            return false;
        }
        Session::regenerate();
        Session::set('reseller_id', (int) $reseller['id']);
        return true;
    }

    public static function adminId(): ?int
    {
        $id = Session::get('admin_id');
        return $id === null ? null : (int) $id;
    }

    public static function resellerId(): ?int
    {
        $id = Session::get('reseller_id');
        return $id === null ? null : (int) $id;
    }

    public static function currentAdmin(): ?array
    {
        $id = self::adminId();
        return $id === null ? null : (new AdminRepository())->find($id);
    }

    public static function currentReseller(): ?array
    {
        $id = self::resellerId();
        return $id === null ? null : (new ResellerRepository())->find($id);
    }

    public static function requireAdmin(): array
    {
        $admin = self::currentAdmin();
        if ($admin === null) {
            header('Location: /admin/login.php');
            exit;
        }
        return $admin;
    }

    public static function requireReseller(): array
    {
        $reseller = self::currentReseller();
        if ($reseller === null) {
            header('Location: /reseller/login.php');
            exit;
        }
        return $reseller;
    }

    public static function logoutAdmin(): void
    {
        Session::remove('admin_id');
    }

    public static function logoutReseller(): void
    {
        Session::remove('reseller_id');
    }
}
