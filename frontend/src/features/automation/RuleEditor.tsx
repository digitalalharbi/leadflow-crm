import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Bell, Filter, Plus, Workflow, X, Zap } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Modal, Select } from '@/components/ui'
import { fetchAssignableUsers, fetchLostReasons } from '@/features/leads'
import { fetchMessageTemplates } from '@/features/conversations'
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
  /*
   * No template id until one is chosen, which is what the required-field error
   * is for. Seeding the first template instead would send whichever message
   * happened to be at the top of somebody's list to real customers.
   */
  send_whatsapp: { template_uuid: '' },
  // Same reasoning as above: an email template is chosen, never defaulted.
  send_email: { template_uuid: '' },
  ask_qualification: {},
  set_priority: {},
  hand_off: { reason: 'needs_human' },
  set_temperature: { temperature: 'hot' },
  stop_automation: {},
  start_nurture: {},
  create_call_task: { in_hours: 1 },
  request_appointment: {},
  // Left empty on purpose: with no kind, the chooser reads the lead's own
  // answers, which is the behaviour worth having by default.
  share_file: { kind: '', send: true, channel: 'whatsapp' },
}

function withDefaults(type: string): RuleAction {
  return { type, ...ACTION_DEFAULTS[type] }
}

/**
 * The waits a rule can ask for, as durations rather than a number of minutes.
 *
 * A closed list because this is a sequence, not a scheduler: somebody writing
 * "follow up in two days" should pick two days, not work out that it is 2,880
 * minutes. The API accepts any minute count up to thirty days, so a rule
 * written elsewhere is still honoured.
 */
const PURPOSES = ['welcome', 'qualification', 'reminder', 'nurture', 'appointment', 'brochure'] as const

const DELAYS = [5, 15, 60, 240, 1440, 2880, 4320, 10080, 20160, 43200] as const

/** The three ways a rule can run: do it, suggest it, or only write it down. */
const MODES = ['auto', 'recommend', 'monitor'] as const

/**
 * What a project file can be, for the one action that may ask for a kind.
 *
 * A closed list rather than a free field, and the same list the API validates
 * against — a kind nobody uploads is a rule that reports "no file of this kind"
 * forever.
 */
const FILE_KINDS = [
  'brochure', 'profile', 'price_list', 'investment_deck', 'financing_guide', 'other',
] as const

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

  // Only the people a lead can actually be given to: a rule naming somebody who
  // cannot hold leads would fail on every run, in a trace nobody reads.
  const { data: assignees = [] } = useQuery({
    queryKey: ['assignable-users'],
    queryFn: fetchAssignableUsers,
  })

  /*
   * Active templates only, for the same reason as the lost reasons above: a
   * rule pointing at a message somebody has since paused reports a failure on
   * every run, in a trace nobody reads until a customer says they heard
   * nothing.
   */
  const { data: templates } = useQuery({
    queryKey: ['message-templates'],
    queryFn: () => fetchMessageTemplates(),
  })

  const { data: emailTemplates } = useQuery({
    queryKey: ['message-templates', 'email'],
    queryFn: () => fetchMessageTemplates(false, 'email'),
  })

  const [name, setName] = useState(rule?.name ?? '')
  const [trigger, setTrigger] = useState(rule?.trigger ?? schema.triggers[0])
  const [priority, setPriority] = useState(String(rule?.priority ?? 100))
  const [conditions, setConditions] = useState<RuleCondition[]>(rule?.conditions ?? [])
  const [conditionMatch, setConditionMatch] = useState(rule?.condition_match ?? 'all')
  const [actions, setActions] = useState<RuleAction[]>(
    rule?.actions ?? [withDefaults(schema.actions[0])],
  )
  const [mode, setMode] = useState<string>(rule?.mode ?? 'auto')

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
      condition_match: conditionMatch,
      conditions: conditions.map((condition) => ({
        field: condition.field,
        operator: condition.operator,
        value: VALUELESS.has(condition.operator) ? null : (condition.value ?? null),
      })),
      actions,
      mode,
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

        <Step n={1} icon={Zap} title={t('automation.step.trigger')} />
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
          <Step n={2} icon={Filter} title={t('automation.step.conditions')} />
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <h3 className="t-body font-medium">{t('automation.ifLabel')}</h3>

              {/*
                Shown only once there are two conditions, because with one it is
                a choice between two identical outcomes — and a control that
                changes nothing is a thing somebody has to stop and think about.
              */}
              {conditions.length > 1 && (
                <select
                  value={conditionMatch}
                  onChange={(event) => setConditionMatch(event.target.value)}
                  data-testid="condition-match"
                  aria-label={t('automation.matchLabel')}
                  className="rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface)] px-2 py-1 text-[0.9375rem]"
                >
                  <option value="all">{t('automation.matchAll')}</option>
                  <option value="any">{t('automation.matchAny')}</option>
                </select>
              )}
            </div>
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
          <Step n={3} icon={Workflow} title={t('automation.step.action')} hint={t('automation.step.timingHint')} />
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
                assignees={assignees}
                templates={templates?.templates ?? []}
                emailTemplates={emailTemplates?.templates ?? []}
                onChange={(patch) => patchAction(index, patch)}
              />

              {/*
                When, not just what.
                
                Every action happened the instant the rule ran, which left the
                most ordinary sequence in the business unwritable: "if nobody
                has replied in two days, send a WhatsApp". Blank means now, so
                every rule already written keeps meaning what it meant.
              */}
              <Select
                className="min-w-32"
                label={index === 0 ? t('automation.when') : undefined}
                value={String(action.delay_minutes ?? '')}
                data-testid={`action-delay-${index}`}
                onChange={(e) =>
                  patchAction(index, {
                    delay_minutes: e.target.value === '' ? undefined : Number(e.target.value),
                  })
                }
              >
                <option value="">{t('automation.delayNone')}</option>
                {DELAYS.map((minutes) => (
                  <option key={minutes} value={minutes}>
                    {t('automation.delayAfter', { duration: t(`automation.delay_.${minutes}`) })}
                  </option>
                ))}
              </Select>

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

        {/*
          How the rule runs. Auto is the default for the ordinary work —
          messages, follow-ups, scoring — and a person steps in only where a
          rule says Recommend.
        */}
        <section className="space-y-2">
          <Step n={5} icon={Bell} title={t('automation.step.mode')} />
          <div className="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label={t('automation.step.mode')} data-testid="rule-mode">
            {MODES.map((key) => (
              <button
                key={key}
                type="button"
                role="radio"
                aria-checked={mode === key}
                onClick={() => setMode(key)}
                data-testid={`mode-${key}`}
                className={
                  mode === key
                    ? 'rounded-xl border-2 border-[var(--brand)] bg-[color-mix(in_srgb,var(--brand)_8%,transparent)] p-3 text-start'
                    : 'rounded-xl border border-[var(--color-line)] p-3 text-start hover:bg-[var(--color-surface-muted)]'
                }
              >
                <span className="block text-[1rem] font-semibold">{t(`automation.mode.${key}`)}</span>
                <span className="block text-[0.8125rem] text-[var(--color-ink-muted)]">{t(`automation.modeHint.${key}`)}</span>
              </button>
            ))}
          </div>
        </section>
      </div>
    </Modal>
  )
}

