import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, Check, Globe, LogOut, Menu, User as UserIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
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
  const logout = useAuth((s) => s.logout)
  const toggleSidebar = useUi((s) => s.toggleSidebar)
  const [notificationsOpen, setNotificationsOpen] = useState(false)

  // Polling, not WebSockets: simpler to operate and enough for the MVP.
  const { data } = useQuery({
    queryKey: ['notifications'],
    queryFn: () => apiGet<{ notifications: AppNotification[]; unread_count: number }>('/notifications'),
    refetchInterval: 60_000,
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

  return (
    <header className="sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-[var(--color-border)] bg-[var(--color-surface)]/90 px-4 backdrop-blur">
      <button
        type="button"
        onClick={toggleSidebar}
        aria-label={t('common.actions')}
        className="grid size-9 place-items-center rounded-lg text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)] lg:hidden"
      >
        <Menu className="size-5" aria-hidden />
      </button>

      <div className="flex-1" />

      {/* Language */}
      <Dropdown
        trigger={({ toggle }) => (
          <button
            type="button"
            onClick={toggle}
            aria-label={t('settings.language')}
            className="grid size-9 place-items-center rounded-lg text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)]"
          >
            <Globe className="size-[1.125rem]" aria-hidden />
          </button>
        )}
      >
        {({ close }) => (
          <>
            {(['ar', 'en'] as const).map((locale) => (
              <DropdownItem
                key={locale}
                onClick={() => {
                  applyLocale(locale)
                  close()
                }}
                icon={
                  i18n.language === locale ? (
                    <Check className="size-4" aria-hidden />
                  ) : (
                    <span className="size-4" aria-hidden />
                  )
                }
              >
                {locale === 'ar' ? 'العربية' : 'English'}
              </DropdownItem>
            ))}
          </>
        )}
      </Dropdown>

      {/* Notifications */}
      <div className="relative">
        <button
          type="button"
          onClick={() => setNotificationsOpen((v) => !v)}
          aria-label={t('nav.notifications')}
          className="relative grid size-9 place-items-center rounded-lg text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)]"
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
            <div className="absolute end-0 z-40 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] shadow-[var(--shadow-pop)]">
              <div className="flex items-center justify-between border-b border-[var(--color-border)] px-4 py-2.5">
                <h3 className="text-[0.8125rem] font-semibold">{t('notifications.title')}</h3>
                {unread > 0 && (
                  <button
                    type="button"
                    onClick={() => markAllRead.mutate()}
                    className="text-[0.75rem] text-[var(--brand)] hover:underline"
                  >
                    {t('notifications.markAllRead')}
                  </button>
                )}
              </div>

              <div className="max-h-[22rem] overflow-y-auto">
                {(data?.notifications ?? []).length === 0 ? (
                  <p className="px-4 py-8 text-center text-[0.8125rem] text-[var(--color-ink-muted)]">
                    {t('notifications.empty')}
                  </p>
                ) : (
                  data?.notifications.map((notification) => (
                    <button
                      key={notification.id}
                      type="button"
                      onClick={() => {
                        setNotificationsOpen(false)
                        if (notification.data.lead_id) navigate(`/leads/${notification.data.lead_id}`)
                      }}
                      className={cn(
                        'block w-full border-b border-[var(--color-border)] px-4 py-3 text-start last:border-0',
                        'hover:bg-[var(--color-surface-muted)]',
                        !notification.read_at && 'bg-[var(--brand-soft)]',
                      )}
                    >
                      <p className="text-[0.8125rem] font-medium">
                        {notification.data.title ?? t(`notifications.${notification.type}`, { defaultValue: '' })}
                      </p>
                      {notification.data.body && (
                        <p className="mt-0.5 truncate text-[0.75rem] text-[var(--color-ink-muted)]">
                          {notification.data.body}
                        </p>
                      )}
                      <p className="mt-1 text-[0.6875rem] text-[var(--color-ink-subtle)]">
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
            className="flex items-center gap-2 rounded-lg p-1 ps-2 hover:bg-[var(--color-surface-sunken)]"
          >
            <span className="hidden text-[0.8125rem] font-medium sm:block">{user?.name}</span>
            <span
              className="grid size-8 place-items-center rounded-full text-[0.75rem] font-semibold text-white"
              style={{ background: 'var(--brand)' }}
              aria-hidden
            >
              {user ? initials(user.name) : <UserIcon className="size-4" />}
            </span>
          </button>
        )}
      >
        {({ close }) => (
          <>
            <div className="border-b border-[var(--color-border)] px-3 py-2">
              <p className="truncate text-[0.8125rem] font-medium">{user?.name}</p>
              <p className="truncate text-[0.75rem] text-[var(--color-ink-muted)]">{user?.email}</p>
              <p className="mt-1 text-[0.6875rem] text-[var(--color-ink-subtle)]">
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
            <DropdownItem danger icon={<LogOut className="size-4" />} onClick={handleSignOut} testId="sign-out">
              {t('auth.signOut')}
            </DropdownItem>
          </>
        )}
      </Dropdown>
    </header>
  )
}
