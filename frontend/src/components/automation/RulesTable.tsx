import { Copy, Eye, Pencil, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'
import { RuleFlow } from '@/components/automation/RuleFlow'
import type { AutomationRule } from '@/features/automation'
import { formatRelative } from '@/lib/format'
import { cn } from '@/lib/cn'

const MODE_STYLE: Record<string, string> = {
  auto: 'bg-[color-mix(in_srgb,var(--brand)_12%,transparent)] text-[var(--brand)]',
  recommend: 'bg-[color-mix(in_srgb,var(--color-warning)_15%,transparent)] text-[color-mix(in_srgb,var(--color-warning)_80%,black)]',
  monitor: 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]',
}

const RESULT_STYLE: Record<string, string> = {
  applied: 'text-[var(--brand)]',
  skipped: 'text-[var(--color-ink-muted)]',
  failed: 'text-[var(--color-danger)]',
}

/**
 * The rules as a table a person scans in seconds: on or off, what it is, the
 * path it follows (trigger → conditions → actions, drawn rather than written),
 * which project, how it runs, and how it last ran. Everything else is one
 * press away on the row.
 */
export function RulesTable({
  rules,
  onToggle,
  onPreview,
  onEdit,
  onDuplicate,
  onDelete,
  busyId,
}: {
  rules: AutomationRule[]
  onToggle: (rule: AutomationRule) => void
  onPreview: (rule: AutomationRule) => void
  onEdit: (rule: AutomationRule) => void
  onDuplicate: (rule: AutomationRule) => void
  onDelete: (rule: AutomationRule) => void
  busyId?: string | null
}) {
  const { t, i18n } = useTranslation()

  return (
    <div className="overflow-hidden rounded-[var(--radius-card)] bg-[var(--color-surface)] shadow-[var(--shadow-card)]" data-testid="rules-list">
      <div className="hidden grid-cols-[6.5rem_minmax(0,1.3fr)_minmax(0,2.6fr)_minmax(0,0.8fr)_5.5rem_minmax(0,1fr)_7rem] gap-3 border-b border-[var(--color-line)] bg-[var(--color-surface-muted)] px-4 py-2.5 text-[0.75rem] font-semibold text-[var(--color-ink-muted)] lg:grid">
        <span>{t('automation.table.onOff')}</span>
        <span>{t('automation.table.rule')}</span>
        <span>{t('automation.table.flow')}</span>
        <span>{t('automation.table.project')}</span>
        <span>{t('automation.table.mode')}</span>
        <span>{t('automation.table.lastRun')}</span>
        <span className="sr-only">{t('common.actions')}</span>
      </div>

      <ul className="divide-y divide-[var(--color-line)]">
        {rules.map((rule) => (
          <li
            key={rule.id}
            className="grid gap-2 px-4 py-3 lg:grid-cols-[6.5rem_minmax(0,1.3fr)_minmax(0,2.6fr)_minmax(0,0.8fr)_5.5rem_minmax(0,1fr)_7rem] lg:items-center lg:gap-3"
            data-testid={`rule-row-${rule.id}`}
          >
            <div className="flex items-center gap-2">
              <button
                type="button"
                aria-pressed={rule.is_enabled}
                aria-label={t(rule.is_enabled ? 'automation.turnOff' : 'automation.turnOn')}
                onClick={() => onToggle(rule)}
                disabled={busyId === rule.id}
                data-testid={`toggle-${rule.id}`}
                className={cn(
                  'relative h-6 w-11 shrink-0 rounded-full transition-colors',
                  rule.is_enabled ? 'bg-[var(--brand)]' : 'bg-[var(--color-border-strong)]',
                )}
              >
                <span className={cn('absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', rule.is_enabled ? 'start-[1.375rem]' : 'start-0.5')} />
                <span className="sr-only">{t(rule.is_enabled ? 'automation.turnOff' : 'automation.turnOn')}</span>
              </button>
              <span className={cn('text-[0.75rem] font-medium', rule.is_enabled ? 'text-[var(--brand)]' : 'text-[var(--color-ink-muted)]')}>
                {t(rule.is_enabled ? 'automation.on' : 'automation.off')}
              </span>
            </div>

            <div className="min-w-0">
              <p className="truncate text-[0.875rem] font-semibold" title={rule.name}>{rule.name}</p>
              <p className="t-caption truncate">
                {[t('automation.conditionCount', { count: rule.conditions.length }), t('automation.actionCount', { count: rule.actions.length })].join(' · ')}
              </p>
            </div>

            <div className="min-w-0">
              <RuleFlow testId={`rule-flow-${rule.id}`} trigger={rule.trigger} conditions={rule.conditions} actions={rule.actions} />
            </div>

            <span className="truncate text-[0.8125rem]">{rule.project ?? t('automation.table.allProjects')}</span>

            <span className={cn('w-fit rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold', MODE_STYLE[rule.mode ?? 'auto'])} data-testid={`rule-mode-${rule.id}`}>
              {t(`automation.mode.${rule.mode ?? 'auto'}`)}
            </span>

            <div className="text-[0.75rem]">
              {rule.last_run ? (
                <>
                  <span className={cn('font-semibold', RESULT_STYLE[rule.last_run.outcome])}>{t(`automation.outcome.${rule.last_run.outcome}`)}</span>
                  <span className="block text-[var(--color-ink-subtle)]">{formatRelative(rule.last_run.at, i18n.language)}</span>
                </>
              ) : (
                <span className="text-[var(--color-ink-subtle)]">{t('automation.table.neverRan')}</span>
              )}
            </div>

            <div className="flex items-center gap-0.5 lg:justify-end">
              <Button size="icon" variant="ghost" aria-label={t('automation.preview')} title={t('automation.preview')} onClick={() => onPreview(rule)} icon={<Eye className="size-4" />} />
              <Button size="icon" variant="ghost" aria-label={t('automation.edit')} title={t('automation.edit')} onClick={() => onEdit(rule)} icon={<Pencil className="size-4" />} />
              <Button size="icon" variant="ghost" aria-label={t('automation.duplicate')} title={t('automation.duplicate')} onClick={() => onDuplicate(rule)} icon={<Copy className="size-4" />} data-testid={`duplicate-rule-${rule.id}`} />
              <Button size="icon" variant="ghost" aria-label={t('automation.delete')} title={t('automation.delete')} onClick={() => onDelete(rule)} icon={<Trash2 className="size-4" />} />
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
