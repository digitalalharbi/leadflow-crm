import { useQuery } from '@tanstack/react-query'
import { Clock, Filter, Sparkles, Trophy, TrendingDown, TrendingUp } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { cn } from '@/lib/cn'

interface Insight {
  key: string
  tone: 'good' | 'warn' | 'neutral'
  values: Record<string, string | number>
}

const ICON: Record<string, LucideIcon> = {
  leads_up: TrendingUp,
  leads_down: TrendingDown,
  leads_steady: Sparkles,
  leads_this_month: Sparkles,
  best_source: Trophy,
  response_slow: Clock,
  response_fast: Clock,
  biggest_leak: Filter,
}

const STRIPE: Record<Insight['tone'], string> = {
  good: 'var(--color-success)',
  warn: 'var(--color-warning)',
  neutral: 'var(--color-border-strong)',
}

/**
 * What to read first, said in a sentence.
 *
 * Above the tabs and the filters, because it answers a different question: not
 * "show me the numbers" but "what are the numbers telling me". Every sentence
 * carries the figures it is made of, so it can be checked against the tables
 * below it rather than taken on trust — and when there is nothing to say (too
 * few leads, nothing that moved) there is no strip at all, which is better than
 * a confident sentence built on five leads.
 */
export function InsightStrip() {
  const { t } = useTranslation()
  const { data } = useQuery({
    queryKey: ['report-insights'],
    queryFn: () => apiGet<{ insights: Insight[] }>('/reports/insights'),
    staleTime: 5 * 60_000,
  })

  const insights = data?.insights ?? []

  if (insights.length === 0) return null

  return (
    <section
      className="mb-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4"
      aria-label={t('reports.insights.title')}
      data-testid="insight-strip"
    >
      {insights.map((insight) => {
        const Icon = ICON[insight.key] ?? Sparkles

        // Platform and stage are keys on the wire and words on the screen.
        const values = {
          ...insight.values,
          best: insight.values.best ? t(`platforms.${insight.values.best}`, { defaultValue: String(insight.values.best) }) : undefined,
          worst: insight.values.worst ? t(`platforms.${insight.values.worst}`, { defaultValue: String(insight.values.worst) }) : undefined,
          from: insight.values.from ? t(`status.${insight.values.from}`) : undefined,
          to: insight.values.to ? t(`status.${insight.values.to}`) : undefined,
        }

        return (
          <article
            key={insight.key}
            data-testid={`insight-${insight.key}`}
            className="relative overflow-hidden rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 ps-4 shadow-[var(--shadow-card)]"
          >
            <span aria-hidden className="absolute inset-y-0 start-0 w-1" style={{ background: STRIPE[insight.tone] }} />
            <Icon
              className={cn(
                'mb-1.5 size-4',
                insight.tone === 'good' && 'text-[var(--color-success)]',
                insight.tone === 'warn' && 'text-[var(--color-warning)]',
                insight.tone === 'neutral' && 'text-[var(--color-ink-muted)]',
              )}
              aria-hidden
            />
            <p className="text-[0.8125rem] leading-snug">{t(`reports.insights.${insight.key}`, values)}</p>
          </article>
        )
      })}
    </section>
  )
}
