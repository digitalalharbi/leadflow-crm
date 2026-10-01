<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Sales = 'sales';

    /** Roles that can be assigned to a user inside a tenant. */
    public static function tenantRoles(): array
    {
        return [self::Admin->value, self::Manager->value, self::Sales->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Sales => 'Sales',
        };
    }

    /** Admin and manager see every lead in the tenant; sales see only their own. */
    public function seesAllLeads(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Admin, self::Manager], true);
    }

    public function managesTenant(): bool
    {
        return $this === self::Admin;
    }
}
