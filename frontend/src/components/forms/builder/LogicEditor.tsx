import { Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Select } from '@/components/ui'
import { LOGIC_OPS, type FormField, type LogicCondition, type LogicGroup } from '@/features/hub'

const VALUELESS = ['empty', 'not_empty']
const NUMERIC = ['gt', 'gte', 'lt', 'lte']

/**
 * "Show this when…" — a few conditions on other fields, joined by AND or OR. The same editor serves every field, row and section, and
 * for show, hide and require. A choice field offers its own choices as the value; the rest take typed text or a number.
 */
export function LogicEditor({ title, group, fields, selfKey, onChange, testId }: {
  title: string
  group: LogicGroup | null | undefined
  fields: FormField[]
  selfKey?: string
  onChange: (group: LogicGroup | null) => void
  testId: string
}) {
  const { t } = useTranslation()
  const candidates = fields.filter((f) => f.key !== selfKey && !['heading', 'paragraph', 'divider', 'hidden'].includes(f.type))
  const g: LogicGroup = group ?? { match: 'all', conditions: [] }

  const set = (conditions: LogicCondition[], match = g.match) => onChange(conditions.length === 0 ? null : { match, conditions })
  const patch = (i: number, p: Partial<LogicCondition>) => set(g.conditions.map((c, n) => (n === i ? { ...c, ...p } : c)))

  return (
    <div className="rounded-xl border border-[var(--color-border)] p-3" data-testid={testId}>
      <div className="flex items-center justify-between gap-2">
        <p className="text-[0.9375rem] font-semibold">{title}</p>
        <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} disabled={candidates.length === 0 || g.conditions.length >= 8}
          onClick={() => set([...g.conditions, { field: candidates[0].key, op: 'equals', value: '' }])} data-testid={`${testId}-add`}>{t('hub.builder.logic.add')}</Button>
      </div>

      {g.conditions.length === 0 ? <p className="t-caption mt-1 text-[var(--color-ink-subtle)]">{t('hub.builder.logic.none')}</p> : (
        <div className="mt-2 space-y-2">
          {g.conditions.length > 1 && (
            <Select aria-label={t('hub.builder.logic.match')} value={g.match} onChange={(e) => set(g.conditions, e.target.value as 'all' | 'any')} data-testid={`${testId}-match`}>
              <option value="all">{t('hub.builder.logic.all')}</option>
              <option value="any">{t('hub.builder.logic.any')}</option>
            </Select>
          )}
          {g.conditions.map((c, i) => {
            const target = candidates.find((f) => f.key === c.field)
            const choices = target?.options?.length ? target.options : target?.matrix ? null : null

            return (
              <div key={i} className="grid grid-cols-[1fr_auto] items-start gap-2" data-testid={`${testId}-cond-${i}`}>
                <div className="grid gap-2 sm:grid-cols-3">
                  <Select aria-label={t('hub.builder.logic.field')} value={c.field} onChange={(e) => patch(i, { field: e.target.value, value: '' })} data-testid={`${testId}-field-${i}`}>
                    {candidates.map((f) => <option key={f.key} value={f.key}>{f.label || f.key}</option>)}
                  </Select>
                  <Select aria-label={t('hub.builder.logic.op')} value={c.op} onChange={(e) => patch(i, { op: e.target.value as LogicCondition['op'] })} data-testid={`${testId}-op-${i}`}>
                    {LOGIC_OPS.map((o) => <option key={o} value={o}>{t(`hub.builder.logic.ops.${o}`)}</option>)}
                  </Select>
                  {!VALUELESS.includes(c.op) && (choices && !NUMERIC.includes(c.op) ? (
                    <Select aria-label={t('hub.builder.logic.value')} value={String(c.value ?? '')} onChange={(e) => patch(i, { value: e.target.value })} data-testid={`${testId}-value-${i}`}>
                      <option value="">—</option>
                      {choices.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </Select>
                  ) : (
                    <input aria-label={t('hub.builder.logic.value')} value={String(c.value ?? '')} inputMode={NUMERIC.includes(c.op) ? 'decimal' : undefined}
                      onChange={(e) => patch(i, { value: NUMERIC.includes(c.op) && e.target.value !== '' && !Number.isNaN(Number(e.target.value)) ? Number(e.target.value) : e.target.value })}
                      className="h-10 w-full rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-sm" data-testid={`${testId}-value-${i}`} />
                  ))}
                </div>
                <Button size="sm" variant="ghost" aria-label={t('common.delete')} icon={<Trash2 className="size-4" />} onClick={() => set(g.conditions.filter((_, n) => n !== i))} data-testid={`${testId}-remove-${i}`} />
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
