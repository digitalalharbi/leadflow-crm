import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/cn'

/** The reports area's three rooms — the library, the client access center and the analytics — one tap apart. */
export function ReportsTabs() {
  const { t } = useTranslation()
  const tabs = [['/report-library', 'nav.reportLibrary'], ['/client-reports', 'nav.clientReports'], ['/reports', 'nav.reports']] as const

  return (
    <nav aria-label={t('library.title')} className="mb-3 flex flex-wrap gap-1" data-testid="reports-tabs">
      {tabs.map(([to, key]) => (
        <NavLink key={to} to={to} end className={({ isActive }) => cn('pressable rounded-full px-3 py-1 text-[0.875rem] font-semibold', isActive ? 'bg-[var(--brand)] text-[var(--brand-ink)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')} data-testid={`reports-tab-${to.slice(1)}`}>
          {t(key)}
        </NavLink>
      ))}
    </nav>
  )
}
