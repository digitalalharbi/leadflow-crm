import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Check, Circle, Sparkles } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, Input, Select } from '@/components/ui'
import { RingProgress } from '@/components/visual'
import { completeAutomationPack, fetchAutomationPack, type PackStep } from '@/features/automation'
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
 * The seven answers that make the journey this project's own, as a ring and
 * a checklist. "Complete" fills what defaults can fill — a welcome on each
 * channel written for this project, starter questions — and never overwrites
 * what is already there.
 */
export function AutomationPackCard({ projectId }: { projectId: string }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [service, setService] = useState('')
  const [language, setLanguage] = useState('ar')

  const { data } = useQuery({ queryKey: ['automation-pack', projectId], queryFn: () => fetchAutomationPack(projectId) })

  const complete = useMutation({
    mutationFn: () => completeAutomationPack(projectId, { service: service || undefined, language }),
    onSuccess: (result) => {
      queryClient.setQueryData(['automation-pack', projectId], result)
      toast(t('pack.completed'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (!data) return null

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
