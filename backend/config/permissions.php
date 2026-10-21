<?php

/*
 * Who may do what.
 *
 * Resources are areas of the product; actions are what a person can do in them:
 *
 *   view     read it                      create   make a new one
 *   edit     change one                   execute  run it (work a lead, sync an account, run an agent)
 *   approve  say yes to a proposal        export   take the data out
 *   share    hand a view of it outside    disable  switch off, archive or delete
 *
 * Three more resources are not areas but kinds of data, and decide what a response may contain:
 * `contacts` (phone, email, WhatsApp), `spend` (what the ads cost) and `revenue` (what the sales
 * brought). A role without `contacts.view` never receives a phone number, whichever endpoint it
 * asks; the same for money.
 *
 * `admin` is everything. `manager`, `sales` keep their long-standing policies (these lists only say
 * what the interface should offer them); the six specialised roles are governed by this table
 * alone — a route that is not granted here answers 403.
 */
return [
    'actions' => ['view', 'create', 'edit', 'execute', 'approve', 'export', 'share', 'disable'],

    'resources' => [
        'dashboard', 'leads', 'visits', 'reservations', 'properties', 'projects', 'companies', 'campaigns', 'tracking',
        'analytics', 'partners', 'reports_share', 'automation', 'integrations', 'users', 'settings', 'audit', 'contacts', 'spend', 'revenue',
    ],

    'roles' => [
        'admin' => '*',

        'manager' => [
            'dashboard' => ['view'],
            'leads' => ['view', 'create', 'edit', 'execute', 'export', 'disable'],
            'visits' => ['view', 'create', 'edit', 'approve'],
            'reservations' => ['view', 'create', 'edit', 'approve'],
            'properties' => ['view', 'create', 'edit', 'disable'],
            'projects' => ['view', 'create', 'edit', 'disable'],
            'companies' => ['view', 'create', 'edit', 'disable'],
            'campaigns' => ['view', 'create', 'edit', 'execute', 'approve', 'export'],
            'tracking' => ['view', 'create', 'edit', 'disable'],
            'analytics' => ['view', 'export'],
            'partners' => ['view', 'create', 'edit', 'disable'],
            'reports_share' => ['view', 'create', 'edit', 'share', 'disable'],
            'automation' => ['view', 'create', 'edit', 'disable'],
            'users' => ['view'],
            'settings' => ['view'],
            'audit' => ['view'],
            'contacts' => ['view'], 'spend' => ['view'], 'revenue' => ['view'],
        ],

        'sales' => [
            'dashboard' => ['view'],
            'leads' => ['view', 'create', 'edit', 'execute'],
            'visits' => ['view', 'create', 'edit'],
            'reservations' => ['view', 'create', 'edit'],
            'properties' => ['view'],
            'projects' => ['view'],
            'settings' => ['view'],
            'contacts' => ['view'], 'revenue' => ['view'],
        ],

        'marketing_manager' => [
            'dashboard' => ['view'],
            'leads' => ['view', 'export'],
            'projects' => ['view'],
            'properties' => ['view'],
            'campaigns' => ['view', 'create', 'edit', 'execute', 'approve', 'export'],
            'tracking' => ['view', 'create', 'edit', 'disable'],
            'analytics' => ['view', 'export'],
            'partners' => ['view', 'create', 'edit', 'disable'],
            'reports_share' => ['view', 'create', 'edit', 'share', 'disable'],
            'automation' => ['view'],
            'integrations' => ['view'],
            'settings' => ['view'],
            'spend' => ['view'], 'revenue' => ['view'],
        ],

        'sales_manager' => [
            'dashboard' => ['view'],
            'leads' => ['view', 'create', 'edit', 'execute', 'export'],
            'visits' => ['view', 'create', 'edit', 'approve'],
            'reservations' => ['view', 'create', 'edit', 'approve'],
            'projects' => ['view'],
            'properties' => ['view'],
            'analytics' => ['view'],
            'automation' => ['view', 'create', 'edit', 'disable'],
            'users' => ['view'],
            'settings' => ['view'],
            'contacts' => ['view'], 'revenue' => ['view'],
        ],

        'project_manager' => [
            'dashboard' => ['view'],
            'leads' => ['view', 'edit', 'execute'],
            'visits' => ['view', 'create', 'edit', 'approve'],
            'reservations' => ['view', 'create', 'edit', 'approve'],
            'projects' => ['view', 'edit'],
            'properties' => ['view', 'create', 'edit'],
            'campaigns' => ['view'],
            'tracking' => ['view'],
            'analytics' => ['view'],
            'settings' => ['view'],
            'contacts' => ['view'], 'spend' => ['view'], 'revenue' => ['view'],
        ],

        'finance' => [
            'dashboard' => ['view'],
            'leads' => ['view'],
            'reservations' => ['view', 'export'],
            'projects' => ['view'],
            'properties' => ['view'],
            'campaigns' => ['view', 'export'],
            'analytics' => ['view', 'export'],
            'settings' => ['view'],
            'spend' => ['view'], 'revenue' => ['view'],
        ],

        'client_viewer' => [
            'dashboard' => ['view'],
            'leads' => ['view'],
            'visits' => ['view'],
            'reservations' => ['view'],
            'projects' => ['view'],
            'properties' => ['view'],
            'analytics' => ['view'],
        ],

        'broker_viewer' => [
            'dashboard' => ['view'],
            // Their own links and QR codes (the scope narrows it to the partner they are).
            'tracking' => ['view'],
            'leads' => ['view'],
            'visits' => ['view'],
            'reservations' => ['view'],
            'projects' => ['view'],
            'properties' => ['view'],
        ],
    ],

    // Short words for the roles screen.
    'descriptions' => [
        'admin' => 'كل شيء في المساحة',
        'manager' => 'يدير الفريق والحملات والتقارير',
        'sales' => 'يعمل على عملائه',
        'marketing_manager' => 'الحملات والتتبع والتقارير',
        'sales_manager' => 'الفريق والمسار والزيارات والحجوزات',
        'project_manager' => 'مشاريعه فقط',
        'finance' => 'الأرقام المالية قراءةً فقط',
        'client_viewer' => 'نافذة قراءة لمشاريع العميل',
        'broker_viewer' => 'نافذة قراءة للوسيط',
    ],
];
