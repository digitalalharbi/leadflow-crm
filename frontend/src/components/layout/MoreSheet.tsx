import { useEffect, useRef, useState } from 'react'
import { NavLink } from 'react-router-dom'
import { ChevronRight, LogOut, Monitor, Moon, Sun, X } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/stores/auth'
import { useUi, type Appearance } from '@/stores/ui'
import { cn } from '@/lib/cn'
import { WorkspaceSwitcher } from './WorkspaceSwitcher'
import { useNavigation, type NavItem } from './navigation'

const APPEARANCES: { key: Appearance; Icon: LucideIcon }[] = [
  { key: 'light', Icon: Sun },
  { key: 'dark', Icon: Moon },
  { key: 'system', Icon: Monitor },
]

/** A swipe down this far, from the sheet's header, closes it: the gesture every phone sheet answers to. */
const CLOSE_DRAG = 80

/** A little longer than the slide itself (--duration-base), so the contents leave after the sheet has gone. */
const CLOSE_AFTER_MS = 400

/**
 * "More" on a phone: a sheet that rises from the bottom, the way an app's does, never one that slides in from the side.
 *
 * Who and where you are and the look you prefer at the top; every place this person can go as cards a thumb can hit,
 * from the same permission-aware list as the tab bar and the laptop's rail; the administrative places as rows; and
 * signing out at the foot. Nothing here needs a sideways swipe, and nothing depends on a gesture: every move has a button.
 */
