import { NavLink } from 'react-router-dom'
import {
  BarChart3, Building2, Columns3, LayoutDashboard, Plug, Settings,
  ShieldCheck, Users2, CalendarClock, Contact2, X,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { cn } from '@/lib/cn'

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
        { to: '/reports', labelKey: 'nav.reports', Icon: BarChart3, visible: can.viewReports(user) },
        { to: '/integrations', labelKey: 'nav.integrations', Icon: Plug, visible: can.manageIntegrations(user) },
        { to: '/users', labelKey: 'nav.users', Icon: Users2, visible: can.seeAllLeads(user) },
        { to: '/settings', labelKey: 'nav.settings', Icon: Settings, visible: true },
      ]

  const systemName = branding?.system_name ?? t('common.appName')

  return (
    <>
      {/* Mobile scrim */}
      {sidebarOpen && (
        <button
          type="button"
          aria-label={t('common.close')}
          className="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      <aside
        data-open={sidebarOpen}
        className={cn(
          'app-sidebar fixed inset-y-0 start-0 z-40 flex w-[17rem] flex-col',
          'border-e border-[var(--color-border)] bg-[var(--color-surface)]',
        )}
      >
        <div className="flex h-16 items-center justify-between gap-2 border-b border-[var(--color-border)] px-4">
          <div className="flex min-w-0 items-center gap-2.5">
            {branding?.logo_url ? (
              <img src={branding.logo_url} alt="" className="size-8 shrink-0 rounded-lg object-contain" />
            ) : (
              <span
                className="grid size-8 shrink-0 place-items-center rounded-lg text-[0.8125rem] font-bold text-white"
                style={{ background: 'var(--brand)' }}
                aria-hidden
              >
                {systemName.slice(0, 1)}
              </span>
            )}
            <span className="truncate text-[0.9375rem] font-semibold">{systemName}</span>
          </div>

          <button
            type="button"
            className="grid size-8 place-items-center rounded-lg text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)] lg:hidden"
            onClick={() => setSidebarOpen(false)}
          >
            <X className="size-4" aria-hidden />
          </button>
        </div>

        <nav className="flex-1 space-y-0.5 overflow-y-auto p-3">
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
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-[0.875rem] font-medium transition-colors',
                    isActive
                      ? 'bg-[var(--brand-soft)] text-[var(--brand)]'
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
          <div className="border-t border-[var(--color-border)] p-3 text-[0.75rem] text-[var(--color-ink-subtle)]">
            <a href={`mailto:${branding.support_email}`} className="hover:text-[var(--color-ink)]">
              {branding.support_email}
            </a>
          </div>
        )}
      </aside>
    </>
  )
}
