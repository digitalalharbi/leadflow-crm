import { NavLink } from 'react-router-dom'
import { X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { cn } from '@/lib/cn'
import { BrandLockup } from '@/components/brand'
import { useNavigation } from './navigation'

/**
 * The phone drawer: every destination, labelled.
 *
 * Below the desktop breakpoint there is no icon rail and no pill track — an
 * icon-only strip is the wrong tool on a thumb — so the same destinations, from
 * the same source, open as a list that slides in from the start edge.
 */
export function Sidebar() {
  const { t } = useTranslation()
  const branding = useAuth((s) => s.branding)
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)
  const sidebarOpen = useUi((s) => s.sidebarOpen)
  const setSidebarOpen = useUi((s) => s.setSidebarOpen)
  const { all: items } = useNavigation()

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
          'app-sidebar fixed inset-y-0 start-0 z-40 flex w-[16rem] max-w-[85vw] flex-col lg:hidden print:hidden',
          /*
           * The canvas colour, not white.
           *
           * With a white rail against a white card there is nothing to see;
           * the whole theme rests on cards being the only white things on the
           * screen, so the chrome has to recede for them to read as objects.
           */
          'rounded-e-[var(--radius-panel)] bg-[var(--color-surface)] shadow-[var(--shadow-pop)]',
        )}
      >
        <div className="flex h-14 items-center justify-between gap-2 px-3">
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
            .map(({ to, labelKey, Icon, badge }) => (
              <NavLink
                key={to}
                to={to}
                end={to === '/'}
                onClick={() => setSidebarOpen(false)}
                className={({ isActive }) =>
                  cn(
                    'relative flex items-center gap-2.5 px-3 py-2 text-[0.9375rem] font-medium',
                    'rounded-[var(--radius-pill)] transition-colors duration-[var(--duration-fast)]',
                    /*
                     * The active item is a white pill, which is the same
                     * relationship every card on the canvas has — so "where am
                     * I" is answered by the one visual rule the whole theme is
                     * built on rather than by a second one invented here.
                     *
                     * The brand marker stays: a tenant whose colour is close to
                     * the ink would otherwise have an active state nobody can
                     * see, and that is the one state that must never be subtle.
                     */
                    isActive
                      ? [
                          'bg-[var(--brand)] font-semibold text-[var(--brand-contrast)]',
                        ].join(' ')
                      : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-chrome-fill)] hover:text-[var(--color-ink)]',
                  )
                }
              >
                <Icon className="size-[1.125rem] shrink-0" aria-hidden />
                <span className="flex-1 truncate">{t(labelKey)}</span>
                {badge !== undefined && badge > 0 && (
                  <span className="tabular rounded-full bg-[var(--color-status-lost)] px-1.5 py-0.5 text-[0.8125rem] font-semibold text-white">
                    {badge > 99 ? '99+' : badge}
                  </span>
                )}
              </NavLink>
            ))}
        </nav>

        {branding?.support_email && (
          <div className="border-t border-[var(--color-line)] p-3 text-[0.875rem] text-[var(--color-ink-subtle)]">
            <a href={`mailto:${branding.support_email}`} className="hover:text-[var(--color-ink)]">
              {branding.support_email}
            </a>
          </div>
        )}
      </aside>
    </>
  )
}
