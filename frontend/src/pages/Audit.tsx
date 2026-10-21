import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Bot, CalendarCheck, Coins, FileBarChart, KeyRound, Megaphone, ShieldCheck, ScrollText, UserCheck, Workflow, GitCommitHorizontal } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Card, CardBody, EmptyState, ErrorState, PageHeader, Select, Skeleton } from '@/components/ui'
import { AUDIT_CATEGORIES, fetchAudit, type AuditCategory, type AuditEntry } from '@/features/audit'
import { formatLastSeen } from '@/lib/format'
import { cn } from '@/lib/cn'

const ICON: Record<AuditCategory, LucideIcon> = {
  assignment: UserCheck, status: GitCommitHorizontal, reservation: KeyRound, visit: CalendarCheck, deal: Coins,
  automation: Workflow, reports: FileBarChart, permissions: ShieldCheck, campaigns: Megaphone, other: ScrollText,
}

const SOURCE_TONE: Record<AuditEntry['source'], 'neutral' | 'info' | 'warning'> = { user: 'neutral', automation: 'info', system: 'warning' }

/**
 * History: who did what, to what, from what to what, when — and whether a person or the machine did it.
 * One list for what people change in the workspace and what happens to each lead, newest first.
 */
export function AuditPage() {
  const { t, i18n } = useTranslation()
  const [category, setCategory] = useState<AuditCategory | ''>('')
  const [days, setDays] = useState(30)
  const Arrow = i18n.dir() === 'rtl' ? ArrowLeft : ArrowRight

  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['audit', category, days], queryFn: () => fetchAudit(category, days) })

  return (
    <>
      <PageHeader
        title={t('audit.title')}
        subtitle={t('audit.subtitle')}
        actions={
          <Select value={String(days)} onChange={(e) => setDays(Number(e.target.value))} className="filter-pill min-w-32" aria-label={t('common.date')} data-testid="audit-days">
            {[7, 30, 90, 365].map((d) => <option key={d} value={d}>{t('dashboard.lastDays', { count: d })}</option>)}
          </Select>
        }
      />

      <div className="mb-3 flex flex-wrap gap-1.5" role="group" aria-label={t('audit.category.all')} data-testid="audit-categories">
        {(['', ...AUDIT_CATEGORIES] as const).map((key) => {
          const Icon = key ? ICON[key] : ScrollText

          return (
            <button
              key={key || 'all'}
              type="button"
              aria-pressed={category === key}
              data-testid={`audit-cat-${key || 'all'}`}
              onClick={() => setCategory(key)}
              className={cn('pressable inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[0.9375rem] font-semibold', category === key ? 'bg-[var(--brand)] text-[var(--brand-ink)]' : 'bg-[var(--color-surface)] shadow-[var(--shadow-card)]')}
            >
              <Icon className="size-4" aria-hidden />
              {t(key ? `audit.category.${key}` : 'audit.category.all')}
            </button>
          )
        })}
      </div>

      {isError ? (
        <Card><ErrorState onRetry={() => void refetch()} /></Card>
      ) : isLoading || !data ? (
        <Skeleton className="h-64" />
      ) : data.entries.length === 0 ? (
        <Card><CardBody><EmptyState icon={<ScrollText className="size-6" />} title={t('audit.emptyTitle')} body={t('audit.emptyBody')} /></CardBody></Card>
      ) : (
        <ul className="space-y-1.5" data-testid="audit-list">
          {data.entries.map((entry) => {
            const Icon = entry.source === 'automation' ? Bot : ICON[entry.category]

            return (
              <li key={entry.id} data-testid={`audit-${entry.action}`} data-category={entry.category} data-source={entry.source} className="flex items-center gap-3 rounded-xl bg-[var(--color-surface)] px-3 py-2 shadow-[var(--shadow-card)]">
                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--color-surface-muted)] text-[var(--color-ink-muted)]" aria-hidden><Icon className="size-[1.125rem]" /></span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[0.9375rem] font-semibold">
                    {t(`audit.action.${entry.action}`, { defaultValue: entry.action })}
                    {entry.subject && (entry.lead_id ? <Link to={`/leads/${entry.lead_id}`} className="ms-2 font-normal hover:underline">{entry.subject}</Link> : <span className="ms-2 font-normal text-[var(--color-ink-muted)]">{entry.subject}</span>)}
                  </p>
                  <p className="t-caption flex flex-wrap items-center gap-x-2">
                    <span>{entry.actor ?? t(`audit.source.${entry.source}`)}</span>
                    <span>{formatLastSeen(entry.at, i18n.language)}</span>
                  </p>
                </div>
                {(entry.before || entry.after) && (
                  <span className="flex min-w-0 max-w-[40%] shrink items-center gap-1.5 text-[0.875rem]" data-testid="audit-change">
                    {entry.before && <span className="truncate rounded-md bg-[var(--color-surface-muted)] px-1.5 py-0.5 text-[var(--color-ink-muted)] line-through" dir="auto">{entry.before}</span>}
                    {entry.before && entry.after && <Arrow className="size-3.5 shrink-0 text-[var(--color-ink-subtle)]" aria-hidden />}
                    {entry.after && <span className="truncate rounded-md bg-[color-mix(in_srgb,var(--brand)_12%,transparent)] px-1.5 py-0.5 font-semibold" dir="auto">{entry.after}</span>}
                  </span>
                )}
                <Badge tone={SOURCE_TONE[entry.source]}>{t(`audit.source.${entry.source}`)}</Badge>
              </li>
            )
          })}
        </ul>
      )}
    </>
  )
}
