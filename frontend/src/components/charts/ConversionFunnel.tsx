import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ChevronDown } from 'lucide-react'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

export interface FunnelStage {
  key: string
  count: number
  of_total: number
  from_previous: number
}

/**
 * The funnel, as proportional bars with the conversion between them.
 *
 * Not the cone: a drawn funnel spends most of its pixels on the shape and
 * leaves the numbers small, and the number everyone actually wants is the drop
 * between two stages. Here each stage is a bar whose width *is* its share, and
 * the step conversion sits in the gap where the loss happens.
 *
 * Every row is a link to the leads it counts, because a funnel you cannot open
 * is a picture rather than a tool.
 */
export function ConversionFunnel({ stages }: { stages: FunnelStage[] }) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()

  const total = stages[0]?.count ?? 0

  // Each stage's filter. "Reached this stage" is not the same as "is in this
  // stage", so the links carry the same meaning the bars do.
  const target: Record<string, string> = {
    total: '/leads',
    contacted: '/leads?status[]=contacted&status[]=qualified&status[]=appointment&status[]=won',
    qualified: '/leads?qualification_status=qualified',
    appointment: '/leads?status[]=appointment&status[]=won',
    won: '/leads?status[]=won',
  }

  if (total === 0) {
    return null
  }

  return (
    <div className="space-y-1 px-3 py-1" data-testid="conversion-funnel">
      {stages.map((stage, index) => {
        const width = Math.max((stage.count / total) * 100, stage.count > 0 ? 6 : 1.5)
        const previous = stages[index - 1]

        return (
          <div key={stage.key}>
            {/* The drop between stages, in the space where it happens. */}
            {previous && (
              <div className="flex items-center gap-1.5 ps-1 pb-1" aria-hidden>
                <ChevronDown className="size-3 text-[var(--color-ink-subtle)]" />
                <span
                  className={cn(
                    'tabular text-[0.6875rem]',
                    stage.from_previous >= 50
                      ? 'text-[var(--color-success)]'
                      : stage.from_previous >= 20
                        ? 'text-[var(--color-ink-muted)]'
                        : 'text-[var(--color-warning)]',
                  )}
                  dir="ltr"
                >
                  {stage.from_previous}%
                </span>
              </div>
            )}

            <button
              type="button"
              onClick={() => navigate(target[stage.key] ?? '/leads')}
              data-testid={`funnel-${stage.key}`}
              className={cn(
                'group relative block w-full overflow-hidden rounded-[var(--radius-control)]',
                'px-3 py-2.5 text-start transition-colors',
                'hover:bg-[var(--color-surface-sunken)] focus-visible:bg-[var(--color-surface-sunken)]',
              )}
            >
              {/* The bar is the background, so the label always sits on it and
                  never wraps under a narrow stage. */}
              <span
                className="absolute inset-y-0 start-0 rounded-[var(--radius-control)] transition-[width] duration-[var(--duration-slow)]"
                style={{
                  width: `${width}%`,
                  background: `color-mix(in srgb, var(--chart-1) ${26 - index * 3}%, transparent)`,
                }}
                aria-hidden
              />

              <span className="relative flex items-baseline justify-between gap-3">
                <span className="t-small font-medium">{t(`funnel.${stage.key}`)}</span>

                <span className="flex items-baseline gap-2.5">
                  <span className="tabular text-[0.75rem] text-[var(--color-ink-muted)]" dir="ltr">
                    {stage.of_total}%
                  </span>
                  <span className="t-metric-sm">{formatNumber(stage.count, i18n.language)}</span>
                </span>
              </span>
            </button>
          </div>
        )
      })}
    </div>
  )
}
