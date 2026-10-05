import { NavLink } from 'react-router-dom'
import {
  BarChart3, Building2, Columns3, LayoutDashboard, Plug, Settings,
  ShieldAlert, ShieldCheck, Target, Users2, CalendarClock, Contact2, Workflow, X,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { cn } from '@/lib/cn'
import { BrandLockup } from '@/components/brand'

interface NavItem {
  to: string
  labelKey: string
  Icon: typeof LayoutDashboard
  visible: boolean
  badge?: number
}

export function Sidebar() {
  const { t } = useTranslation()
  const user = useAuth((s) => s.user)
  const branding = useAuth((s) => s.branding)
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)
  const sidebarOpen = useUi((s) => s.sidebarOpen)
  const setSidebarOpen = useUi((s) => s.setSidebarOpen)

  // Overdue count sits next to "Follow-ups" — it is the number that should
  // pull a rep back into the app.
  const { data: counts } = useQuery({
    queryKey: ['follow-up-counts'],
    queryFn: () => apiGet<{ overdue: number; today: number; upcoming: number }>('/follow-ups/counts'),
    enabled: !isSuperAdmin,
    refetchInterval: 120_000,
  })

  const items: NavItem[] = isSuperAdmin
    ? [{ to: '/platform', labelKey: 'nav.superAdmin', Icon: ShieldCheck, visible: true }]
    : [
        { to: '/', labelKey: 'nav.dashboard', Icon: LayoutDashboard, visible: true },
        /*
         * Directly under the dashboard, above the lead list. A rep who opens
         * the list first starts by choosing; this screen is the one that tells
         * them, so it comes first.
         */
        { to: '/work', labelKey: 'nav.work', Icon: Target, visible: true },
        { to: '/leads', labelKey: 'nav.leads', Icon: Contact2, visible: true },
        { to: '/pipeline', labelKey: 'nav.pipeline', Icon: Columns3, visible: true },
        {
          to: '/follow-ups',
          labelKey: 'nav.followUps',
          Icon: CalendarClock,
          visible: true,
          badge: (counts?.overdue ?? 0) + (counts?.today ?? 0),
        },
        { to: '/projects', labelKey: 'nav.projects', Icon: Building2, visible: true },
        /*
         * Rules act on everybody's leads, so the screen belongs to the people
         * who already have workspace-wide authority — the same test the API
         * applies, rather than a second, looser one in the navigation.
         */
        { to: '/automation', labelKey: 'nav.automation', Icon: Workflow, visible: can.seeAllLeads(user) },
        { to: '/reports', labelKey: 'nav.reports', Icon: BarChart3, visible: can.viewReports(user) },
        { to: '/data-quality', labelKey: 'nav.dataQuality', Icon: ShieldAlert, visible: can.viewReports(user) },
        { to: '/integrations', labelKey: 'nav.integrations', Icon: Plug, visible: can.manageIntegrations(user) },
        { to: '/users', labelKey: 'nav.users', Icon: Users2, visible: can.seeAllLeads(user) },
        { to: '/settings', labelKey: 'nav.settings', Icon: Settings, visible: true },
      ]

  return (
    <>
      {/* Mobile scrim */}
      {sidebarOpen && (
        <button
          type="button"
          aria-label={t('common.close')}
          className="fixed inset-0 z-30 bg-[var(--color-neutral-900)]/40 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      <aside
        data-open={sidebarOpen}
        className={cn(
          'app-sidebar fixed inset-y-0 start-0 z-40 flex w-[17rem] flex-col',
          'border-e border-[var(--color-line)] bg-[var(--color-surface)]',
        )}
      >
        <div className="flex h-16 items-center justify-between gap-2 border-b border-[var(--color-line)] px-4">
          {/* One component decides whose identity this is — see BrandLockup. */}
          <BrandLockup to="/" size="md" />

          <button
            type="button"
            aria-label={t('common.close')}
            className="grid size-8 place-items-center rounded-[var(--radius-control)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)] lg:hidden"
            onClick={() => setSidebarOpen(false)}
          >
            <X className="size-4" aria-hidden />
          </button>
        </div>

        <nav className="flex-1 space-y-0.5 overflow-y-auto p-3">
          {/*
           * The console says so in words as well as in colour. Colour alone is
           * the kind of cue that stops working on the day it matters — a tired
           * operator, a projector, a screenshot pasted into a ticket.
           */}
          {isSuperAdmin && (
            <p className="label-caps px-3 pb-2 text-[var(--color-ink-subtle)]">
              {t('nav.platformConsole')}
            </p>
          )}

          {items
            .filter((item) => item.visible)
            .map(({ to, labelKey, Icon, badge }) => (
              <NavLink
                key={to}
                to={to}
                end={to === '/'}
                onClick={() => setSidebarOpen(false)}
                className={({ isActive }) =>
                  cn(
                    'relative flex items-center gap-3 px-3 py-2 text-[0.875rem] font-medium',
                    'rounded-[var(--radius-control)] transition-colors duration-[var(--duration-fast)]',
                    // The active item gets a filled pill and a marker on the
                    // inline-start edge, so which page you are on survives a
                    // tenant whose brand colour is almost the text colour.
                    isActive
                      ? [
                          'bg-[var(--brand-soft)] font-semibold text-[var(--brand)]',
                          'before:absolute before:inset-y-1.5 before:start-0 before:w-0.5',
                          'before:rounded-full before:bg-[var(--brand)]',
                        ].join(' ')
                      : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)] hover:text-[var(--color-ink)]',
                  )
                }
              >
                <Icon className="size-[1.125rem] shrink-0" aria-hidden />
                <span className="flex-1 truncate">{t(labelKey)}</span>
                {badge !== undefined && badge > 0 && (
                  <span className="tabular rounded-full bg-[var(--color-status-lost)] px-1.5 py-0.5 text-[0.6875rem] font-semibold text-white">
                    {badge > 99 ? '99+' : badge}
                  </span>
                )}
              </NavLink>
            ))}
        </nav>

        {branding?.support_email && (
          <div className="border-t border-[var(--color-line)] p-3 text-[0.75rem] text-[var(--color-ink-subtle)]">
            <a href={`mailto:${branding.support_email}`} className="hover:text-[var(--color-ink)]">
              {branding.support_email}
            </a>
          </div>
        )}
      </aside>
    </>
  )
}
