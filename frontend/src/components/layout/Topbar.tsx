import { useState } from 'react'
import { HOME } from '@/lib/routes'
import { NavLink, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, ChevronDown, LogOut, User as UserIcon, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { CommandPalette } from '@/components/layout/CommandPalette'
import { WorkspaceSwitcher } from '@/components/layout/WorkspaceSwitcher'
import { CompanySwitcher } from '@/components/company/CompanySwitcher'
import { ProjectSwitcher } from '@/components/project/ProjectSwitcher'
import { BrandLockup } from '@/components/brand'
import { useNavigation } from '@/components/layout/navigation'
import { apiGet, apiPost } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { applyLocale } from '@/i18n'
import { formatRelative, initials } from '@/lib/format'
import { Dropdown, DropdownItem } from '@/components/ui'
import type { AppNotification } from '@/types/api'
import { cn } from '@/lib/cn'

export function Topbar() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)
  const logout = useAuth((s) => s.logout)
  const appearance = useUi((s) => s.appearance)
  const setAppearance = useUi((s) => s.setAppearance)
  const [notificationsOpen, setNotificationsOpen] = useState(false)

  // Polling, not WebSockets: simpler to operate and enough for the MVP.
  const { data } = useQuery({
    queryKey: ['notifications'],
    queryFn: () => apiGet<{ notifications: AppNotification[]; unread_count: number }>('/notifications'),
    refetchInterval: 60_000,
  })

  const markRead = useMutation({
    mutationFn: (id: string) => apiPost(`/notifications/${id}/read`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const markAllRead = useMutation({
    mutationFn: () => apiPost('/notifications/read-all'),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const unread = data?.unread_count ?? 0

  async function handleSignOut() {
    await logout()
    navigate('/login', { replace: true })
  }

  const { primary } = useNavigation()

  return (
    /*
     * The floating header: one white capsule on the workspace, holding the
     * brand, the primary destinations and the person's own controls. Sticky
     * with an offset rather than pinned to the edge, so it keeps floating when
     * the page scrolls under it.
     */
    <header
      className="app-bar sticky top-0 z-20 -mx-2 -mt-2 flex h-14 items-center gap-2 bg-[var(--color-surface)] px-3 shadow-[0_1px_0_var(--color-line)] sm:top-3 sm:mx-0 sm:mt-0 sm:gap-3 sm:rounded-[var(--radius-pill)] sm:border sm:border-[var(--color-line)] sm:shadow-[var(--shadow-card)] lg:h-[4.25rem] lg:px-4 print:hidden"
      data-testid="app-header"
    >
      {/* The console's header is dark in either theme, so it takes the reverse logo. */}
      <BrandLockup to={HOME} size="md" variant={isSuperAdmin ? 'reverse' : 'auto'} onDark={isSuperAdmin} />

      {/*
        The console says so in words as well as in colour. Colour alone is the
        kind of cue that stops working on the day it matters — a tired operator,
        a projector, a screenshot pasted into a ticket.
      */}
      {isSuperAdmin && (
        <span className="hidden rounded-full bg-[var(--brand-soft)] px-3 py-1 text-[0.875rem] font-medium text-[var(--brand)] sm:inline" data-testid="console-badge">
          {t('nav.platformConsole')}
        </span>
      )}

      {/* The primary destinations, as a pill track with a white active pill. */}
      <nav
        aria-label={t('nav.primary')}
        className="mx-auto hidden items-center gap-1 rounded-[var(--radius-pill)] bg-[var(--color-chrome-fill)] p-1.5 lg:flex"
        data-testid="primary-nav"
      >
        {primary.map(({ to, labelKey }) => (
          <NavLink
            key={to}
            to={to}
            end={to === '/dashboard'}
            className={({ isActive }) => cn('nav-pill', isActive && 'nav-pill-active')}
          >
            {t(labelKey)}
          </NavLink>
        ))}
      </nav>

      <div className="flex-1 lg:hidden" />

      {/* Holding → company: what every screen below is about. */}
      <CompanySwitcher />
      <ProjectSwitcher />

      {/* The one control used more than once a day; it answers to ⌘K anyway. */}
      <CommandPalette />

      {/* Notifications */}
      <div className="relative">
        <button
          type="button"
          onClick={() => setNotificationsOpen((v) => !v)}
          aria-label={t('nav.notifications')}
          data-testid="notifications-toggle"
          className="chrome-circle relative"
        >
          <Bell className="size-[1.125rem]" aria-hidden />
          {unread > 0 && (
            <span className="absolute end-1.5 top-1.5 size-2 rounded-full bg-[var(--color-status-lost)] ring-2 ring-[var(--color-surface)]" />
          )}
        </button>

        {notificationsOpen && (
          <>
            <button
              type="button"
              aria-label={t('common.close')}
              className="fixed inset-0 z-30 cursor-default"
              onClick={() => setNotificationsOpen(false)}
            />
            <div data-testid="notification-center" className="absolute end-0 z-40 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-[var(--radius-card)] border border-[var(--color-border)] bg-[var(--color-surface)] shadow-[var(--shadow-pop)] max-lg:fixed max-lg:inset-0 max-lg:mt-0 max-lg:flex max-lg:w-full max-lg:flex-col max-lg:rounded-none max-lg:pt-[env(safe-area-inset-top)] max-lg:pb-[env(safe-area-inset-bottom)]">
              <div className="flex items-center justify-between border-b border-[var(--color-border)] px-4 py-2.5">
                <h3 className="text-[0.9375rem] font-semibold max-lg:text-[1.125rem]">{t('notifications.title')}</h3>
                {unread > 0 && (
                  <button
                    type="button"
                    onClick={() => markAllRead.mutate()}
                    className="text-[0.875rem] text-[var(--brand)] hover:underline"
                  >
                    {t('notifications.markAllRead')}
                  </button>
                )}
                <button type="button" aria-label={t('common.close')} onClick={() => setNotificationsOpen(false)} className="grid size-11 place-items-center lg:hidden" data-testid="notifications-close">
                  <X className="size-5" aria-hidden />
                </button>
              </div>

              <div className="max-h-[22rem] overflow-y-auto max-lg:max-h-none max-lg:min-h-0 max-lg:flex-1">
                {(data?.notifications ?? []).length === 0 ? (
                  <p className="px-4 py-8 text-center text-[0.9375rem] text-[var(--color-ink-muted)]">
                    {t('notifications.empty')}
                  </p>
                ) : (
                  [...(data?.notifications ?? [])].sort((a, b) => Number(b.data.severity === 'critical') - Number(a.data.severity === 'critical')).map((notification) => (
                    <button
                      key={notification.id}
                      type="button"
                      onClick={() => {
                        setNotificationsOpen(false)
                        if (!notification.read_at) markRead.mutate(notification.id)
                        if (notification.data.lead_id) navigate(`/leads/${notification.data.lead_id}`)
                        else if (notification.data.link) navigate(notification.data.link)
                      }}
                      className={cn(
                        'block w-full border-b border-[var(--color-border)] px-4 py-3 text-start last:border-0',
                        'hover:bg-[var(--color-surface-muted)]',
                        !notification.read_at && 'bg-[var(--brand-soft)]',
                        // The few that matter are marked, not louder in volume.
                        notification.data.severity === 'critical' && 'border-s-4 border-s-[var(--color-danger)]',
                        notification.data.severity === 'warning' && 'border-s-4 border-s-[var(--color-warning)]',
                      )}
                      data-testid="notification-item"
                      data-type={notification.type}
                      data-kind={notification.data.kind}
                    >
                      <p className="text-[0.9375rem] font-medium">
                        {notification.data.title ?? t(`notifications.${notification.type}`, { defaultValue: '' })}
                      </p>
                      {notification.data.body && (
                        <p className="mt-0.5 truncate text-[0.875rem] text-[var(--color-ink-muted)] max-lg:whitespace-normal">
                          {notification.data.body}
                        </p>
                      )}
                      <p className="mt-1 text-[0.8125rem] text-[var(--color-ink-subtle)]">
                        {formatRelative(notification.created_at, i18n.language)}
                      </p>
                    </button>
                  ))
                )}
              </div>
            </div>
          </>
        )}
      </div>

      {/* Account */}
      <Dropdown
        trigger={({ toggle }) => (
          <button
            type="button"
            onClick={toggle}
            data-testid="account-menu"
            /* The name is the label on a wide screen and hidden on a phone, where
               the avatar beside it is decorative — so below `sm` this button had
               no accessible name at all. */
            aria-label={user?.name ?? t('nav.profile')}
            className="flex items-center gap-1 rounded-full p-0.5 hover:bg-[var(--color-chrome-fill)]"
          >
            <span
              className="grid size-10 place-items-center rounded-full text-[0.9375rem] font-semibold text-white"
              style={{ background: 'var(--brand)' }}
              aria-hidden
            >
              {user ? initials(user.name) : <UserIcon className="size-4" />}
            </span>
            <ChevronDown className="hidden size-3.5 text-[var(--color-ink-subtle)] sm:block" aria-hidden />
          </button>
        )}
      >
        {({ close }) => (
          <>
            <div className="border-b border-[var(--color-border)] px-3 py-2">
              <p className="truncate text-[0.9375rem] font-medium">{user?.name}</p>
              <p className="truncate text-[0.875rem] text-[var(--color-ink-muted)]">{user?.email}</p>
              <p className="mt-1 text-[0.8125rem] text-[var(--color-ink-subtle)]">
                {t(`roles.${user?.role ?? 'sales'}`)}
              </p>
            </div>
            <DropdownItem
              icon={<UserIcon className="size-4" />}
              onClick={() => {
                close()
                navigate('/settings/profile')
              }}
            >
              {t('nav.profile')}
            </DropdownItem>
            {!isSuperAdmin && <WorkspaceSwitcher onDone={close} />}
            <div className="border-t border-[var(--color-line)] px-3 py-2" role="group" aria-label={t('settings.language')}>
              <p className="mb-1 text-[0.8125rem] text-[var(--color-ink-subtle)]">{t('settings.language')}</p>
              <div className="flex gap-1">
                {(['ar', 'en'] as const).map((locale) => (
                  <button
                    key={locale}
                    type="button"
                    data-testid={`language-${locale}`}
                    aria-pressed={i18n.language === locale}
                    onClick={() => {
                      applyLocale(locale)
                      close()
                    }}
                    className={cn('segment', i18n.language === locale && 'segment-active')}
                  >
                    {locale === 'ar' ? 'العربية' : 'English'}
                  </button>
                ))}
              </div>
            </div>
            <div className="border-t border-[var(--color-line)] px-3 py-2" role="group" aria-label={t('appearance.label')} data-testid="appearance-menu">
              <p className="mb-1 text-[0.8125rem] text-[var(--color-ink-subtle)]">{t('appearance.label')}</p>
              <div className="flex gap-1">
                {(['system', 'light', 'dark'] as const).map((option) => (
                  <button
                    key={option}
                    type="button"
                    data-testid={`appearance-${option}`}
                    aria-pressed={appearance === option}
                    onClick={() => {
                      setAppearance(option)
                      close()
                    }}
                    className={cn('segment', appearance === option && 'segment-active')}
                  >
                    {t(`appearance.${option}`)}
                  </button>
                ))}
              </div>
            </div>
            <DropdownItem danger icon={<LogOut className="size-4" />} onClick={handleSignOut} testId="sign-out">
              {t('auth.signOut')}
            </DropdownItem>
          </>
        )}
      </Dropdown>
    </header>
  )
}
