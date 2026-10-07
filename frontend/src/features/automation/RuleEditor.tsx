import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Plus, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Modal, Select } from '@/components/ui'
import { fetchLostReasons } from '@/features/leads'
import type { AutomationRule, AutomationSchema, RuleAction, RuleCondition } from '@/features/automation'

/**
 * The builder.
 *
 * Every dropdown here is populated from `/automation/schema` rather than from a
 * list typed into this file. The API checks each field, operator and action
 * against a closed list and refuses anything else, so a builder with its own
 * copy of that vocabulary would eventually offer something the server rejects —
 * and the customer would meet it as a validation error on a rule they had
 * already finished writing.
 *
 * Operators are not all alike: `exists` takes no value, `between` takes two,
 * `in` takes a list. The value input changes with the operator, because a lone
 * text box next to "between" is a question with no right answer.
 */

/**
 * What each action needs in order to do anything, seeded when it is chosen.
 *
 * The detail inputs below *display* a sensible default, but a displayed default
 * is not a stored one: a rule saved without touching the select would carry no
 * audience and no stage at all. The API now refuses that outright rather than
 * saving a rule that fails on every run, so the editor has to put the value in
 * the rule when the action is picked — not when somebody happens to change it.
 */
const ACTION_DEFAULTS: Record<string, Record<string, unknown>> = {
  assign: { strategy: 'round_robin' },
  change_stage: { status: 'contacted' },
  adjust_score: { by: 10 },
  schedule_follow_up: { in_hours: 24 },
  add_tag: { tag: '' },
  add_note: { body: '' },
  notify: { audience: 'owner', message: '' },
  stop_follow_ups: {},
}

function withDefaults(type: string): RuleAction {
  return { type, ...ACTION_DEFAULTS[type] }
}

/** Operators that need no value at all. */
const VALUELESS = new Set(['exists', 'not_exists'])

/** Operators whose value is a list the user types comma-separated. */
const LIST_VALUED = new Set(['in', 'not_in', 'between'])

interface Props {
  rule: AutomationRule | null
  schema: AutomationSchema
  saving: boolean
  onCancel: () => void
  onSave: (payload: Record<string, unknown>) => void
}

