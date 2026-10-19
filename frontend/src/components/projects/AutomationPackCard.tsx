import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Check, Circle, Pause, Play, Sparkles } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, Input, Select } from '@/components/ui'
import { RingProgress } from '@/components/visual'
import { activateAutomationPack, completeAutomationPack, fetchAutomationPack, pauseAutomationPack, type Cadence, type PackStep } from '@/features/automation'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { cn } from '@/lib/cn'

/** Where each step is answered, when it is not answered here. */
const WHERE: Partial<Record<PackStep['key'], string>> = {
  brochure: '?tab=files',
  qualification: '?tab=questions',
  sales_team: '/users',
  whatsapp_template: '/integrations',
  email_template: '/integrations',
}

/**
 * The eleven answers that make the journey this project's own, as a ring and
 * a checklist — and the four that are choices (how fast it follows up, how
 * quickly the first call must happen, when it keeps quiet) set right here.
 * "Activate Automation" fills what defaults can fill, switches the journey on
 * and lets this project's leads be contacted; pausing stops only the messages.
 */
export function AutomationPackCard({ projectId }: { projectId: string }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [service, setService] = useState('')
  const [language, setLanguage] = useState('ar')
  const [draft, setDraft] = useState<{ cadence?: Cadence; sla?: string; quiet?: { enabled: boolean; start: string; end: string } }>({})

  const { data } = useQuery({ queryKey: ['automation-pack', projectId], queryFn: () => fetchAutomationPack(projectId) })

  const onDone = (message: string) => (result: { pack: unknown }) => {
    queryClient.setQueryData(['automation-pack', projectId], result)
    setDraft({})
    toast(t(message), 'success')
  }
  const onError = (caught: unknown) => toast(translateError(caught), 'error')

  const complete = useMutation({
    mutationFn: () => completeAutomationPack(projectId, { service: service || undefined, language }),
    onSuccess: onDone('pack.completed'),
    onError,
  })

  const saveSettings = useMutation({
    mutationFn: () => completeAutomationPack(projectId, {
      cadence: draft.cadence,
      sla_minutes: draft.sla === undefined ? undefined : draft.sla === '' ? null : Number(draft.sla),
      quiet_hours: draft.quiet,
    }),
    onSuccess: onDone('pack.saved'),
    onError,
  })

  const activate = useMutation({
    mutationFn: () => activateAutomationPack(projectId, { service: service || undefined, language }),
    onSuccess: onDone('pack.activated'),
    onError,
  })

  const pause = useMutation({ mutationFn: () => pauseAutomationPack(projectId), onSuccess: onDone('pack.paused'), onError })

  if (!data) return null

  const settings = data.pack.settings
  const quiet = draft.quiet ?? { enabled: settings.quiet_hours?.enabled ?? false, start: settings.quiet_hours?.start ?? '21:00', end: settings.quiet_hours?.end ?? '08:00' }
  const cadence = draft.cadence ?? settings.cadence
  const sla = draft.sla ?? (settings.sla_minutes ? String(settings.sla_minutes) : '')

  const pack = data.pack
  const percent = Math.round((pack.done / pack.total) * 100)
  const needsService = !pack.steps.find((s) => s.key === 'service')?.done

  return (
    <Card data-testid="automation-pack">
      <CardBody className="grid gap-4 md:grid-cols-[auto_1fr]">
        <div className="flex flex-col items-center gap-2">
          <RingProgress value={percent} size={108} thickness={10} tone={percent === 100 ? 'good' : 'warn'} label={t('pack.title')}>
            <span className="text-[1.375rem] font-extrabold tabular-nums" dir="ltr">{pack.done}/{pack.total}</span>
          </RingProgress>
          <span data-testid="pack-active" data-active={pack.active} className={cn('rounded-full px-2.5 py-0.5 text-[0.9375rem] font-bold', pack.active ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[color-mix(in_srgb,var(--color-warning)_20%,transparent)] text-[var(--color-warning)]')}>
            {t(pack.active ? 'pack.active' : 'pack.paused_label')}
          </span>
          <span className={cn('rounded-full px-2.5 py-0.5 text-[0.8125rem] font-semibold', pack.journey_on ? 'bg-[color-mix(in_srgb,var(--brand)_12%,transparent)] text-[var(--brand)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')}>
            {t(pack.journey_on ? 'pack.journeyOn' : 'pack.journeyOff')}
          </span>
        </div>

        <div className="space-y-3">
          <div>
            <h2 className="t-card-title flex items-center gap-2"><Sparkles className="size-4 text-[var(--brand)]" aria-hidden />{t('pack.title')}</h2>
            <p className="t-caption">{t('pack.hint')}</p>
          </div>

          <ul className="grid gap-1.5 sm:grid-cols-2">
            {pack.steps.map((step) => {
              const where = WHERE[step.key]
              const label = (
                <>
                  <span className={cn('grid size-5 shrink-0 place-items-center rounded-full', step.done ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'border border-[var(--color-border-strong)] text-[var(--color-ink-subtle)]')} aria-hidden>
                    {step.done ? <Check className="size-3" /> : <Circle className="size-2" />}
                  </span>
                  <span className="min-w-0 flex-1 truncate text-[0.9375rem] font-medium">{t(`pack.step.${step.key}`)}</span>
                  {step.detail && <span className="truncate text-[0.8125rem] text-[var(--color-ink-muted)]">{t(`pack.detail.${step.detail}`, { defaultValue: step.detail })}</span>}
                </>
              )

              return (
                <li key={step.key} data-testid={`pack-${step.key}`} data-done={step.done}>
                  {where && !step.done ? (
                    <Link to={where.startsWith('?') ? where : where} className="flex items-center gap-2 rounded-xl bg-[var(--color-surface-muted)] px-2.5 py-2 hover:bg-[var(--color-chrome-fill)]">{label}</Link>
                  ) : (
                    <div className="flex items-center gap-2 rounded-xl bg-[var(--color-surface-muted)] px-2.5 py-2">{label}</div>
                  )}
                </li>
              )
            })}
          </ul>

          {/* The four choices: pace, target, quiet window. */}
          <div className="space-y-2 rounded-xl bg-[var(--color-surface-muted)] p-3" data-testid="pack-settings">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-[0.9375rem] font-semibold">{t('pack.step.cadence')}</span>
              {(['gentle', 'standard', 'intensive'] as const).map((key) => (
                <button
                  key={key}
                  type="button"
                  aria-pressed={cadence === key}
                  data-testid={`pack-cadence-${key}`}
                  onClick={() => setDraft((d) => ({ ...d, cadence: key }))}
                  className={cn('pressable rounded-full border px-3 py-1 text-[0.9375rem]', cadence === key ? 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-contrast)]' : 'border-[var(--color-border-strong)]')}
                >
                  {t(`pack.cadence.${key}`)}
                </button>
              ))}
            </div>
            <div className="flex flex-wrap items-end gap-2">
              <Input className="w-40" type="number" min={1} max={10080} label={t('pack.slaLabel')} value={sla} data-testid="pack-sla-input" onChange={(e) => setDraft((d) => ({ ...d, sla: e.target.value }))} />
              <button
                type="button"
                aria-pressed={quiet.enabled}
                data-testid="pack-quiet-toggle"
                onClick={() => setDraft((d) => ({ ...d, quiet: { ...quiet, enabled: !quiet.enabled } }))}
                className={cn('pressable h-10 rounded-full border px-3 text-[0.9375rem]', quiet.enabled ? 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-contrast)]' : 'border-[var(--color-border-strong)]')}
              >
                {t('pack.step.quiet_hours')}
              </button>
              {quiet.enabled && (
                <>
                  <Input className="w-28" type="time" dir="ltr" aria-label={t('pack.quietFrom')} value={quiet.start} onChange={(e) => setDraft((d) => ({ ...d, quiet: { ...quiet, start: e.target.value } }))} />
                  <Input className="w-28" type="time" dir="ltr" aria-label={t('pack.quietTo')} value={quiet.end} onChange={(e) => setDraft((d) => ({ ...d, quiet: { ...quiet, end: e.target.value } }))} />
                </>
              )}
              <Button variant="secondary" size="sm" disabled={Object.keys(draft).length === 0} loading={saveSettings.isPending} data-testid="pack-save-settings" onClick={() => saveSettings.mutate()}>
                {t('common.save')}
              </Button>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {pack.active ? (
              <Button variant="secondary" loading={pause.isPending} data-testid="pause-automation" onClick={() => pause.mutate()}>
                <Pause className="size-4" aria-hidden />
                {t('pack.pause')}
              </Button>
            ) : (
              <Button loading={activate.isPending} data-testid="activate-automation" onClick={() => activate.mutate()}>
                <Play className="size-4" aria-hidden />
                {t('pack.activate')}
              </Button>
            )}
          </div>

          {pack.done < pack.total && (
            <div className="flex flex-wrap items-end gap-2">
              {needsService && (
                <Input className="min-w-48 flex-1" label={t('pack.step.service')} placeholder={t('pack.servicePlaceholder')} value={service} onChange={(e) => setService(e.target.value)} data-testid="pack-service-input" />
              )}
              <Select className="min-w-32" label={t('pack.step.language')} value={language} onChange={(e) => setLanguage(e.target.value)}>
                <option value="ar">{t('files.arabic')}</option>
                <option value="en">{t('files.english')}</option>
              </Select>
              <Button loading={complete.isPending} onClick={() => complete.mutate()} data-testid="complete-pack">
                {t('pack.complete')}
              </Button>
            </div>
          )}
        </div>
      </CardBody>
    </Card>
  )
}
