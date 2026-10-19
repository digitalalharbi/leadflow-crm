import {
  Bell, Clock, FileText, Filter, Flag, ListChecks, Mail, MessageCircle, Octagon, StickyNote,
  Tag, TrendingUp, UserCheck, Zap, ArrowRightLeft, ChevronLeft,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { RuleAction, RuleCondition } from '@/features/automation'
import { cn } from '@/lib/cn'

type Kind = 'trigger' | 'condition' | 'action' | 'wait' | 'outcome'

interface Node {
  key: string
  kind: Kind
  Icon: LucideIcon
  label: string
  title?: string
}

const ACTION_ICON: Record<string, LucideIcon> = {
  assign: UserCheck,
  change_stage: ArrowRightLeft,
  adjust_score: TrendingUp,
  add_tag: Tag,
  schedule_follow_up: ListChecks,
  add_note: StickyNote,
  notify: Bell,
  stop_follow_ups: Octagon,
  send_whatsapp: MessageCircle,
  send_email: Mail,
  share_file: FileText,
}

/** Each kind of step has its own colour, so the shape of a rule is readable before any word is. */
const KIND: Record<Kind, string> = {
  trigger: 'bg-[var(--brand)] text-[var(--brand-contrast)]',
  condition: 'bg-[color-mix(in_srgb,var(--color-warning)_16%,transparent)] text-[var(--color-warning)]',
  action: 'bg-[var(--brand-soft)] text-[var(--brand)]',
  wait: 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]',
  outcome: 'bg-[color-mix(in_srgb,var(--color-status-won)_14%,transparent)] text-[var(--color-status-won)]',
}

/**
 * A rule as the path it describes.
 *
 * TRIGGER → CONDITION → ACTION → WAIT → ACTION → OUTCOME: the same list the
 * editor shows as form fields, drawn as steps in the order they happen. Someone
 * who has never opened the editor can see what a rule does — "new lead, in this
 * project, send a WhatsApp, wait four hours, remind" — without learning what a
 * condition operator is. The wait is its own step because it is the part that
 * is easiest to miss in a list and the part that changes what a rule means.
 */
export function RuleFlow({
  trigger,
  conditions,
  actions,
  matched,
  testId,
}: {
  trigger: string
  conditions: RuleCondition[]
  actions: RuleAction[]
  /** How many times it has fired; the last step says so. Omitted for a template. */
  matched?: number
  testId?: string
}) {
  const { t } = useTranslation()

  const nodes: Node[] = [{ key: 'trigger', kind: 'trigger', Icon: Zap, label: t(`automation.trigger.${trigger}`) }]

  if (conditions.length > 0) {
    const first = conditions[0]
    /*
     * Values in the words the rest of the product uses: `hot` is "مرتفع" and
     * `appointment` is "موعد" everywhere else, and a rule that says otherwise
     * reads like a developer's note. A true/false has no value to show — the
     * field name ("لديه واتساب") is already the whole statement.
     */
    const value = (c: RuleCondition): string => {
      if (c.value === undefined || c.value === null || typeof c.value === 'boolean') return ''
      const raw = String(c.value)
      if (c.field === 'status') return t(`status.${raw}`, { defaultValue: raw })
      if (c.field === 'temperature') return t(`temperature.${raw}`, { defaultValue: raw })
      return raw
    }

    const summary = (c: RuleCondition) =>
      [t(`automation.field_.${c.field}`, { defaultValue: c.field }), typeof c.value === 'boolean' ? '' : t(`automation.operator_.${c.operator}`, { defaultValue: c.operator }), value(c)]
        .filter(Boolean)
        .join(' ')

    nodes.push({
      key: 'condition',
      kind: 'condition',
      Icon: Filter,
      label: conditions.length > 1 ? `${t(`automation.field_.${first.field}`, { defaultValue: first.field })} +${conditions.length - 1}` : summary(first),
      title: conditions.map(summary).join(' · '),
    })
  }

  actions.forEach((action, index) => {
    if (typeof action.delay_minutes === 'number' && action.delay_minutes > 0) {
      nodes.push({
        key: `wait-${index}`,
        kind: 'wait',
        Icon: Clock,
        label: t(`automation.delay_.${action.delay_minutes}`, { defaultValue: `${action.delay_minutes}m` }),
      })
    }

    nodes.push({
      key: `action-${index}`,
      kind: 'action',
      Icon: ACTION_ICON[action.type] ?? Zap,
      label: t(`automation.action_.${action.type}`, { defaultValue: action.type }),
    })
  })

  if (matched !== undefined) {
    nodes.push({ key: 'outcome', kind: 'outcome', Icon: Flag, label: String(matched) })
  }

  return (
    <ol className="flex flex-wrap items-center gap-y-2" data-testid={testId}>
      {nodes.map((node, index) => (
        <li key={node.key} className="flex items-center" data-kind={node.kind}>
          <span
            title={node.title ?? node.label}
            className={cn(
              'inline-flex max-w-[11rem] items-center gap-1.5 rounded-full px-2.5 py-1 text-[0.875rem] font-medium',
              KIND[node.kind],
            )}
          >
            <node.Icon className="size-3.5 shrink-0" aria-hidden />
            <span className={cn('truncate', node.kind === 'outcome' && 'tabular-nums')}>{node.label}</span>
          </span>
          {index < nodes.length - 1 && (
            <ChevronLeft className="mx-1 size-3.5 shrink-0 text-[var(--color-ink-subtle)] ltr:rotate-180" aria-hidden />
          )}
        </li>
      ))}
    </ol>
  )
}
