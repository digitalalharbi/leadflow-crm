import { useState } from 'react'
import { Monitor, Smartphone, Tablet } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { FormRenderer, type RenderForm } from '@/components/forms/FormRenderer'
import { ThankYou } from '@/components/forms/ThankYou'
import { Button } from '@/components/ui'
import { designVars } from '@/lib/formDesign'
import { cn } from '@/lib/cn'

export const DEVICES = { desktop: 'min(100%, 880px)', tablet: '768px', mobile: '375px' } as const
export type Device = keyof typeof DEVICES

/**
 * The live form, at three widths. It is the very renderer the public page uses, in preview: nothing is sent, nothing is counted, and the
 * grid answers to the width of the frame — so "mobile" here is what a phone gets, not a squeezed laptop.
 */
export function PreviewPane({ form, thanks, device, onDevice }: {
  form: RenderForm
  thanks: { title: string | null; message: string | null }
  device: Device
  onDevice: (d: Device) => void
}) {
  const { t } = useTranslation()
  const [showThanks, setShowThanks] = useState(false)
  const icons = { desktop: Monitor, tablet: Tablet, mobile: Smartphone }

  return (
    <div className="space-y-3" data-testid="preview-pane">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div role="radiogroup" aria-label={t('hub.builder.preview.device')} className="flex gap-1">
          {(Object.keys(DEVICES) as Device[]).map((d) => {
            const Icon = icons[d]

            return (
              <button key={d} type="button" role="radio" aria-checked={device === d} onClick={() => onDevice(d)} data-testid={`device-${d}`}
                className={cn('pressable inline-flex min-h-9 items-center gap-1.5 rounded-full px-3 text-[0.875rem] font-semibold', device === d ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--color-surface-muted)]')}>
                <Icon className="size-4" aria-hidden />{t(`hub.builder.preview.${d}`)}
              </button>
            )
          })}
        </div>
        <Button size="sm" variant="secondary" onClick={() => setShowThanks((v) => !v)} data-testid="preview-thanks">{showThanks ? t('hub.builder.preview.showForm') : t('hub.builder.preview.showThanks')}</Button>
      </div>
      <p className="t-caption text-[var(--color-ink-subtle)]">{t('hub.builder.preview.note')}</p>
      <div className="overflow-x-auto rounded-2xl bg-[var(--color-surface-muted)] p-3">
        <div className="mx-auto overflow-hidden rounded-2xl bg-[var(--f-canvas)] p-4" style={{ width: DEVICES[device], maxWidth: '100%', ...designVars(form.design) }} data-testid="preview-frame" data-device={device}>
          {showThanks
            ? <ThankYou preview title={thanks.title} message={thanks.message} actions={form.settings.thanks.actions} redirectUrl={null} redirectDelay={0} design={form.design} />
            : <FormRenderer form={form} preview />}
        </div>
      </div>
    </div>
  )
}
