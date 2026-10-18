import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Button, Drawer, Input, Textarea } from '@/components/ui'
import { saveClientRules, useClientRules, type ClientRules } from '@/features/proposals'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'

type Form = Record<'cpl' | 'cpql' | 'cpa' | 'roas' | 'minSpend' | 'ceiling' | 'maxChange' | 'blackout' | 'forbidden' | 'notes', string>

const toMajor = (minor: number | null) => (minor === null ? '' : String(minor / 100))
const toMinor = (major: string) => (major.trim() === '' ? null : Math.round(Number(major) * 100))
const list = (text: string) => text.split(/[,،\n]/).map((s) => s.trim()).filter(Boolean)

/**
 * The client's targets and limits, in one short form.
 *
 * Every agent reads these before it advises, and the gateway enforces the
 * limits before anything runs — so this is where "never more than 20% at once"
 * and "nothing changes on match days" are said once and then always hold.
 */
export function ClientRulesDrawer({ open, onClose, currency }: { open: boolean; onClose: () => void; currency: string }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const { data } = useClientRules()
  const [form, setForm] = useState<Form | null>(null)

  useEffect(() => {
    if (!data || !open) return
    const r = data.rules
    setForm({
      cpl: toMajor(r.target_cpl_minor), cpql: toMajor(r.target_cpql_minor), cpa: toMajor(r.target_cpa_minor),
      roas: r.target_roas === null ? '' : String(r.target_roas), minSpend: toMajor(r.min_spend_to_judge_minor),
      ceiling: toMajor(r.daily_budget_ceiling_minor), maxChange: String(r.max_budget_change_pct ?? 20),
      blackout: (r.blackout_days ?? []).join(', '), forbidden: (r.forbidden_words ?? []).join(', '), notes: r.notes ?? '',
    })
  }, [data, open])

  const save = useMutation({
    mutationFn: () => {
      const f = form!
      const rules: ClientRules = {
        target_cpl_minor: toMinor(f.cpl), target_cpql_minor: toMinor(f.cpql), target_cpa_minor: toMinor(f.cpa),
        target_roas: f.roas.trim() === '' ? null : Number(f.roas), min_spend_to_judge_minor: toMinor(f.minSpend),
        daily_budget_ceiling_minor: toMinor(f.ceiling), max_budget_change_pct: Number(f.maxChange) || 20,
        blackout_days: list(f.blackout), forbidden_words: list(f.forbidden), notes: f.notes || null,
      }
      return saveClientRules(rules)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['client-rules'] })
      void queryClient.invalidateQueries({ queryKey: ['proposals'] })
      toast(t('rules.saved'), 'success')
      onClose()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const field = (key: keyof Form, label: string, type = 'number', hint?: string) => (
    <Input
      label={label}
      hint={hint}
      type={type}
      dir={type === 'number' ? 'ltr' : undefined}
      value={form?.[key] ?? ''}
      onChange={(e) => setForm((f) => (f ? { ...f, [key]: e.target.value } : f))}
      data-testid={`rule-${key}`}
    />
  )

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={t('rules.title')}
      description={t('rules.hint')}
      testId="client-rules-drawer"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button loading={save.isPending} disabled={!form} onClick={() => save.mutate()} data-testid="rules-save">{t('common.save')}</Button>
        </>
      }
    >
      {form && (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3">
            {field('cpl', t('rules.cpl', { currency }))}
            {field('cpql', t('rules.cpql', { currency }))}
            {field('cpa', t('rules.cpa', { currency }))}
            {field('roas', t('rules.roas'))}
            {field('minSpend', t('rules.minSpend', { currency }))}
            {field('ceiling', t('rules.ceiling', { currency }))}
            {field('maxChange', t('rules.maxChange'))}
          </div>
          {field('blackout', t('rules.blackout'), 'text', t('rules.blackoutHint'))}
          {field('forbidden', t('rules.forbidden'), 'text', t('rules.forbiddenHint'))}
          <Textarea label={t('rules.notes')} value={form.notes} onChange={(e) => setForm((f) => (f ? { ...f, notes: e.target.value } : f))} />
        </div>
      )}
    </Drawer>
  )
}
