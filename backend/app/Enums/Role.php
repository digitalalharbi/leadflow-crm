<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Sales = 'sales';

    // The specialised roles. Each is governed by the permission matrix (config/permissions.php)
    // end to end: a route it has not been granted answers 403, whatever the controller would say.
    case MarketingManager = 'marketing_manager';
    case SalesManager = 'sales_manager';
    case ProjectManager = 'project_manager';
    case Finance = 'finance';
    case ClientViewer = 'client_viewer';
    case BrokerViewer = 'broker_viewer';

    /** Roles that can be assigned to a user inside a tenant. */
    public static function tenantRoles(): array
    {
        return array_column(array_filter(self::cases(), fn (self $r) => $r !== self::SuperAdmin), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Sales => 'Sales',
            self::MarketingManager => 'Marketing Manager',
            self::SalesManager => 'Sales Manager',
            self::ProjectManager => 'Project Manager',
            self::Finance => 'Finance',
            self::ClientViewer => 'Client Viewer',
            self::BrokerViewer => 'Broker Viewer',
        };
    }

    /**
     * Admin, manager and the specialised roles read every lead the permission matrix lets them;
     * sales see only their own. Project scope (below) narrows the rest by project.
     */
    public function seesAllLeads(): bool
    {
        return $this !== self::Sales;
    }

    public function managesTenant(): bool
    {
        return $this === self::Admin;
    }

    /** The roles whose whole access is decided by the matrix (the others keep their long-standing policies). */
    public function usesMatrix(): bool
    {
        return in_array($this, [self::MarketingManager, self::SalesManager, self::ProjectManager, self::Finance, self::ClientViewer, self::BrokerViewer], true);
    }

    /** Sees only the projects they have been given — and nothing at all until they have been given one. */
    public function isProjectScoped(): bool
    {
        return in_array($this, [self::ProjectManager, self::ClientViewer, self::BrokerViewer], true);
    }

    /** People from outside the company: read-only windows, never a place to work leads from. */
    public function isExternal(): bool
    {
        return in_array($this, [self::ClientViewer, self::BrokerViewer], true);
    }
}
