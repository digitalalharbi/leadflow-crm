import { NavLink } from 'react-router-dom'
import { HOME } from '@/lib/routes'
import { LogOut, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { cn } from '@/lib/cn'
import { BrandLockup } from '@/components/brand'
import { WorkspaceSwitcher } from './WorkspaceSwitcher'
import { useNavigation } from './navigation'

/**
 * "More" on a phone: a whole screen, the way an app has one. Who and where you are at the top, every destination grouped
 * with room for a thumb, settings and signing out at the foot. From the same list as the tabs and the laptop's rail.
 */
export function Sidebar() {
  const { t } = useTranslation()
  const user = useAuth((s) => s.user)
  const branding = useAuth((s) => s.branding)
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)
  const logout = useAuth((s) => s.logout)
  const sidebarOpen = useUi((s) => s.sidebarOpen)
  const setSidebarOpen = useUi((s) => s.setSidebarOpen)
  const { groups, settings } = useNavigation()
  const close = () => setSidebarOpen(false)

  return (
    <aside
      data-open={sidebarOpen}
      aria-hidden={!sidebarOpen}
      data-testid="more-menu"
      className="app-sidebar fixed inset-0 z-40 flex w-full flex-col bg-[var(--color-surface)] pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] lg:hidden print:hidden"
    >
      <div className="flex h-16 shrink-0 items-center justify-between gap-2 px-4">
        <BrandLockup to={HOME} size="md" />
        <button type="button" aria-label={t('common.close')} className="grid size-11 place-items-center rounded-lg hover:bg-[var(--color-surface-muted)]" onClick={close}>
          <X className="size-5" aria-hidden />
        </button>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-4">
        <div className="rounded-2xl bg-[var(--color-surface-muted)] px-4 py-3" data-testid="more-identity">
          <p className="truncate text-[1rem] font-semibold">{user?.name}</p>
          <p className="truncate text-[0.875rem] text-[var(--color-ink-muted)]">{isSuperAdmin ? t('nav.platformConsole') : (branding?.company_name ?? user?.email)}</p>
        </div>

        {sidebarOpen && !isSuperAdmin && <div className="mt-2"><WorkspaceSwitcher onDone={close} /></div>}

        <nav className="mt-3 space-y-4">
          {groups.map((group, index) => (
            <ul key={index} className="overflow-hidden rounded-2xl border border-[var(--color-line)]">
              {group.map(({ to, labelKey, Icon, badge }) => (
                <li key={to} className="border-b border-[var(--color-line)] last:border-0">
                  <NavLink
                    to={to}
                    end={to === '/dashboard'}
                    onClick={close}
                    className={({ isActive }) => cn('flex min-h-[3.25rem] items-center gap-3 px-4 text-[1rem] font-medium', isActive ? 'bg-[var(--brand-soft)] font-semibold text-[var(--brand-text)]' : 'active:bg-[var(--color-surface-muted)]')}
                  >
                    <Icon className="size-5 shrink-0" aria-hidden />
                    <span className="flex-1 truncate">{t(labelKey)}</span>
                    {badge !== undefined && badge > 0 && <span className="tabular rounded-full bg-[var(--color-status-lost)] px-2 py-0.5 text-[0.8125rem] font-semibold text-white">{badge > 99 ? '99+' : badge}</span>}
                  </NavLink>
                </li>
              ))}
            </ul>
          ))}

          <ul className="overflow-hidden rounded-2xl border border-[var(--color-line)]">
            {settings && (
              <li className="border-b border-[var(--color-line)]">
                <NavLink to={settings.to} onClick={close} className="flex min-h-[3.25rem] items-center gap-3 px-4 text-[1rem] font-medium">
                  <settings.Icon className="size-5 shrink-0" aria-hidden />
                  {t(settings.labelKey)}
                </NavLink>
              </li>
            )}
            <li>
              <button type="button" onClick={() => { close(); void logout() }} data-testid="more-logout" className="flex min-h-[3.25rem] w-full items-center gap-3 px-4 text-start text-[1rem] font-medium text-[var(--color-status-lost)]">
                <LogOut className="size-5 shrink-0" aria-hidden />
                {t('auth.signOut')}
              </button>
            </li>
          </ul>
        </nav>

        {branding?.support_email && <a href={`mailto:${branding.support_email}`} className="mt-3 block text-center text-[0.875rem] text-[var(--color-ink-muted)]">{branding.support_email}</a>}
      </div>
    </aside>
  )
}