export function MoreSheet() {
  const { t } = useTranslation()
  const user = useAuth((s) => s.user)
  const branding = useAuth((s) => s.branding)
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)
  const logout = useAuth((s) => s.logout)
  const open = useUi((s) => s.sidebarOpen)
  const setOpen = useUi((s) => s.setSidebarOpen)
  const appearance = useUi((s) => s.appearance)
  const setAppearance = useUi((s) => s.setAppearance)
  const { groups, settings } = useNavigation()
  const close = () => setOpen(false)
  const closeButton = useRef<HTMLButtonElement>(null)
  /*
   * The contents exist only while the sheet is open, and for the length of its slide back down: a closed sheet holding
   * every link, switch and test id of the app would put a second copy of each into every page.
   */
  const [mounted, setMounted] = useState(open)

  useEffect(() => {
    if (open) {
      setMounted(true)
      return
    }
    const timer = window.setTimeout(() => setMounted(false), CLOSE_AFTER_MS)

    return () => window.clearTimeout(timer)
  }, [open])
  const dragFrom = useRef<number | null>(null)

  // The last group is the administrative one (integrations, people, access) when there is more than one; it reads as rows.
  const cardGroups = groups.length > 1 ? groups.slice(0, -1) : groups
  const adminRows: NavItem[] = [...(groups.length > 1 ? groups[groups.length - 1] : []), ...(settings ? [settings] : [])]

  useEffect(() => {
    if (!open) return

    const returnTo = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('keydown', onKey)
    // The page behind must not scroll under a thumb that is meant for the sheet.
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.requestAnimationFrame(() => closeButton.current?.focus())

    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = overflow
      returnTo?.focus?.()
    }
  }, [open, setOpen])

  return (
    <>
      <div
        data-open={open}
        data-testid="more-scrim"
        aria-hidden
        onClick={close}
        className="more-scrim fixed inset-0 z-[39] bg-black/45 lg:hidden print:hidden"
      />

      <aside
        data-open={open}
        aria-hidden={!open}
        aria-label={t('nav.more')}
        data-testid="more-menu"
        className="app-sidebar fixed inset-x-0 bottom-0 top-[calc(env(safe-area-inset-top)+0.75rem)] z-40 flex flex-col rounded-t-[1.75rem] bg-[var(--color-canvas)] shadow-[0_-12px_40px_-12px_rgb(0_0_0/0.35)] lg:hidden print:hidden"
      >
        {mounted && <>
        <div
          className="shrink-0 touch-pan-x px-4 pt-2"
          onTouchStart={(e) => { dragFrom.current = e.touches[0]?.clientY ?? null }}
          onTouchEnd={(e) => {
            const from = dragFrom.current
            dragFrom.current = null
            if (from !== null && (e.changedTouches[0]?.clientY ?? from) - from > CLOSE_DRAG) close()
          }}
        >
          <div className="mx-auto h-1.5 w-10 rounded-full bg-[var(--color-line-strong,var(--color-line))]" aria-hidden />

          <div className="mt-3 flex items-center gap-3" data-testid="more-identity">
            <span className="grid size-12 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[1.125rem] font-bold text-[var(--brand-text)]" aria-hidden>
              {(user?.name ?? '?').trim().charAt(0).toUpperCase()}
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-[1.0625rem] font-semibold leading-tight">{user?.name}</p>
              <p className="truncate text-[0.875rem] text-[var(--color-ink-muted)]"><bdi dir="ltr">{user?.email}</bdi></p>
              <p className="truncate text-[0.8125rem] text-[var(--color-ink-muted)]">{isSuperAdmin ? t('nav.platformConsole') : branding?.company_name}</p>
            </div>
            <button
              ref={closeButton}
              type="button"
              aria-label={t('common.close')}
              data-testid="more-close"
              className="grid size-11 shrink-0 place-items-center rounded-full bg-[var(--color-surface)] shadow-[var(--shadow-card)] active:scale-95"
              onClick={close}
            >
              <X className="size-5" aria-hidden />
            </button>
          </div>

          <div role="group" aria-label={t('nav.appearance.label')} className="mt-3 grid grid-cols-3 gap-1 rounded-2xl bg-[var(--color-surface-muted)] p-1" data-testid="more-appearance">
            {APPEARANCES.map(({ key, Icon }) => (
              <button
                key={key}
                type="button"
                aria-pressed={appearance === key}
                data-testid={`appearance-${key}`}
                onClick={() => setAppearance(key)}
                className={cn(
                  'flex min-h-11 items-center justify-center gap-1.5 rounded-xl text-[0.9375rem] font-medium transition-colors',
                  appearance === key ? 'bg-[var(--color-surface)] text-[var(--color-ink)] shadow-[var(--shadow-card)]' : 'text-[var(--color-ink-muted)]',
                )}
              >
                <Icon className="size-4" aria-hidden />
                {t(`nav.appearance.${key}`)}
              </button>
            ))}
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-3">
          {!isSuperAdmin && <div className="mb-3"><WorkspaceSwitcher onDone={close} /></div>}

          <nav aria-label={t('nav.menu')} className="space-y-3">
            {cardGroups.map((group, index) => (
              <ul key={index} className="grid grid-cols-3 gap-2.5">
                {group.map(({ to, labelKey, Icon, badge }) => (
                  <li key={to} className="min-w-0">
                    <NavLink
                      to={to}
                      end={to === '/dashboard'}
                      onClick={close}
                      data-testid={`more-card-${to.slice(1)}`}
                      className={({ isActive }) => cn(
                        'relative flex min-h-[5.75rem] flex-col items-start justify-between gap-2 rounded-2xl border p-3 transition-transform active:scale-[0.97]',
                        isActive ? 'border-[var(--brand)] bg-[var(--brand-soft)]' : 'border-[var(--color-line)] bg-[var(--color-surface)]',
                      )}
                    >
                      <span className="grid size-9 place-items-center rounded-xl bg-[var(--brand-soft)] text-[var(--brand-text)]">
                        <Icon className="size-[1.125rem]" aria-hidden />
                      </span>
                      <span className="line-clamp-2 w-full text-[0.9375rem] font-semibold leading-snug">{t(labelKey)}</span>
                      {badge !== undefined && badge > 0 && (
                        <span className="tabular absolute end-2 top-2 min-w-5 rounded-full bg-[var(--color-status-lost)] px-1.5 text-center text-[0.8125rem] font-semibold text-white">{badge > 99 ? '99+' : badge}</span>
                      )}
                    </NavLink>
                  </li>
                ))}
              </ul>
            ))}
          </nav>

          {adminRows.length > 0 && (
            <section className="mt-4">
              <h2 className="mb-1.5 px-1 text-[0.875rem] font-semibold text-[var(--color-ink-muted)]">{t('nav.administration')}</h2>
              <ul className="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)]">
                {adminRows.map(({ to, labelKey, Icon }) => (
                  <li key={to} className="border-b border-[var(--color-line)] last:border-0">
                    <NavLink
                      to={to}
                      onClick={close}
                      data-testid={`more-row-${to.slice(1)}`}
                      className={({ isActive }) => cn('flex min-h-[3.25rem] items-center gap-3 px-4 text-[1rem] font-medium active:bg-[var(--color-surface-muted)]', isActive && 'text-[var(--brand-text)]')}
                    >
                      <Icon className="size-5 shrink-0 text-[var(--color-ink-muted)]" aria-hidden />
                      <span className="flex-1 truncate">{t(labelKey)}</span>
                      <ChevronRight className="size-4 shrink-0 text-[var(--color-ink-muted)] rtl:rotate-180" aria-hidden />
                    </NavLink>
                  </li>
                ))}
              </ul>
            </section>
          )}

          <button
            type="button"
            onClick={() => { close(); void logout() }}
            data-testid="more-logout"
            className="mt-4 flex min-h-[3.25rem] w-full items-center gap-3 rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] px-4 text-start text-[1rem] font-medium text-[var(--color-status-lost)] active:bg-[var(--color-surface-muted)]"
          >
            <LogOut className="size-5 shrink-0" aria-hidden />
            {t('auth.signOut')}
          </button>

          {branding?.support_email && <a href={`mailto:${branding.support_email}`} className="mt-3 block text-center text-[0.875rem] text-[var(--color-ink-muted)]">{branding.support_email}</a>}
        </div>
        </>}
      </aside>
    </>
  )
}
