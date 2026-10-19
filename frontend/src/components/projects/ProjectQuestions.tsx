import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import {
  createProjectQuestion, deleteProjectQuestion, fetchProjectQuestions, updateProjectQuestion,
  type ProjectQuestion, type QuestionOption, type QuestionRule, type QuestionType, type RuleOp,
} from '@/features/projectQuestions'
import { ApiError } from '@/lib/api'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Badge, Button, Card, CardBody, CardHeader, Drawer, EmptyState, Input, Select, Skeleton } from '@/components/ui'

const TYPES: QuestionType[] = ['select', 'boolean', 'number', 'text']

const BLANK = { label: '', type: 'boolean' as QuestionType, options: [] as QuestionOption[], rules: [] as QuestionRule[], is_required: false }

/**
 * The questions a project asks, and what each answer means.
 *
 * Every rule reads as a sentence — "if the answer is yes: +10 points, and make
 * them a priority" — because the person writing it is a sales manager, and the
 * person it will be explained to is a rep who wants to know why this lead is
 * above that one. The server refuses a rule that could never fire; this form
 * only offers the operators that fit the question's type, so the refusal is the
 * backstop and not the experience.
 */
export function ProjectQuestions({ projectId }: { projectId: string }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const canEdit = can.manageProjects(user)

  const { data, isLoading } = useQuery({
    queryKey: ['project-questions', projectId],
    queryFn: () => fetchProjectQuestions(projectId),
  })

  const [editing, setEditing] = useState<ProjectQuestion | null>(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(BLANK)
  const [error, setError] = useState<string | null>(null)

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['project-questions', projectId] })
    void queryClient.invalidateQueries({ queryKey: ['lead-answers'] })
  }

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        label: form.label,
        options: form.type === 'select' ? form.options : undefined,
        rules: form.rules,
        is_required: form.is_required,
      }
      return editing
        ? updateProjectQuestion(editing.id, payload)
        : createProjectQuestion(projectId, { ...payload, type: form.type })
    },
    onSuccess: () => {
      refresh()
      toast(t('common.saved'), 'success')
      close()
    },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.errors) {
        setError(Object.values(caught.errors)[0]?.[0] ?? t('errors.validation'))
      } else {
        toast(translateError(caught), 'error')
      }
    },
  })

  const remove = useMutation({
    mutationFn: () => deleteProjectQuestion(editing!.id),
    onSuccess: () => {
      refresh()
      close()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  function openCreate() {
    setEditing(null)
    setForm(BLANK)
    setError(null)
    setOpen(true)
  }

  function openEdit(question: ProjectQuestion) {
    setEditing(question)
    setForm({
      label: question.label,
      type: question.type,
      options: question.options,
      rules: question.rules,
      is_required: question.is_required,
    })
    setError(null)
    setOpen(true)
  }

  function close() {
    setOpen(false)
    setEditing(null)
  }

  const operators = data?.operators ?? ({} as Record<QuestionType, RuleOp[]>)
  const questions = data?.questions ?? []

  const patchRule = (index: number, patch: Partial<QuestionRule>) =>
    setForm((f) => ({ ...f, rules: f.rules.map((rule, i) => (i === index ? { ...rule, ...patch } : rule)) }))

  return (
    <Card data-testid="project-questions">
      <CardHeader
        title={t('pq.title')}
        description={t('pq.hint')}
        action={
          canEdit ? (
            <Button size="sm" icon={<Plus className="size-4" />} onClick={openCreate} data-testid="add-question">
              {t('pq.add')}
            </Button>
          ) : undefined
        }
      />
      <CardBody>
        {isLoading ? (
          <Skeleton className="h-24" />
        ) : questions.length === 0 ? (
          <EmptyState title={t('pq.emptyTitle')} body={t('pq.emptyBody')} />
        ) : (
          <ul className="divide-y divide-[var(--color-line)]" data-testid="question-list">
            {questions.map((question) => (
              <li key={question.id} className="flex items-center justify-between gap-3 py-2.5">
                <div className="min-w-0">
                  <p className="truncate text-[1rem] font-medium">{question.label}</p>
                  <p className="t-caption">
                    {t(`pq.type.${question.type}`)} · {t('pq.rulesCount', { count: question.rules.length })}
                  </p>
                </div>
                <span className="flex shrink-0 items-center gap-2">
                  {question.is_required && <Badge tone="info">{t('pq.required')}</Badge>}
                  {canEdit && (
                    <Button size="sm" variant="ghost" onClick={() => openEdit(question)} data-testid={`edit-question-${question.key}`}>
                      {t('common.edit')}
                    </Button>
                  )}
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardBody>

      <Drawer
        open={open}
        onClose={close}
        testId="question-drawer"
        size="lg"
        title={editing ? t('common.edit') : t('pq.add')}
        footer={
          <>
            {editing && (
              <Button variant="danger" className="me-auto" icon={<Trash2 className="size-4" />} loading={remove.isPending} onClick={() => remove.mutate()} data-testid="delete-question">
                {t('common.delete')}
              </Button>
            )}
            <Button variant="ghost" onClick={close}>{t('common.cancel')}</Button>
            <Button loading={save.isPending} onClick={() => { setError(null); save.mutate() }} data-testid="save-question">
              {t('common.save')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          {error && <p className="notice notice-danger" data-testid="question-error">{error}</p>}

          <Input
            required
            label={t('pq.label')}
            value={form.label}
            data-testid="question-label"
            onChange={(e) => setForm((f) => ({ ...f, label: e.target.value }))}
          />

          <Select
            label={t('pq.typeLabel')}
            value={form.type}
            disabled={Boolean(editing)}
            hint={editing ? t('pq.typeLocked') : undefined}
            data-testid="question-type"
            onChange={(e) => setForm((f) => ({ ...f, type: e.target.value as QuestionType, rules: [], options: [] }))}
          >
            {TYPES.map((type) => <option key={type} value={type}>{t(`pq.type.${type}`)}</option>)}
          </Select>

          {form.type === 'select' && (
            <fieldset className="space-y-2" data-testid="question-options">
              <legend className="t-small mb-1 font-medium">{t('pq.options')}</legend>
              {form.options.map((option, index) => (
                <div key={index} className="flex gap-2">
                  <Input
                    dir="ltr"
                    aria-label={t('pq.optionValue')}
                    placeholder="villa"
                    value={option.value}
                    onChange={(e) => setForm((f) => ({ ...f, options: f.options.map((o, i) => (i === index ? { ...o, value: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '') } : o)) }))}
                  />
                  <Input
                    aria-label={t('pq.optionLabel')}
                    placeholder={t('pq.optionLabel')}
                    value={option.label}
                    onChange={(e) => setForm((f) => ({ ...f, options: f.options.map((o, i) => (i === index ? { ...o, label: e.target.value } : o)) }))}
                  />
                  <Button size="icon" variant="ghost" aria-label={t('common.delete')} onClick={() => setForm((f) => ({ ...f, options: f.options.filter((_, i) => i !== index) }))}>
                    <Trash2 className="size-4" />
                  </Button>
                </div>
              ))}
              <Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => setForm((f) => ({ ...f, options: [...f.options, { value: '', label: '' }] }))} data-testid="add-option">
                {t('pq.addOption')}
              </Button>
            </fieldset>
          )}

          <label className="flex items-center gap-2 text-[0.9375rem]">
            <input type="checkbox" checked={form.is_required} onChange={(e) => setForm((f) => ({ ...f, is_required: e.target.checked }))} />
            {t('pq.requiredHint')}
          </label>

          <fieldset className="space-y-2" data-testid="question-rules">
            <legend className="t-small mb-1 font-medium">{t('pq.rules')}</legend>
            <p className="t-caption">{t('pq.rulesHint', { cap: data?.cap ?? 30 })}</p>

            {form.rules.map((rule, index) => (
              <RuleRow
                key={index}
                rule={rule}
                type={form.type}
                options={form.options}
                ops={operators[form.type] ?? []}
                onChange={(patch) => patchRule(index, patch)}
                onRemove={() => setForm((f) => ({ ...f, rules: f.rules.filter((_, i) => i !== index) }))}
              />
            ))}

            <Button
              size="sm"
              variant="outline"
              icon={<Plus className="size-4" />}
              onClick={() => setForm((f) => ({ ...f, rules: [...f.rules, { op: (operators[f.type] ?? ['filled'])[0], points: 0, effect: null }] }))}
              data-testid="add-rule"
            >
              {t('pq.addRule')}
            </Button>
          </fieldset>
        </div>
      </Drawer>
    </Card>
  )
}

function RuleRow({
  rule, type, options, ops, onChange, onRemove,
}: {
  rule: QuestionRule
  type: QuestionType
  options: QuestionOption[]
  ops: RuleOp[]
  onChange: (patch: Partial<QuestionRule>) => void
  onRemove: () => void
}) {
  const { t } = useTranslation()

  return (
    <div className="space-y-2 rounded-[var(--radius-control)] bg-[var(--color-surface-muted)] p-2.5" data-testid="rule-row">
      <div className="grid gap-2 sm:grid-cols-[1fr_1fr_5rem_auto]">
        <Select aria-label={t('pq.when')} value={rule.op} data-testid="rule-op" onChange={(e) => onChange({ op: e.target.value as RuleOp, value: null })}>
          {ops.map((op) => <option key={op} value={op}>{t(`pq.op.${op}`)}</option>)}
        </Select>

        {rule.op === 'equals' && (
          <Select aria-label={t('pq.value')} value={String(rule.value ?? '')} data-testid="rule-value" onChange={(e) => onChange({ value: e.target.value })}>
            <option value="">—</option>
            {options.map((o) => <option key={o.value} value={o.value}>{o.label || o.value}</option>)}
          </Select>
        )}
        {rule.op === 'in' && (
          <Input
            dir="ltr"
            aria-label={t('pq.value')}
            placeholder="villa,flat"
            value={Array.isArray(rule.value) ? rule.value.join(',') : ''}
            data-testid="rule-value"
            onChange={(e) => onChange({ value: e.target.value.split(',').map((v) => v.trim()).filter(Boolean) })}
          />
        )}
        {(rule.op === 'gte' || rule.op === 'lte') && (
          <Input
            dir="ltr"
            inputMode="numeric"
            aria-label={t('pq.value')}
            value={rule.value === null || rule.value === undefined ? '' : String(rule.value)}
            data-testid="rule-value"
            onChange={(e) => onChange({ value: e.target.value === '' ? null : Number(e.target.value.replace(/[^\d.]/g, '')) })}
          />
        )}
        {rule.op === 'between' && (
          <div className="flex items-center gap-1">
            {[0, 1].map((side) => (
              <Input
                key={side}
                dir="ltr"
                inputMode="numeric"
                aria-label={t('pq.value')}
                value={Array.isArray(rule.value) ? String(rule.value[side] ?? '') : ''}
                data-testid={`rule-value-${side}`}
                onChange={(e) => {
                  const next = (Array.isArray(rule.value) ? [...rule.value] : [0, 0]) as [number, number]
                  next[side] = Number(e.target.value.replace(/[^\d.]/g, ''))
                  onChange({ value: next })
                }}
              />
            ))}
          </div>
        )}
        {['is_true', 'is_false', 'filled'].includes(rule.op) && <span className="hidden sm:block" />}

        <Input
          dir="ltr"
          type="number"
          aria-label={t('pq.points')}
          value={rule.points ?? 0}
          data-testid="rule-points"
          onChange={(e) => onChange({ points: Number(e.target.value) })}
        />
        <Button size="icon" variant="ghost" aria-label={t('common.delete')} onClick={onRemove}>
          <Trash2 className="size-4" />
        </Button>
      </div>

      <Select aria-label={t('pq.effect.label')} value={rule.effect ?? ''} data-testid="rule-effect" onChange={(e) => onChange({ effect: (e.target.value || null) as QuestionRule['effect'] })}>
        <option value="">{t('pq.effect.none')}</option>
        <option value="priority">{t('pq.effect.priority')}</option>
        <option value="wrong_fit">{t('pq.effect.wrong_fit')}</option>
      </Select>
      {type === 'select' && rule.op === 'equals' && options.length === 0 && <p className="t-caption">{t('pq.addOptionsFirst')}</p>}
    </div>
  )
}
