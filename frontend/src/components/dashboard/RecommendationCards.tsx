import { Link } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Lightbulb, Siren, TriangleAlert } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useRecommendations, type Recommendation } from '@/features/recommendations'
import { findingText } from '@/lib/findings'
import { cn } from '@/lib/cn'

const TONE: Record<Recommendation['severity'], { color: string; Icon: typeof Lightbulb }> = {
  critical: { color: 'var(--color-danger)', Icon: Siren },
  warning: { color: 'var(--color-warning)', Icon: TriangleAlert },
  info: { color: 'var(--brand)', Icon: Lightbulb },
}

/**
 * From "something moved" to "do this": a few findings that compare campaigns, partners and reps, each
 * with what it means, what to do, and the place to go — a sentence each, never a paragraph. Silent
 * when there is nothing to compare.
 */
export function RecommendationCards({ limit = 4, className }: { limit?: number; className?: string }) {
  const { t, i18n } = useTranslation()
  const { data } = useRecommendations(30)
  const Arrow = i18n.dir() === 'rtl' ? ArrowLeft : ArrowRight
  const items = (data?.items ?? []).slice(0, limit)

  if (items.length === 0) return null

  return (
    <section className={cn('space-y-2', className)} data-testid="recommendations" aria-label={t('recommendations.title')}>
      <h2 className="t-card-title px-1">{t('recommendations.title')}</h2>
      <ul className="grid gap-2 md:grid-cols-2" data-testid="recommendation-list">
        {items.map((item) => {
          const { color, Icon } = TONE[item.severity]

          return (
            <li key={item.key} data-testid={`rec-${item.key}`} data-severity={item.severity} className="space-y-1 rounded-xl bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]" style={{ borderInlineStart: `3px solid ${color}` }}>
              <p className="flex items-start gap-2 text-[0.9375rem] font-semibold leading-snug">
                <Icon className="mt-0.5 size-4 shrink-0" style={{ color }} aria-hidden />
                <span data-testid="rec-finding">{findingText(t, 'finding', item)}</span>
              </p>
              <p className="t-caption ps-6" data-testid="rec-impact">{findingText(t, 'impact', item)}</p>
              <p className="flex items-center justify-between gap-2 ps-6 text-[0.9375rem]">
                <span data-testid="rec-action">{findingText(t, 'action', item)}</span>
                {item.link && (
                  <Link to={item.link} data-testid="rec-link" className="inline-flex shrink-0 items-center gap-1 font-semibold text-[var(--brand)] hover:underline">
                    {t('recommendations.open')}<Arrow className="size-4" aria-hidden />
                  </Link>
                )}
              </p>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