/** A numbered step of the builder: what to decide here, in two or three words. */
function Step({ n, icon: Icon, title, hint }: { n: number; icon: LucideIcon; title: string; hint?: string }) {
  return (
    <div className="flex items-center gap-2.5">
      <span className="grid size-7 shrink-0 place-items-center rounded-full bg-[var(--brand)] text-[0.875rem] font-bold text-[var(--brand-contrast)]" aria-hidden>
        {n}
      </span>
      <Icon className="size-4 text-[var(--color-ink-muted)]" aria-hidden />
      <h3 className="text-[1.0625rem] font-semibold">{title}</h3>
      {hint && <span className="t-caption">{hint}</span>}
    </div>
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
  assignees,
  templates,
  emailTemplates,
  onChange,
}: {
  action: RuleAction
  schema: AutomationSchema
  labelled: boolean
  lostReasons: { id: string; label: string }[]
  assignees: { id: string; name: string }[]
  templates: { id: string; name: string }[]
  emailTemplates: { id: string; name: string }[]
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

    /*
      The wording lives in the workspace's saved messages, not in the rule.
      A manager changing what the product says to customers should change it
      where the messages are listed — not by opening six rules to find which of
      them carries that sentence.
    */
    case 'send_whatsapp':
    case 'send_email': {
      const list = action.type === 'send_email' ? emailTemplates : templates
      const value = action.purpose ? `purpose:${String(action.purpose)}` : action.template_uuid ? `template:${String(action.template_uuid)}` : ''

      return (
        <Select
          className="min-w-48 flex-1"
          label={labelled ? t(action.type === 'send_email' ? 'automation.emailTemplate' : 'automation.template') : undefined}
          value={value}
          data-testid="action-message"
          onChange={(e) => {
            const [kind, id] = e.target.value.split(':')
            onChange(kind === 'purpose' ? { purpose: id, template_uuid: undefined } : { template_uuid: id, purpose: undefined })
          }}
        >
          {/*
            Unselectable until there is one to name. By purpose is the usual
            choice: each lead then gets the version written for its project,
            language and stage.
          */}
          <option value="" disabled>{t('automation.pickTemplate')}</option>
          <optgroup label={t('automation.byPurpose')}>
            {PURPOSES.map((purpose) => (
              <option key={purpose} value={`purpose:${purpose}`}>{t(`templates.purpose.${purpose}`)}</option>
            ))}
          </optgroup>
          {list.length > 0 && (
            <optgroup label={t('automation.byTemplate')}>
              {list.map((template) => (
                <option key={template.id} value={`template:${template.id}`}>{template.name}</option>
              ))}
            </optgroup>
          )}
        </Select>
      )
    }

    case 'set_temperature':
      return (
        <Select
          className="min-w-36"
          label={labelled ? t('automation.classification') : undefined}
          value={String(action.temperature ?? 'hot')}
          onChange={(e) => onChange({ temperature: e.target.value })}
        >
          {['hot', 'warm', 'cold'].map((key) => <option key={key} value={key}>{t(`temperature.${key}`, { defaultValue: key })}</option>)}
        </Select>
      )

    case 'create_call_task':
      return (
        <Input
          className="min-w-32"
          type="number"
          label={labelled ? t('automation.inHours') : undefined}
          value={String(action.in_hours ?? 1)}
          onChange={(e) => onChange({ in_hours: Number(e.target.value) })}
        />
      )

    case 'hand_off':
      return (
        <Select
          className="min-w-40 flex-1"
          label={labelled ? t('automation.handOffReason') : undefined}
          value={String(action.reason ?? 'needs_human')}
          onChange={(e) => onChange({ reason: e.target.value })}
        >
          {['needs_human', 'replied', 'qualified', 'no_reply', 'asked_for_human'].map((key) => (
            <option key={key} value={key}>{t(`workspace.handoff.${key}`)}</option>
          ))}
        </Select>
      )

    case 'share_file':
      return (
        <>
          <Select
            className="min-w-40 flex-1"
            label={labelled ? t('automation.fileKind') : undefined}
            value={String(action.kind ?? '')}
            onChange={(e) => onChange({ kind: e.target.value || undefined })}
          >
            {/*
              The default is not "brochure" — it is "whatever this lead's own
              answers point at". Someone buying to invest wants the numbers.
            */}
            <option value="">{t('automation.fileKindAuto')}</option>
            {FILE_KINDS.map((kind) => (
              <option key={kind} value={kind}>
                {t(`files.kind.${kind}`, { defaultValue: kind })}
              </option>
            ))}
          </Select>

          <Select
            className="min-w-36"
            label={labelled ? t('automation.delivery') : undefined}
            value={action.send === false ? 'prepare' : 'send'}
            onChange={(e) => onChange({ send: e.target.value === 'send' })}
          >
            <option value="send">{t('automation.deliverySend')}</option>
            <option value="prepare">{t('automation.deliveryPrepare')}</option>
          </Select>

          {/* By which channel. WhatsApp unless the rule says email. */}
          <Select
            className="min-w-32"
            label={labelled ? t('automation.fileChannel') : undefined}
            value={String(action.channel ?? 'whatsapp')}
            disabled={action.send === false}
            onChange={(e) => onChange({ channel: e.target.value })}
            data-testid="file-channel"
          >
            <option value="whatsapp">{t('whatsapp.channel')}</option>
            <option value="email">{t('email.channel')}</option>
          </Select>
        </>
      )

    case 'assign':
      return (
        <>
          <Select
            className="min-w-40 flex-1"
            label={labelled ? t('automation.strategy') : undefined}
            value={String(action.strategy ?? 'round_robin')}
            onChange={(e) =>
              onChange({
                strategy: e.target.value,
                // The rep belongs to "a specific person" and to nothing else.
                // Left behind, the rule would carry a name the executor ignores.
                user_id: e.target.value === 'specific' ? (assignees[0]?.id ?? '') : undefined,
              })
            }
          >
            <option value="round_robin">{t('automation.strategyRoundRobin')}</option>
            {/*
              Unselectable until there is somebody to name — which also covers
              the moment before the list has loaded. Otherwise the rule is saved
              holding an empty rep while the select below displays a real one,
              and the API refuses it with a message about a field the person
              never saw.
            */}
            <option value="specific" disabled={assignees.length === 0}>
              {t('automation.strategySpecific')}
            </option>
          </Select>

          {/*
            The executor has always supported naming a rep, and nothing could
            say one: the builder offered a single strategy, and the API dropped
            `strategy` and `user_id` on the way in because neither had a
            validation rule. So "every lead from this campaign goes to نورة" —
            which is how a developer actually runs a project launch — could not
            be written at all.
          */}
          {action.strategy === 'specific' && (
            <Select
              className="min-w-40 flex-1"
              label={labelled ? t('automation.assignee') : undefined}
              value={String(action.user_id ?? '')}
              onChange={(e) => onChange({ user_id: e.target.value })}
            >
              {assignees.length === 0 && <option value="">{t('automation.noAssignees')}</option>}
              {assignees.map((user) => (
                <option key={user.id} value={user.id}>
                  {user.name}
                </option>
              ))}
            </Select>
          )}
        </>
      )

    default:
      return null
  }
}
