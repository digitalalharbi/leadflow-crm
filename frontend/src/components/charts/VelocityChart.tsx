import { useTranslation } from 'react-i18next'
import { SERIES } from './palette'

export interface VelocityStep {
  step: string
  median_hours: number | null
  count: number
}

/**
 * Where deals stall.
 *
 * The question a funnel cannot answer. A funnel says ten of forty reached an
 * appointment; it says nothing about the ones that waited three weeks for a
 * first call — which is the part a manager can act on this afternoon.
 *
 * Horizontal bars because the labels are phrases, not dates: a step named
 * "من الوصول إلى أول تواصل" set vertically is either rotated or truncated, and
 * both are worse than turning the chart on its side.
 *
 * Each bar carries its own number. Three bars do not need an axis, and a
 * gridline here would be furniture — the comparison people make is between the
 * bars, not against a scale.
 */
export function VelocityChart({ steps }: { steps: VelocityStep[] }) {
  const { t } = useTranslation()

  const measured = steps.filter((step) => step.median_hours !== null && step.count > 0)

  if (measured.length === 0) {
    return null
  }

  const max = Math.max(...measured.map((step) => step.median_hours ?? 0))

  return (
    <ul className="space-y-3 px-3 py-1" data-testid="velocity-steps">
      {steps.map((step, index) => {
        const hours = step.median_hours

        return (
          <li key={step.step} className="space-y-1.5">
            <div className="flex items-baseline justify-between gap-3">
              <span className="t-caption min-w-0 truncate">{t(`charts.velocity.${step.step}`)}</span>
              <span className="t-body shrink-0 font-medium tabular-nums">
                {hours === null ? '—' : humanise(hours, t)}
              </span>
            </div>

            <div className="h-2 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
              {hours !== null && (
                <div
                  data-chart-mark
                  className="h-full rounded-full"
                  style={{
                    width: `${max > 0 ? Math.max(2, (hours / max) * 100) : 0}%`,
                    background: SERIES[index % SERIES.length],
                  }}
                />
              )}
            </div>

            {/*
              The sample size, because a median of two leads is an anecdote and
              a manager about to reorganise a rota deserves to know which it is.
            */}
            <p className="t-caption">
              {step.count === 0
                ? t('charts.velocity.noneYet')
                : t('charts.velocity.basedOn', { count: step.count })}
            </p>
          </li>
        )
      })}
    </ul>
  )
}

/**
 * Hours up to a day, then days.
 *
 * "73 ساعة" is a number somebody has to divide before it means anything, and
 * the whole point of this chart is to be read at a glance.
 */
function humanise(hours: number, t: ReturnType<typeof useTranslation>['t']): string {
  if (hours < 1) {
    return t('charts.velocity.minutes', { count: Math.max(1, Math.round(hours * 60)) })
  }

  if (hours < 24) {
    return t('charts.velocity.hours', { count: Math.round(hours) })
  }

  return t('charts.velocity.days', { count: Math.round(hours / 24) })
}
