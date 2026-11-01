import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Timer, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, CardHeader, Input, Select } from '@/components/ui'
import { useApiError } from '@/hooks/useApiError'
import { apiPatch } from '@/lib/api'
import { useUi } from '@/stores/ui'

const SOURCES = ['meta', 'tiktok', 'snapchat', 'google_ads', 'linkedin', 'website', 'webhook', 'manual'] as const

export interface SlaRules {
  by_source: Record<string, number>
  priority_minutes: number | null
}

/**
 * First-contact targets that depend on the lead. The workspace target stays the rule for everyone; a line here can
 * only make it stricter — a Meta lead in fifteen minutes, a priority lead in ten.
 */
export function SlaRulesCard({ rules, base }: { rules: SlaRules | undefined; base: number }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [rows, setRows] = useState<Array<{ source: string; minutes: number }>>(
    Object.entries(rules?.by_source ?? {}).map(([source, minutes]) => ({ source, minutes })),
  )
  const [priority, setPriority] = useState<string>(rules?.priority_minutes ? String(rules.priority_minutes) : '')

  const save = useMutation({
    mutationFn: () => apiPatch('/settings', {
      sla_rules: {
        by_source: Object.fromEntries(rows.filter((r) => r.minutes >= 1).map((r) => [r.source, r.minutes])),
        priority_minutes: priority === '' ? null : Number(priority),
      },
    }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['settings'] })
      toast(t('settings.saved'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const unused = SOURCES.filter((s) => !rows.some((r) => r.source === s))

  return (
    <Card data-testid="sla-rules">
      <CardHeader title={t('slaRules.title')} description={t('slaRules.hint', { minutes: base })} />
      <CardBody className="space-y-3">
        {rows.map((row, index) => (
          <div key={row.source} className="flex items-end gap-2" data-testid="sla-rule-row">
            <Select
              className="min-w-40 flex-1"
              value={row.source}
              aria-label={t('slaRules.source')}
              onChange={(e) => setRows((prev) => prev.map((r, i) => (i === index ? { ...r, source: e.target.value } : r)))}
            >
              {[row.source, ...unused].map((s) => <option key={s} value={s}>{t(`platforms.${s}`, { defaultValue: s })}</option>)}
            </Select>
            <Input
              className="w-32"
              type="number"
              min={1}
              max={10080}
              value={row.minutes}
              aria-label={t('slaRules.minutes')}
              onChange={(e) => setRows((prev) => prev.map((r, i) => (i === index ? { ...r, minutes: Number(e.target.value) } : r)))}
            />
            <span className="t-caption pb-2">{t('slaRules.minutesShort')}</span>
            <Button size="icon" variant="ghost" aria-label={t('common.delete')} icon={<X className="size-4" />} onClick={() => setRows((prev) => prev.filter((_, i) => i !== index))} />
          </div>
        ))}
        {unused.length > 0 && (
          <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} data-testid="sla-rule-add" onClick={() => setRows((prev) => [...prev, { source: unused[0], minutes: Math.max(1, Math.round(base / 2)) }])}>
            {t('slaRules.addSource')}
          </Button>
        )}
        <div className="flex items-end gap-2 border-t border-[var(--color-line)] pt-3">
          <Timer className="mb-2.5 size-4 text-[var(--color-ink-muted)]" aria-hidden />
          <Input
            className="w-32"
            type="number"
            min={1}
            max={10080}
            label={t('slaRules.priority')}
            value={priority}
            placeholder="—"
            onChange={(e) => setPriority(e.target.value)}
            data-testid="sla-rule-priority"
          />
          <span className="t-caption pb-2">{t('slaRules.minutesShort')}</span>
          <Button className="ms-auto" loading={save.isPending} onClick={() => save.mutate()} data-testid="sla-rules-save">{t('common.save')}</Button>
        </div>
      </CardBody>
    </Card>
  )
}
