import { NavLink, useNavigate } from 'react-router-dom'
import { LogOut } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/stores/auth'
import { cn } from '@/lib/cn'
import { useNavigation, type NavItem } from './navigation'

/**
 * The compact vertical navigation: rounded white capsules of outline icons.
 *
 * Icon-only, so every destination carries its name three ways — an accessible
 * name for screen readers, a tooltip for the pointer, and the same on keyboard
 * focus. The active destination is a filled green circle with a white glyph;
 * everything else is a quiet grey outline that warms on hover.
 *
 * Positioned by the layout grid, not by `left`/`right`, so in Arabic it sits at
 * the start edge natively rather than by mirroring.
 */
export function IconRail() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const logout = useAuth((s) => s.logout)
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)
  const nav = useNavigation()
  // The console has two destinations and the header already carries both; a
  // rail repeating them is the same link twice. It keeps only the way out.
  const groups = isSuperAdmin ? [] : nav.groups
  const settings = nav.settings

  async function signOut() {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <aside
      aria-label={t('nav.menu')}
      className="hidden w-[3.5rem] shrink-0 flex-col gap-2 self-start lg:flex print:hidden"
      data-testid="icon-rail"
    >
      {groups.map((group, index) => (
        <nav key={index} className="flex flex-col items-center gap-1 rounded-[var(--radius-panel)] bg-[var(--color-surface)] p-1.5 shadow-[var(--shadow-card)]">
          {group.map((item) => (
            <RailLink key={item.to} item={item} />
          ))}
        </nav>
      ))}

      <div className="flex flex-col items-center gap-1 rounded-[var(--radius-panel)] bg-[var(--color-surface)] p-1.5 shadow-[var(--shadow-card)]">
        {settings && <RailLink item={settings} />}
        <button
          type="button"
          onClick={signOut}
          aria-label={t('auth.signOut')}
          className="rail-item group"
          data-testid="rail-sign-out"
        >
          <LogOut className="size-[1.125rem]" aria-hidden />
          <span className="rail-tip" role="tooltip">{t('auth.signOut')}</span>
        </button>
      </div>
    </aside>
  )
}

function RailLink({ item }: { item: NavItem }) {
  const { t } = useTranslation()
  const { to, labelKey, Icon, badge } = item

  return (
    <NavLink
      to={to}
      end={to === '/'}
      aria-label={t(labelKey)}
      className={({ isActive }) => cn('rail-item group', isActive && 'rail-item-active')}
    >
      <Icon className="size-[1.125rem]" aria-hidden />
      {badge !== undefined && badge > 0 && (
        <span className="absolute -end-0.5 -top-0.5 min-w-4 rounded-full bg-[var(--color-status-lost)] px-1 text-center text-[0.625rem] font-semibold leading-4 text-white tabular-nums">
          {badge > 99 ? '99+' : badge}
        </span>
      )}
      <span className="rail-tip" role="tooltip">{t(labelKey)}</span>
    </NavLink>
  )
}
