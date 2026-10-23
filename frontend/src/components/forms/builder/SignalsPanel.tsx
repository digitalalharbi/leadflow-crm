import { Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Input } from '@/components/ui'
import type { FormField, FormSettings, SignalRule } from '@/features/hub'
import { LogicEditor } from './LogicEditor'

/** What the answers say about how serious a customer is, by the workspace's own rules: a condition and some points. */
export function SignalsPanel({ fields, settings, onSettings }: { fields: FormField[]; settings: FormSettings; onSettings: (s: FormSettings) => void }) {
  const { t } = useTranslation()
  const rules = settings.signals
  const set = (signals: SignalRule[]) => onSettings({ ...settings, signals })
  const total = Math.min(30, rules.reduce((n, r) => n + r.points, 0))

  return (
    <div className="space-y-3" data-testid="signals-panel">
      <p className="text-[0.9375rem] text-[var(--color-ink-muted)]">{t('hub.builder.signals.intro')}</p>
      {rules.map((r, i) => (
        <div key={r.id} className="space-y-2 rounded-xl border border-[var(--color-border)] p-3" data-testid={`signal-${i}`}>
          <div className="flex items-start gap-2">
            <Input aria-label={t('hub.builder.signals.label')} containerClassName="flex-1" placeholder={t('hub.builder.signals.label')} value={r.label} onChange={(e) => set(rules.map((x, n) => (n === i ? { ...x, label: e.target.value } : x)))} data-testid={`signal-label-${i}`} />
            <Input aria-label={t('hub.builder.signals.points')} containerClassName="w-20" type="number" min={1} max={30} value={String(r.points)} onChange={(e) => set(rules.map((x, n) => (n === i ? { ...x, points: Math.min(30, Math.max(1, Number(e.target.value) || 1)) } : x)))} data-testid={`signal-points-${i}`} />
            <Button size="sm" variant="ghost" aria-label={t('common.delete')} icon={<Trash2 className="size-4" />} onClick={() => set(rules.filter((_, n) => n !== i))} />
          </div>
          {r.when.conditions.length === 0 && <p role="status" className="t-caption text-[var(--color-warning)]" data-testid={`signal-needs-${i}`}>{t('hub.builder.signals.needsCondition')}</p>}
          <LogicEditor title={t('hub.builder.signals.when')} group={r.when} fields={fields} onChange={(when) => set(rules.map((x, n) => (n === i ? { ...x, when: when ?? { match: 'all', conditions: [] } } : x)))} testId={`signal-when-${i}`} />
        </div>
      ))}
      <Button size="sm" variant="secondary" icon={<Plus className="size-4" />} disabled={rules.length >= 12} data-testid="signal-add"
        onClick={() => set([...rules, { id: `rule_${rules.length + 1}`, label: '', points: 10, when: { match: 'all', conditions: [] } }])}>{t('hub.builder.signals.add')}</Button>
      {rules.length > 0 && <p className="t-caption text-[var(--color-ink-subtle)]">{t('hub.builder.signals.cap', { max: total })}</p>}
    </div>
  )
}
