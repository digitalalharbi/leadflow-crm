import { Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Select, Textarea } from '@/components/ui'
import { THANK_ACTIONS, type FormSettings, type ThankAction } from '@/features/hub'

/** What the customer sees once the lead is kept: the thanks, up to four things to do next, and an optional redirect after a pause. */
export function ThanksPanel({ title, message, settings, onTitle, onMessage, onSettings }: {
  title: string; message: string; settings: FormSettings
  onTitle: (v: string) => void; onMessage: (v: string) => void; onSettings: (s: FormSettings) => void
}) {
  const { t } = useTranslation()
  const th = settings.thanks
  const setActions = (actions: ThankAction[]) => onSettings({ ...settings, thanks: { ...th, actions } })

  return (
    <div className="space-y-4" data-testid="thanks-panel">
      <Input label={t('hub.forms.thanksTitle')} value={title} onChange={(e) => onTitle(e.target.value)} data-testid="thanks-title" />
      <Textarea label={t('hub.forms.thanksMessage')} rows={3} value={message} onChange={(e) => onMessage(e.target.value)} data-testid="thanks-message" />

      <div className="space-y-2">
        <div className="flex items-center justify-between"><p className="font-semibold">{t('hub.builder.thanks.actions')}</p>
          <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} disabled={th.actions.length >= 4} onClick={() => setActions([...th.actions, { type: 'whatsapp', label: '', value: '' }])} data-testid="thanks-add-action">{t('hub.builder.thanks.addAction')}</Button>
        </div>
        {th.actions.map((a, i) => (
          <div key={i} className="grid gap-2 rounded-xl border border-[var(--color-border)] p-2" data-testid={`thanks-action-${i}`}>
            <Select aria-label={t('hub.builder.thanks.type')} value={a.type} onChange={(e) => setActions(th.actions.map((x, n) => (n === i ? { ...x, type: e.target.value as ThankAction['type'] } : x)))} data-testid={`thanks-type-${i}`}>
              {THANK_ACTIONS.map((ty) => <option key={ty} value={ty}>{t(`hub.builder.thanks.types.${ty}`)}</option>)}
            </Select>
            <Input aria-label={t('hub.builder.thanks.label')} placeholder={t('hub.builder.thanks.label')} value={a.label} onChange={(e) => setActions(th.actions.map((x, n) => (n === i ? { ...x, label: e.target.value } : x)))} data-testid={`thanks-label-${i}`} />
            <div className="flex items-start gap-2">
              <Input aria-label={t('hub.builder.thanks.value')} containerClassName="flex-1" dir="ltr" placeholder={a.type === 'whatsapp' || a.type === 'call' ? '+9665…' : 'https://'} value={a.value} onChange={(e) => setActions(th.actions.map((x, n) => (n === i ? { ...x, value: e.target.value } : x)))} data-testid={`thanks-value-${i}`} />
              <Button size="sm" variant="ghost" aria-label={t('common.delete')} icon={<Trash2 className="size-4" />} onClick={() => setActions(th.actions.filter((_, n) => n !== i))} />
            </div>
          </div>
        ))}
      </div>

      <div className="grid gap-2 sm:grid-cols-[2fr_1fr]">
        <Input label={t('hub.builder.thanks.redirect')} dir="ltr" placeholder="https://" value={th.redirect_url ?? ''} onChange={(e) => onSettings({ ...settings, thanks: { ...th, redirect_url: e.target.value || null } })} data-testid="thanks-redirect" />
        <Input label={t('hub.builder.thanks.delay')} type="number" min={0} max={30} value={String(th.redirect_delay)} onChange={(e) => onSettings({ ...settings, thanks: { ...th, redirect_delay: Math.min(30, Math.max(0, Number(e.target.value) || 0)) } })} data-testid="thanks-delay" />
      </div>
    </div>
  )
}
