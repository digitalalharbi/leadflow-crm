import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ArrowLeft, Mail, MessageCircle, Phone, Users } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, EmptyState, Skeleton } from '@/components/ui'
import { fetchHistory, type HistoryResult } from '@/features/automation'
import { cn } from '@/lib/cn'

const RESULTS: Array<HistoryResult | ''> = ['', 'completed', 'recovered', 'waiting', 'needs_human', 'failed', 'skipped']

const RESULT_STYLE: Record<HistoryResult, string> = {
  completed: 'bg-[var(--brand-soft)] text-[var(--brand-text)]',
  recovered: 'bg-[color-mix(in_srgb,var(--color-success)_14%,transparent)] text-[var(--color-success)]',
  waiting: 'bg-[color-mix(in_srgb,var(--color-info)_12%,transparent)] text-[var(--color-info)]',
  needs_human: 'bg-[color-mix(in_srgb,var(--color-warning)_15%,transparent)] text-[color-mix(in_srgb,var(--color-warning)_80%,black)]',
  failed: 'bg-[color-mix(in_srgb,var(--color-danger)_12%,transparent)] text-[var(--color-danger)]',
  skipped: 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]',
}

const CHANNEL_ICON: Record<string, LucideIcon> = { whatsapp: MessageCircle, email: Mail, call: Phone, team: Users }

/**
 * Everything automation did, waits to do, or decided against — newest first,
 * one line each: when, who, which rule, what, on which channel, what changed,
 * and how it ended. The proof that no step happened silently.
 */
export function ActionHistory({ initialResult = '' }: { initialResult?: HistoryResult | '' }) {
  const { t, i18n } = useTranslation()
  const [result, setResult] = useState<HistoryResult | ''>(initialResult)
  const [page, setPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['automation-history', result, page],
    queryFn: () => fetchHistory({ result, page }),
    placeholderData: keepPreviousData,
  })

  const time = (iso: string | null) =>
    iso ? new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso)) : '—'

  return (
    <div className="space-y-3" data-testid="action-history">
      <div className="flex flex-wrap gap-1.5" role="tablist" aria-label={t('automation.history.filter')}>
        {RESULTS.map((key) => (
          <button
            key={key || 'all'}
            type="button"
            role="tab"
            aria-selected={result === key}
            onClick={() => { setResult(key); setPage(1) }}
            className={cn(
              'rounded-full px-3 py-1.5 text-[0.9375rem] font-medium',
              result === key ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--color-surface)] text-[var(--color-ink-muted)] shadow-[var(--shadow-card)] hover:text-[var(--color-ink)]',
            )}
            data-testid={`history-filter-${key || 'all'}`}
          >
            {t(key ? `automation.result.${key}` : 'automation.history.all')}
          </button>
        ))}
      </div>

      <div className="overflow-hidden rounded-[var(--radius-card)] bg-[var(--color-surface)] shadow-[var(--shadow-card)]">
        <div className="hidden grid-cols-[7.5rem_minmax(0,1fr)_minmax(0,1.1fr)_minmax(0,1fr)_2.5rem_minmax(0,0.9fr)_7rem] gap-3 border-b border-[var(--color-line)] bg-[var(--color-surface-muted)] px-4 py-2.5 text-[0.875rem] font-semibold text-[var(--color-ink-muted)] lg:grid">
          <span>{t('automation.history.time')}</span>
          <span>{t('automation.history.lead')}</span>
          <span>{t('automation.history.rule')}</span>
          <span>{t('automation.history.action')}</span>
          <span>{t('automation.history.channel')}</span>
          <span>{t('automation.history.change')}</span>
          <span>{t('automation.history.result')}</span>
        </div>

        {isLoading ? (
          <div className="space-y-2 p-4"><Skeleton className="h-8" /><Skeleton className="h-8" /><Skeleton className="h-8" /></div>
        ) : (data?.entries.length ?? 0) === 0 ? (
          <div className="p-6"><EmptyState title={t('automation.history.empty')} body={t('automation.history.emptyHint')} /></div>
        ) : (
          <ul className="divide-y divide-[var(--color-line)]" data-testid="history-rows">
            {data!.entries.map((entry) => {
              const Icon = entry.channel ? CHANNEL_ICON[entry.channel] : null

              return (
                <li key={entry.id} className="grid gap-1 px-4 py-2.5 text-[0.9375rem] lg:grid-cols-[7.5rem_minmax(0,1fr)_minmax(0,1.1fr)_minmax(0,1fr)_2.5rem_minmax(0,0.9fr)_7rem] lg:items-center lg:gap-3" data-testid="history-row">
                  <span className="tabular-nums text-[var(--color-ink-muted)]">{time(entry.at)}</span>
                  <span className="min-w-0 truncate font-semibold">
                    {entry.lead ? <Link to={`/leads/${entry.lead.id}`} className="hover:underline">{entry.lead.name}</Link> : '—'}
                  </span>
                  <span className="min-w-0 truncate text-[var(--color-ink-muted)]" title={entry.rule ?? ''}>{entry.rule ?? '—'}</span>
                  <span className="min-w-0 truncate" title={entry.description ?? ''}>{t(`automation.action_.${entry.action}`, { defaultValue: entry.action_words })}</span>
                  <span className="text-[var(--color-ink-muted)]">{Icon ? <Icon className="size-4" aria-label={t(`automation.channel.${entry.channel}`)} /> : '—'}</span>
                  <span className="min-w-0 truncate tabular-nums" dir="auto">
                    {entry.before || entry.after ? (
                      <span className="inline-flex items-center gap-1">
                        {entry.before && <span className="text-[var(--color-ink-subtle)]">{entry.before}</span>}
                        {entry.before && <ArrowLeft className="size-3 shrink-0 ltr:rotate-180" aria-hidden />}
                        <span className="font-semibold">{entry.after}</span>
                      </span>
                    ) : '—'}
                  </span>
                  <span className={cn('w-fit rounded-full px-2 py-0.5 text-[0.8125rem] font-semibold', RESULT_STYLE[entry.result])} data-testid={`result-${entry.result}`}>
                    {t(`automation.result.${entry.result}`)}
                  </span>
                </li>
              )
            })}
          </ul>
        )}
      </div>

      {data && data.meta.last_page > 1 && (
        <div className="flex items-center justify-center gap-2">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>{t('common.previous')}</Button>
          <span className="t-caption tabular-nums">{page} / {data.meta.last_page}</span>
          <Button size="sm" variant="outline" disabled={page >= data.meta.last_page} onClick={() => setPage((p) => p + 1)}>{t('common.next')}</Button>
        </div>
      )}
    </div>
  )
}