export function RuleEditor({ rule, schema, saving, onCancel, onSave }: Props) {
  const { t } = useTranslation()

  const knownValues = (field: string) => schema.field_values?.[field]

  // Only the active ones, and only here: a rule written against a reason an
  // admin has since retired would fail on every run.
  const { data: lostReasons = [] } = useQuery({
    queryKey: ['lost-reasons'],
    queryFn: fetchLostReasons,
  })

  const [name, setName] = useState(rule?.name ?? '')
  const [trigger, setTrigger] = useState(rule?.trigger ?? schema.triggers[0])
  const [priority, setPriority] = useState(String(rule?.priority ?? 100))
  const [conditions, setConditions] = useState<RuleCondition[]>(rule?.conditions ?? [])
  const [actions, setActions] = useState<RuleAction[]>(
    rule?.actions ?? [withDefaults(schema.actions[0])],
  )

  function patchCondition(index: number, patch: Partial<RuleCondition>) {
    setConditions((prev) => prev.map((c, i) => (i === index ? { ...c, ...patch } : c)))
  }

  function patchAction(index: number, patch: Partial<RuleAction>) {
    setActions((prev) =>
      prev.map((action, i) => {
        if (i !== index) return action

        // Changing the action replaces it rather than merging, so a stage left
        // over from "change_stage" does not ride along inside a "notify".
        return typeof patch.type === 'string' && patch.type !== action.type
          ? withDefaults(patch.type)
          : { ...action, ...patch }
      }),
    )
  }

  function submit() {
    onSave({
      name,
      trigger,
      priority: Number(priority) || 100,
      // Sent even when empty: a rule with a trigger and no conditions fires on
      // every event of that kind, which is a thing people legitimately want.
      conditions: conditions.map((condition) => ({
        field: condition.field,
        operator: condition.operator,
        value: VALUELESS.has(condition.operator) ? null : (condition.value ?? null),
      })),
      actions,
    })
  }

  const valid = name.trim().length > 0 && actions.length > 0

  return (
    <Modal
      open
      onClose={onCancel}
      size="lg"
      title={t(rule ? 'automation.editTitle' : 'automation.newTitle')}
      footer={
        <>
          <Button variant="ghost" onClick={onCancel}>
            {t('common.cancel')}
          </Button>
          <Button loading={saving} disabled={!valid} onClick={submit} data-testid="save-rule">
            {t('common.save')}
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        <Input
          label={t('automation.name')}
          value={name}
          onChange={(e) => setName(e.target.value)}
          data-testid="rule-name"
        />

        <div className="grid gap-3 sm:grid-cols-2">
          <Select
            label={t('automation.whenLabel')}
            value={trigger}
            onChange={(e) => setTrigger(e.target.value)}
            data-testid="rule-trigger"
          >
            {schema.triggers.map((option) => (
              <option key={option} value={option}>
                {t(`automation.trigger.${option}`)}
              </option>
            ))}
          </Select>

          <Input
            label={t('automation.priority')}
            hint={t('automation.priorityHint')}
            type="number"
            value={priority}
            onChange={(e) => setPriority(e.target.value)}
          />
        </div>

        <section className="space-y-2">
          <div className="flex items-center justify-between">
            <h3 className="t-body font-medium">{t('automation.ifLabel')}</h3>
            <Button
              size="sm"
              variant="ghost"
              icon={<Plus className="size-4" aria-hidden />}
              data-testid="add-condition"
              onClick={() =>
                setConditions((prev) => [
                  ...prev,
                  {
                    field: schema.fields[0],
                    operator: schema.operators[0],
                    // A select has no empty option, so an unseeded condition
                    // would display its first value and save none.
                    value: knownValues(schema.fields[0])?.[0] ?? '',
                  },
                ])
              }
            >
              {t('automation.addCondition')}
            </Button>
          </div>

          {conditions.length === 0 ? (
            // Said out loud, because an empty list reads as "unfinished" when
            // it actually means "every time this happens".
            <p className="t-caption">{t('automation.noConditions')}</p>
          ) : (
            conditions.map((condition, index) => (
              <div key={index} className="flex flex-wrap items-end gap-2">
                <Select
                  className="min-w-40 flex-1"
                  label={index === 0 ? t('automation.field') : undefined}
                  value={condition.field}
                  onChange={(e) =>
                    patchCondition(index, {
                      field: e.target.value,
                      // The old value belongs to the old field. Carrying it over
                      // leaves a select showing a value the rule does not hold.
                      value: knownValues(e.target.value)?.[0] ?? '',
                    })
                  }
                >
                  {schema.fields.map((field) => (
                    <option key={field} value={field}>
                      {t(`automation.field_.${field}`, { defaultValue: field })}
                    </option>
                  ))}
                </Select>

                <Select
                  className="min-w-36"
                  label={index === 0 ? t('automation.operator') : undefined}
                  value={condition.operator}
                  onChange={(e) => patchCondition(index, { operator: e.target.value })}
                >
                  {schema.operators.map((operator) => (
                    <option key={operator} value={operator}>
                      {t(`automation.operator_.${operator}`, { defaultValue: operator })}
                    </option>
                  ))}
                </Select>

                {/*
                  A picker where the server knows the answers.

                  `last_outcome` is the case that forced this: its values are
                  internal strings, and a manager writing "لم يرد" or "no answer"
                  instead of `no_answer` gets a rule that saves, runs, matches
                  nothing and reports no error — the hardest kind of rule to
                  debug, because every screen says it is fine.

                  Still a text box for `in` and `between`, which take several
                  values; a multi-select for those is worth doing, and is not
                  worth guessing at here.
                */}
                {!VALUELESS.has(condition.operator) &&
                  (knownValues(condition.field) && !LIST_VALUED.has(condition.operator) ? (
                    <Select
                      className="min-w-32 flex-1"
                      label={index === 0 ? t('automation.value') : undefined}
                      value={String(condition.value ?? '')}
                      onChange={(e) => patchCondition(index, { value: e.target.value })}
                    >
                      {knownValues(condition.field)!.map((option) => (
                        <option key={option} value={option}>
                          {t(`work.outcome.${option}`, { defaultValue: option })}
                        </option>
                      ))}
                    </Select>
                  ) : (
                    <Input
                      className="min-w-32 flex-1"
                      label={index === 0 ? t('automation.value') : undefined}
                      hint={LIST_VALUED.has(condition.operator) ? t('automation.listHint') : undefined}
                      value={String(condition.value ?? '')}
                      onChange={(e) => patchCondition(index, { value: e.target.value })}
                    />
                  ))}

                <Button
                  size="icon"
                  variant="ghost"
                  aria-label={t('automation.removeCondition')}
                  icon={<X className="size-4" aria-hidden />}
                  onClick={() => setConditions((prev) => prev.filter((_, i) => i !== index))}
                />
              </div>
            ))
          )}
        </section>

        <section className="space-y-2">
          <div className="flex items-center justify-between">
            <h3 className="t-body font-medium">{t('automation.thenLabel')}</h3>
            <Button
              size="sm"
              variant="ghost"
              icon={<Plus className="size-4" aria-hidden />}
              data-testid="add-action"
              onClick={() => setActions((prev) => [...prev, withDefaults(schema.actions[0])])}
            >
              {t('automation.addAction')}
            </Button>
          </div>

          {actions.map((action, index) => (
            <div key={index} className="flex flex-wrap items-end gap-2">
              <Select
                className="min-w-48 flex-1"
                label={index === 0 ? t('automation.action') : undefined}
                value={action.type}
                onChange={(e) => patchAction(index, { type: e.target.value })}
              >
                {schema.actions.map((option) => (
                  <option key={option} value={option}>
                    {t(`automation.action_.${option}`, { defaultValue: option })}
                  </option>
                ))}
              </Select>

              <ActionDetail
                action={action}
                schema={schema}
                labelled={index === 0}
                lostReasons={lostReasons}
                onChange={(patch) => patchAction(index, patch)}
              />

              {actions.length > 1 && (
                <Button
                  size="icon"
                  variant="ghost"
                  aria-label={t('automation.removeAction')}
                  icon={<X className="size-4" aria-hidden />}
                  onClick={() => setActions((prev) => prev.filter((_, i) => i !== index))}
                />
              )}
            </div>
          ))}
        </section>
      </div>
    </Modal>
  )
}

/**
 * The one extra field an action needs, if it needs one.
 *
 * "Change stage" without a stage, or "wait" without a duration, is a rule that
 * saves cleanly and then does nothing — the exact failure the closed vocabulary
 * elsewhere is designed to prevent, reappearing one level down.
 */
function ActionDetail({
  action,
  schema,
  labelled,
  lostReasons,
  onChange,
}: {
  action: RuleAction
  schema: AutomationSchema
  labelled: boolean
  lostReasons: { id: string; label: string }[]
  onChange: (patch: Partial<RuleAction>) => void
}) {
  const { t } = useTranslation()

  switch (action.type) {
    case 'change_stage':
      return (
        <>
          <Select
            className="min-w-40 flex-1"
            label={labelled ? t('automation.stage') : undefined}
            value={String(action.status ?? schema.stages[0])}
            onChange={(e) =>
              onChange({
                status: e.target.value,
                // A reason belongs to "lost" and to nothing else. Carrying it
                // into another stage leaves the rule holding a field the
                // executor will not read and nobody can see.
                lost_reason_id: e.target.value === 'lost' ? (lostReasons[0]?.id ?? '') : undefined,
              })
            }
          >
            {schema.stages.map((stage) => (
              <option key={stage} value={stage}>
                {t(`status.${stage}`, { defaultValue: stage })}
              </option>
            ))}
          </Select>

          {/*
            A lost lead carries why it was lost, whoever closed it.

            The executor refuses to close one without a reason, which is the
            point — a sale lost for "price" and one lost for "wrong city" call
            for opposite decisions, and a pipeline full of unexplained losses
            answers neither. Without this input the rule could not supply one,
            so the refusal would have landed in a trace nobody reads instead of
            on the screen where the rule is written.
          */}
          {action.status === 'lost' && (
            <Select
              className="min-w-40 flex-1"
              label={labelled ? t('automation.lostReason') : undefined}
              value={String(action.lost_reason_id ?? '')}
              onChange={(e) => onChange({ lost_reason_id: e.target.value })}
            >
              {lostReasons.length === 0 && <option value="">{t('automation.noLostReasons')}</option>}
              {lostReasons.map((reason) => (
                <option key={reason.id} value={reason.id}>
                  {reason.label}
                </option>
              ))}
            </Select>
          )}
        </>
      )

    case 'adjust_score':
      return (
        <Input
          className="min-w-32"
          type="number"
          label={labelled ? t('automation.scoreBy') : undefined}
          value={String(action.by ?? 10)}
          onChange={(e) => onChange({ by: Number(e.target.value) })}
        />
      )

    case 'schedule_follow_up':
      return (
        <Input
          className="min-w-32"
          type="number"
          label={labelled ? t('automation.inHours') : undefined}
          value={String(action.in_hours ?? 24)}
          onChange={(e) => onChange({ in_hours: Number(e.target.value) })}
        />
      )

    case 'add_tag':
      return (
        <Input
          className="min-w-40 flex-1"
          label={labelled ? t('automation.tag') : undefined}
          value={String(action.tag ?? '')}
          onChange={(e) => onChange({ tag: e.target.value })}
        />
      )

    case 'add_note':
      return (
        <Input
          className="min-w-40 flex-1"
          label={labelled ? t('automation.noteBody') : undefined}
          value={String(action.body ?? '')}
          onChange={(e) => onChange({ body: e.target.value })}
        />
      )

    case 'notify':
      return (
        <>
          <Select
            className="min-w-36"
            label={labelled ? t('automation.audience') : undefined}
            value={String(action.audience ?? 'owner')}
            onChange={(e) => onChange({ audience: e.target.value })}
          >
            <option value="owner">{t('automation.audienceOwner')}</option>
            <option value="managers">{t('automation.audienceManagers')}</option>
          </Select>
          <Input
            className="min-w-40 flex-1"
            label={labelled ? t('automation.message') : undefined}
            value={String(action.message ?? '')}
            onChange={(e) => onChange({ message: e.target.value })}
          />
        </>
      )

    case 'assign':
      return (
        <Select
          className="min-w-40 flex-1"
          label={labelled ? t('automation.strategy') : undefined}
          value={String(action.strategy ?? 'round_robin')}
          onChange={(e) => onChange({ strategy: e.target.value })}
        >
          <option value="round_robin">{t('automation.strategyRoundRobin')}</option>
        </Select>
      )

    default:
      return null
  }
}
