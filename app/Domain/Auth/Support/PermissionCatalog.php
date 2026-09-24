<?php

namespace App\Domain\Auth\Support;

/**
 * The fixed, global set of capability strings the app understands — these
 * map 1:1 to `permission:` middleware checks on routes/api.php and never
 * vary per company. What DOES vary per company is which of these each of
 * a shop's own roles is given (see CompanyProvisioningService, which uses
 * DEFAULT_ROLE_PERMISSIONS as the starting point for every new company's
 * Admin/Salesperson/Accountant roles).
 */
class PermissionCatalog
{
    public const ALL = [
        'catalog.manage',
        'distributors.manage',
        'accounting.manage',
        'accounting.view',
        'purchases.manage',
        'customers.manage',
        'pos.sell',
        'expenses.manage',
        'reports.view',
        'reports.view-cost',
        'users.manage',
        'branches.manage',
        'stock.transfer',
        'backup.manage',
        'company.manage',
    ];

    public const LABELS = [
        'catalog.manage' => 'Manage Catalog (brands, models, products)',
        'distributors.manage' => 'Manage Distributors',
        'accounting.manage' => 'Manage Accounting (post entries, custom accounts)',
        'accounting.view' => 'View Accounting / Ledger',
        'purchases.manage' => 'Manage Purchases',
        'customers.manage' => 'Manage Customers',
        'pos.sell' => 'Sell at POS',
        'expenses.manage' => 'Manage Expenses',
        'reports.view' => 'View Reports',
        'reports.view-cost' => 'View Cost / Profit Reports',
        'users.manage' => 'Manage Staff & Roles',
        'branches.manage' => 'Manage Branches',
        'stock.transfer' => 'Transfer Stock Between Branches',
        'backup.manage' => 'Manage Database Backup',
        'company.manage' => 'Manage Shop Settings & Subscription',
    ];

    public const DEFAULT_ROLE_PERMISSIONS = [
        'Admin' => self::ALL,
        'Accountant' => [
            'accounting.manage',
            'accounting.view',
            'expenses.manage',
            'reports.view',
            'reports.view-cost',
        ],
        'Salesperson' => [
            'pos.sell',
            'customers.manage',
            'reports.view',
        ],
    ];
}
