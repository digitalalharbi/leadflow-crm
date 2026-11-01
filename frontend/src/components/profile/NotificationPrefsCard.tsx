import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, BellRing, Mail, MailWarning, type LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, CardHeader, Skeleton } from '@/components/ui'
import { fetchNotificationPrefs, saveNotificationPrefs, type AlertCategory, type AlertChannel, type AlertMode, type DigestKind, type NotificationPrefs, type WhatsappChanges } from '@/features/notificationPrefs'
import { WhatsappAlertsSection } from '@/components/profile/WhatsappAlertsSection'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { cn } from '@/lib/cn'

const CHANNELS: Array<{ key: AlertChannel; Icon: LucideIcon }> = [{ key: 'in_app', Icon: Bell }, { key: 'email', Icon: Mail }]

/**
 * How this person wants to be told: each kind of alert at once, in the daily or weekly summary,
 * or not at all — which summaries arrive (daily, weekly, monthly) — and whether the critical few
 * also come to their WhatsApp. Every press saves; there is no form to forget.
 */
export function NotificationPrefsCard() {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const queryClient = useQueryClient()

  const query = useQuery({ queryKey: ['notification-prefs'], queryFn: fetchNotificationPrefs, staleTime: Infinity })

  /*
   * What is on screen is local state, seeded once from the server. Each press changes it at
   * once and is sent in order; the server's replies are not read back, so a slow answer to one
   * press can never roll back the next. A failed save puts the server's truth back.
   */
  const [data, setData] = useState<NotificationPrefs | null>(null)

  useEffect(() => {
    if (query.data && !data) setData(query.data)
  }, [query.data, data])

  const save = useMutation({
    mutationKey: ['notification-prefs'],
    scope: { id: 'notification-prefs' },
    mutationFn: saveNotificationPrefs,
    // The cache is never shown; it only remembers the last thing the server confirmed, for the case below.
    onSuccess: (confirmed) => queryClient.setQueryData(['notification-prefs'], confirmed),
    onError: (caught) => {
      toast(translateError(caught), 'error')
      // Put the server's truth back. If the server cannot be reached (offline), the last confirmed copy
      // is the truth — never leave on screen a choice that was refused.
      void query.refetch().then((fresh) => {
        const truth = fresh.data ?? queryClient.getQueryData<NotificationPrefs>(['notification-prefs'])
        if (truth) setData(truth)
      })
    },
  })

  if (!data) return <Card><CardBody><Skeleton className="h-48" /></CardBody></Card>

  const setMode = (category: AlertCategory, mode: AlertMode) => {
    setData({ ...data, alerts: { ...data.alerts, [category]: mode } })
    save.mutate({ alerts: { [category]: mode } })
  }

  const setChannel = (category: AlertCategory, channel: AlertChannel, on: boolean) => {
    const current = data.channels?.[category] ?? { in_app: true, email: true }
    setData({ ...data, channels: { ...(data.channels ?? {}), [category]: { ...current, [channel]: on } } as NotificationPrefs['channels'] })
    save.mutate({ channels: { [category]: { [channel]: on } } })
  }

  const setDigest = (digest: DigestKind, on: boolean) => {
    setData({ ...data, digests: { ...data.digests, [digest]: on } })
    save.mutate({ digests: { [digest]: on } })
  }

  /** WhatsApp changes are applied on screen at once, as the server will apply them, and sent in order. */
  const setWhatsapp = (changes: WhatsappChanges) => {
    // From the latest state, not the one this render saw: two quick edits (start, then end) must both land.
    setData((current) => {
      if (!current) return current

      const w = current.whatsapp
      const quiet = changes.quiet === undefined ? w.quiet : changes.quiet === null ? { ...w.quiet, source: 'workspace' as const } : { enabled: changes.quiet.enabled, start: changes.quiet.start ?? w.quiet.start, end: changes.quiet.end ?? w.quiet.end, source: 'own' as const }

      return { ...current, whatsapp: { ...w, enabled: changes.enabled ?? w.enabled, alerts: { ...w.alerts, ...changes.alerts }, quiet } }
    })
    save.mutate({ whatsapp: changes })
  }

  return (
    <Card className="lg:col-span-2" data-testid="notification-prefs">
      <CardHeader title={t('alerts.title')} description={t('alerts.subtitle', { email: data.email })} />
      <CardBody className="space-y-4">
        {!data.mail_configured && (
          <p className="t-small flex items-start gap-2 rounded-xl bg-[color-mix(in_srgb,var(--color-warning)_12%,transparent)] p-2.5" data-testid="alerts-mail-off">
            <MailWarning className="mt-0.5 size-4 shrink-0 text-[var(--color-warning)]" aria-hidden />
            {t('alerts.mailOff')}
          </p>
        )}

        <ul className="divide-y divide-[var(--color-line)]" data-testid="alert-rows">
          {data.categories.map((category) => (
            <li key={category} className="flex flex-wrap items-center justify-between gap-2 py-2" data-testid={`alert-${category}`}>
              <span className="flex items-center gap-2 text-[0.9375rem] font-medium"><BellRing className="size-4 text-[var(--color-ink-muted)]" aria-hidden />{t(`alerts.category.${category}`)}</span>
              <span className="ms-auto inline-flex gap-1" role="group" aria-label={t('alerts.channels')}>
                {CHANNELS.map(({ key, Icon }) => {
                  const on = data.channels?.[category]?.[key] ?? true
                  return (
                    <button
                      key={key}
                      type="button"
                      aria-pressed={on}
                      title={t(`alerts.channel.${key}`)}
                      aria-label={`${t(`alerts.channel.${key}`)} — ${t(`alerts.category.${category}`)}`}
                      data-testid={`alert-${category}-channel-${key}`}
                      onClick={() => setChannel(category, key, !on)}
                      className={cn(
                        'pressable grid size-7 place-items-center rounded-full',
                        on ? 'bg-[var(--brand-soft)] text-[var(--brand)]' : 'text-[var(--color-ink-muted)] line-through opacity-60',
                      )}
                    >
                      <Icon className="size-3.5" aria-hidden />
                    </button>
                  )
                })}
              </span>
              <div role="group" aria-label={t(`alerts.category.${category}`)} className="inline-flex gap-0.5 rounded-full bg-[var(--color-chrome-fill)] p-0.5">
                {data.modes.map((mode) => (
                  <button
                    key={mode}
                    type="button"
                    aria-pressed={data.alerts[category] === mode}
                    data-testid={`alert-${category}-${mode}`}
                    onClick={() => setMode(category, mode)}
                    className={cn(
                      'pressable rounded-full px-2.5 py-0.5 text-[0.875rem] font-semibold',
                      data.alerts[category] === mode ? 'bg-[var(--color-surface)] shadow-[var(--shadow-card)]' : 'text-[var(--color-ink-muted)]',
                    )}
                  >
                    {t(`alerts.mode.${mode}`)}
                  </button>
                ))}
              </div>
            </li>
          ))}
        </ul>

        <div className="space-y-1" data-testid="digest-rows">
          <h3 className="t-small font-semibold">{t('alerts.digests')}</h3>
          {data.digest_kinds.map((digest) => (
            <label key={digest} className="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-2 py-1.5 hover:bg-[var(--color-surface-muted)]">
              <span>
                <span className="t-small block font-medium">{t(`alerts.digest.${digest}`)}</span>
                <span className="t-caption block">{t(`alerts.digestHint.${digest}`)}</span>
              </span>
              <input type="checkbox" role="switch" checked={data.digests[digest]} data-testid={`digest-${digest}`} onChange={(e) => setDigest(digest, e.target.checked)} className="size-4 accent-[var(--brand)]" />
            </label>
          ))}
        </div>

        <WhatsappAlertsSection prefs={data.whatsapp} onChange={setWhatsapp} />
      </CardBody>
    </Card>
  )
}
