import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { MessageCircle, MoonStar, RotateCcw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui'
import { fetchWhatsappLog, type StaffCategory, type StaffMode, type WhatsappChanges, type WhatsappPrefs } from '@/features/notificationPrefs'
import { formatLastSeen } from '@/lib/format'
import { cn } from '@/lib/cn'

/**
 * WhatsApp alerts to this person's own phone — optional, off until they switch it on, and only the
 * six critical kinds. Each category is instant or off; quiet hours and the limit keep it from
 * becoming a siren, and what was held back (and why) is listed below, so "why did I not get it?"
 * has an answer on the same screen.
 */
export function WhatsappAlertsSection({ prefs, onChange }: { prefs: WhatsappPrefs; onChange: (changes: WhatsappChanges) => void }) {
  const { t, i18n } = useTranslation()
  const [showLog, setShowLog] = useState(false)
  const log = useQuery({ queryKey: ['whatsapp-alert-log'], queryFn: fetchWhatsappLog, enabled: showLog && prefs.enabled, staleTime: 15_000 })

  const blocked = !prefs.has_phone ? 'noPhone' : !prefs.available ? 'notConnected' : null

  return (
    <div className="space-y-3 rounded-xl border border-[var(--color-line)] p-3" data-testid="whatsapp-alerts">
      <label className="flex cursor-pointer items-center justify-between gap-3">
        <span className="flex items-center gap-2">
          <span className="grid size-9 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden><MessageCircle className="size-5" /></span>
          <span>
            <span className="t-small block font-semibold">{t('alerts.whatsapp.title')}</span>
            <span className="t-caption block">{t('alerts.whatsapp.hint')}</span>
          </span>
        </span>
        <input type="checkbox" role="switch" checked={prefs.enabled} data-testid="whatsapp-enabled" onChange={(e) => onChange({ enabled: e.target.checked })} className="size-4 shrink-0 accent-[var(--brand)]" />
      </label>

      {blocked && <p className="t-caption rounded-lg bg-[color-mix(in_srgb,var(--color-warning)_12%,transparent)] px-2.5 py-1.5" data-testid="whatsapp-blocked">{t(`alerts.whatsapp.${blocked}`)}</p>}
      {!blocked && !prefs.live && <p className="t-caption" data-testid="whatsapp-demo">{t('alerts.whatsapp.demo')}</p>}

      {prefs.enabled && (
        <>
          <ul className="divide-y divide-[var(--color-line)]" data-testid="whatsapp-rows">
            {prefs.categories.map((category: StaffCategory) => (
              <li key={category} className="flex flex-wrap items-center justify-between gap-2 py-1.5" data-testid={`whatsapp-${category}`}>
                <span className="text-[0.9375rem] font-medium">{t(`alerts.category.${category}`)}</span>
                <div role="group" aria-label={t(`alerts.category.${category}`)} className="inline-flex gap-0.5 rounded-full bg-[var(--color-chrome-fill)] p-0.5">
                  {prefs.modes.map((mode: StaffMode) => (
                    <button
                      key={mode}
                      type="button"
                      aria-pressed={prefs.alerts[category] === mode}
                      data-testid={`whatsapp-${category}-${mode}`}
                      onClick={() => onChange({ alerts: { [category]: mode } })}
                      className={cn('pressable rounded-full px-2.5 py-0.5 text-[0.875rem] font-semibold', prefs.alerts[category] === mode ? 'bg-[var(--color-surface)] shadow-[var(--shadow-card)]' : 'text-[var(--color-ink-muted)]')}
                    >
                      {t(`alerts.mode.${mode}`)}
                    </button>
                  ))}
                </div>
              </li>
            ))}
          </ul>

          <div>
            <button type="button" aria-expanded={showLog} data-testid="whatsapp-log-toggle" onClick={() => setShowLog((v) => !v)} className="t-small font-medium text-[var(--brand)] underline">{t('alerts.whatsapp.recent')}</button>
            {showLog && (
              <ul className="mt-1.5 space-y-1" data-testid="whatsapp-log">
                {(log.data?.log ?? []).length === 0 ? <li className="t-caption">{t('alerts.whatsapp.emptyLog')}</li> : (log.data?.log ?? []).map((row) => (
                  <li key={row.id} data-testid={`whatsapp-log-${row.status}`} className="flex flex-wrap items-center gap-2 rounded-lg bg-[var(--color-surface-muted)] px-2.5 py-1 text-[0.8125rem]">
                    <Badge tone={row.status === 'sent' ? 'success' : row.status === 'failed' ? 'danger' : 'neutral'}>{t(`alerts.whatsapp.status.${row.status}`)}</Badge>
                    <span className="min-w-0 flex-1 truncate">{t(`alerts.category.${row.category}`)} — {row.title}</span>
                    {row.reason && <span className="t-caption">{t(`alerts.whatsapp.reason.${row.reason}`)}</span>}
                    {row.at && <span className="t-caption">{formatLastSeen(row.at, i18n.language)}</span>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </>
      )}

      {/* Quiet hours hold back ordinary emails too, so they do not depend on WhatsApp being on. */}
      <div className="space-y-2" data-testid="whatsapp-quiet">
        <label className="flex cursor-pointer items-center justify-between gap-3">
          <span className="flex items-center gap-2 text-[0.9375rem] font-medium"><MoonStar className="size-4 text-[var(--color-ink-muted)]" aria-hidden />{t('alerts.whatsapp.quiet')}</span>
          <input type="checkbox" role="switch" checked={prefs.quiet.enabled} data-testid="whatsapp-quiet-enabled" onChange={(e) => onChange({ quiet: { enabled: e.target.checked } })} className="size-4 accent-[var(--brand)]" />
        </label>
        {prefs.quiet.enabled && (
          <div className="flex flex-wrap items-center gap-3 ps-6">
            <label className="flex items-center gap-1.5 text-[0.875rem]">
              {t('alerts.whatsapp.from')}
              <input type="time" dir="ltr" value={prefs.quiet.start} data-testid="whatsapp-quiet-start" onChange={(e) => e.target.value && onChange({ quiet: { enabled: true, start: e.target.value } })} className="field !h-10 !w-auto" />
            </label>
            <label className="flex items-center gap-1.5 text-[0.875rem]">
              {t('alerts.whatsapp.to')}
              <input type="time" dir="ltr" value={prefs.quiet.end} data-testid="whatsapp-quiet-end" onChange={(e) => e.target.value && onChange({ quiet: { enabled: true, end: e.target.value } })} className="field !h-10 !w-auto" />
            </label>
            <Badge tone="neutral" testId="whatsapp-quiet-source">{t(`alerts.whatsapp.source.${prefs.quiet.source}`)}</Badge>
            {prefs.quiet.source === 'own' && (
              <button type="button" aria-label={t('alerts.whatsapp.useWorkspace')} title={t('alerts.whatsapp.useWorkspace')} data-testid="whatsapp-quiet-reset" onClick={() => onChange({ quiet: null })} className="pressable grid size-7 place-items-center rounded-full hover:bg-[var(--color-chrome-fill)]">
                <RotateCcw className="size-3.5" aria-hidden />
              </button>
            )}
          </div>
        )}
        <p className="t-caption">{t('alerts.whatsapp.limits')}</p>
      </div>
    </div>
  )
}
