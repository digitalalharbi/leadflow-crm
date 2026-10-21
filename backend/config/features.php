<?php

/*
 * The pieces of the interface a workspace can show or hide, one by one.
 *
 * `config/permissions.php` says what a person may DO in an area. This says what they may SEE of it:
 * a page, a section of a page, a widget, one KPI, a chart, a field, an action button, a section of
 * a report. A workspace turns any of them off for a role, a project or a person
 * (`permission_rules`, permission `ui:<key>`), and the interface does not render what is off.
 *
 *   kind      page · section · widget · kpi · chart · field · action · report_section
 *   needs     permissions the element is built from: without any one of them it is off whatever the
 *             rules say (a revenue chart is never shown to somebody who may not see revenue)
 *   scrub     response keys the API removes when the element is off, on the paths given — so hiding a
 *             field is the server's act too, not only the page's
 */
return [
    'kinds' => ['page', 'section', 'widget', 'kpi', 'chart', 'field', 'action', 'report_section'],

    'elements' => [
        // Pages
        'page.dashboard' => ['kind' => 'page', 'needs' => ['dashboard.view']],
        'page.leads' => ['kind' => 'page', 'needs' => ['leads.view']],
        'page.visits' => ['kind' => 'page', 'needs' => ['visits.view']],
        'page.reservations' => ['kind' => 'page', 'needs' => ['reservations.view']],
        'page.campaigns' => ['kind' => 'page', 'needs' => ['campaigns.view']],
        'page.tracking' => ['kind' => 'page', 'needs' => ['tracking.view']],
        'page.partners' => ['kind' => 'page', 'needs' => ['partners.view']],
        'page.reports' => ['kind' => 'page', 'needs' => ['analytics.view']],
        'page.automation' => ['kind' => 'page', 'needs' => ['automation.view']],
        'page.audit' => ['kind' => 'page', 'needs' => ['audit.view']],

        // Dashboard
        'dashboard.widget.holding' => ['kind' => 'widget', 'needs' => ['dashboard.view']],
        'dashboard.widget.journey' => ['kind' => 'widget', 'needs' => ['dashboard.view']],
        'dashboard.widget.board' => ['kind' => 'widget', 'needs' => ['dashboard.view']],
        'dashboard.widget.operations_home' => ['kind' => 'widget', 'needs' => ['dashboard.view']],
        'dashboard.widget.ops_pulse' => ['kind' => 'widget', 'needs' => ['dashboard.view']],
        'dashboard.widget.insights' => ['kind' => 'widget', 'needs' => ['analytics.view']],
        'dashboard.widget.recommendations' => ['kind' => 'widget', 'needs' => ['analytics.view']],
        'dashboard.widget.manager' => ['kind' => 'widget', 'needs' => ['analytics.view']],
        'dashboard.widget.today' => ['kind' => 'widget', 'needs' => ['dashboard.view']],
        'dashboard.kpi.leads_today' => ['kind' => 'kpi', 'needs' => ['analytics.view']],
        'dashboard.kpi.qualified' => ['kind' => 'kpi', 'needs' => ['analytics.view']],
        'dashboard.kpi.overdue' => ['kind' => 'kpi', 'needs' => ['analytics.view']],
        'dashboard.kpi.stalled' => ['kind' => 'kpi', 'needs' => ['analytics.view']],
        'dashboard.kpi.response' => ['kind' => 'kpi', 'needs' => ['analytics.view']],
        'dashboard.kpi.appointment_rate' => ['kind' => 'kpi', 'needs' => ['analytics.view']],
        'dashboard.kpi.conversion' => ['kind' => 'kpi', 'needs' => ['analytics.view']],
        'dashboard.kpi.period' => ['kind' => 'kpi', 'needs' => ['analytics.view']],
        'dashboard.kpi.revenue' => ['kind' => 'kpi', 'needs' => ['revenue.view'], 'scrub' => ['paths' => ['dashboard', 'dashboard/*'], 'keys' => ['revenue', 'revenue_today', 'won_value']]],
        'dashboard.chart.funnel' => ['kind' => 'chart', 'needs' => ['dashboard.view']],
        'dashboard.chart.velocity' => ['kind' => 'chart', 'needs' => ['dashboard.view']],
        'dashboard.chart.campaign_quality' => ['kind' => 'chart', 'needs' => ['analytics.view']],
        'dashboard.chart.platforms' => ['kind' => 'chart', 'needs' => ['analytics.view']],
        'dashboard.chart.lead_quality' => ['kind' => 'chart', 'needs' => ['dashboard.view']],
        'dashboard.chart.response_time' => ['kind' => 'chart', 'needs' => ['dashboard.view']],
        'dashboard.chart.revenue_by_quality' => ['kind' => 'chart', 'needs' => ['revenue.view']],

        // Campaigns
        'campaigns.field.budget' => ['kind' => 'field', 'needs' => ['spend.view'], 'scrub' => ['paths' => ['campaigns', 'campaigns/*'], 'keys' => ['daily_budget', 'budget', 'lifetime_budget']]],
        'campaigns.field.spend' => ['kind' => 'field', 'needs' => ['spend.view'], 'scrub' => ['paths' => ['campaigns', 'campaigns/*'], 'keys' => ['spend', 'spend_source', 'budget_spent']]],
        'campaigns.field.roas' => ['kind' => 'field', 'needs' => ['spend.view', 'revenue.view'], 'scrub' => ['paths' => ['campaigns', 'campaigns/*'], 'keys' => ['roas']]],
        'campaigns.field.cost_per' => ['kind' => 'field', 'needs' => ['spend.view'], 'scrub' => ['paths' => ['campaigns', 'campaigns/*'], 'keys' => ['cpl', 'cpql', 'cpa', 'cac', 'cost_per_appointment', 'cost_per_interested', 'cost_per_visit', 'cost_per_reservation']]],
        'campaigns.section.revenue' => ['kind' => 'section', 'needs' => ['revenue.view']],
        'campaigns.action.pause' => ['kind' => 'action', 'needs' => ['campaigns.execute']],
        'campaigns.action.budget' => ['kind' => 'action', 'needs' => ['campaigns.execute']],
        'campaigns.action.sync' => ['kind' => 'action', 'needs' => ['campaigns.execute']],

        // A lead
        'lead.field.phone' => ['kind' => 'field', 'needs' => ['contacts.view'], 'scrub' => ['paths' => ['leads', 'leads/*', 'work/*', 'appointments', 'appointments/*', 'follow-ups', 'follow-ups/*', 'pipeline'], 'keys' => ['phone', 'phone_normalized', 'whatsapp', 'lead_phone']]],
        'lead.field.email' => ['kind' => 'field', 'needs' => ['contacts.view'], 'scrub' => ['paths' => ['leads', 'leads/*', 'work/*', 'appointments', 'appointments/*', 'follow-ups', 'follow-ups/*', 'pipeline'], 'keys' => ['email', 'lead_email']]],
        'lead.field.deal_value' => ['kind' => 'field', 'needs' => ['revenue.view'], 'scrub' => ['paths' => ['leads', 'leads/*', 'work/*', 'pipeline'], 'keys' => ['deal_value', 'deal_value_minor']]],
        'lead.action.reassign' => ['kind' => 'action', 'needs' => ['leads.execute']],
        'lead.action.export' => ['kind' => 'action', 'needs' => ['leads.export']],

        // Partners
        'partners.field.commission' => ['kind' => 'field', 'needs' => ['revenue.view']],
        'partners.section.statement' => ['kind' => 'section', 'needs' => ['partners.view']],

        // Report sections
        'report.section.kpi_volume' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.kpi_cost' => ['kind' => 'report_section', 'needs' => ['spend.view']],
        'report.section.kpi_revenue' => ['kind' => 'report_section', 'needs' => ['revenue.view']],
        'report.section.funnel' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.trend' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.platforms' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.campaigns' => ['kind' => 'report_section', 'needs' => ['campaigns.view']],
        'report.section.team' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.interested' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.qualification' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.visits' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.reservations' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.attribution' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.automation' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.tracking_health' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.insights' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
        'report.section.statement' => ['kind' => 'report_section', 'needs' => ['partners.view', 'revenue.view']],
        'report.section.recommendations' => ['kind' => 'report_section', 'needs' => ['analytics.view']],
    ],
];
