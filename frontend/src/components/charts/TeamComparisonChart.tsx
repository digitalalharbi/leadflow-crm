import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { SERIES } from './palette'
import { formatNumber } from '@/lib/format'
import { humaniseDuration } from './duration'
import { cn } from '@/lib/cn'

export interface TeamRow {
  user_id: string
  name: string
  leads: number
  qualified: number
  won: number
  win_rate: number
  avg_first_contact_seconds: number | null
}

/**
 * Which measure the manager is looking at.
 *
 * `lowerIsBetter` is the reason this is a table rather than a list of keys. A
 * ranked bar chart silently means "longer is better" — true of every measure
 * here except response time, where the longest bar is the worst rep on the
 * team. A chart that reverses its meaning depending on a dropdown, without
 * saying so, is worse than no chart: it is confidently wrong.
 */
const MEASURES = {
  leads: { lowerIsBetter: false, format: 'number' },
  qualified: { lowerIsBetter: false, format: 'number' },
  won: { lowerIsBetter: false, format: 'number' },
  win_rate: { lowerIsBetter: false, format: 'percent' },
  avg_first_contact_seconds: { lowerIsBetter: true, format: 'duration' },
} as const

type Measure = keyof typeof MEASURES

/**
 * How the team compares, on one measure at a time.
 *
 * The team tab was seven numeric columns across five people — the one screen
 * where a manager compares human beings, and the hardest shape to compare in.
 * Reading "is نورة ahead of سعد" out of a table means holding two numbers in
 * your head while your eye travels; out of a bar chart it means looking.
 *
 * One measure at a time rather than seven small multiples, because the
 * follow-up question is always "on what?" and answering it in a dropdown keeps
 * the comparison honest. Seven charts at once invites reading a shape as a
 * verdict on a person.
 */
export function TeamComparisonChart({ rows }: { rows: TeamRow[] }) {
  const { t, i18n } = useTranslation()
  const [measure, setMeasure] = useState<Measure>('won')

  const spec = MEASURES[measure]

  const ranked = rows
    .filter((row) => value(row, measure) !== null)
    .toSorted((a, b) => {
      const av = value(a, measure) ?? 0
      const bv = value(b, measure) ?? 0

      // Best first, always — whichever direction "best" happens to point.
      return spec.lowerIsBetter ? av - bv : bv - av
    })

  if (ranked.length === 0) {
    return null
  }

  const max = Math.max(...ranked.map((row) => value(row, measure) ?? 0), 1)

  return (
    <div className="space-y-3" data-testid="team-comparison">
      <div className="flex flex-wrap items-center gap-2">
        {(Object.keys(MEASURES) as Measure[]).map((key) => (
          <button
            key={key}
            type="button"
            onClick={() => setMeasure(key)}
            aria-pressed={key === measure}
            data-testid={`team-measure-${key}`}
            className={cn(
              'pressable rounded-[var(--radius-control)] px-2.5 py-1 text-[0.8125rem]',
              key === measure
                ? 'bg-[var(--brand)] text-[var(--brand-contrast)]'
                : 'bg-[var(--color-surface-sunken)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]',
            )}
          >
            {t(`charts.team.${key}`)}
          </button>
        ))}
      </div>

      {/*
        Said in words, every time. The ranking reverses for response time, and
        a reader who missed that reads the top of the list as the best rep when
        it is the fastest — which is the same thing here, but only because the
        sort was told which way to point.
      */}
      <p className="t-caption">
        {t(spec.lowerIsBetter ? 'charts.team.lowerIsBetter' : 'charts.team.higherIsBetter')}
      </p>

      <ul className="space-y-2.5">
        {ranked.map((row, index) => {
          const raw = value(row, measure) ?? 0

          return (
            <li key={row.user_id} className="space-y-1">
              <div className="flex items-baseline justify-between gap-3">
                <span className="t-caption min-w-0 truncate">{row.name}</span>
                <span className="t-body shrink-0 font-medium tabular-nums">
                  {display(raw, spec.format, t, i18n.language)}
                </span>
              </div>
              <div className="h-2 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
                <div
                  data-chart-mark
                  className="h-full rounded-full"
                  style={{
                    width: `${Math.max(2, (raw / max) * 100)}%`,
                    // One colour per chart: the bars are one series compared
                    // across people, not five entities. Colouring each rep
                    // differently would imply a meaning the chart does not have.
                    background: SERIES[0],
                    opacity: index === 0 ? 1 : 0.75,
                  }}
                />
              </div>
            </li>
          )
        })}
      </ul>
    </div>
  )
}

function value(row: TeamRow, measure: Measure): number | null {
  const raw = row[measure]

  return typeof raw === 'number' ? raw : null
}

function display(
  raw: number,
  format: (typeof MEASURES)[Measure]['format'],
  t: ReturnType<typeof useTranslation>['t'],
  language: string,
): string {
  if (format === 'percent') {
    return `${raw}%`
  }

  if (format === 'duration') {
    // Was its own minutes-or-hours ladder that never reached days, so a rep
    // who took three days to call showed as "72 ساعة" here and "3 أيام" on the
    // velocity chart. Same lead, same number, two answers.
    return humaniseDuration(raw, t)
  }

  return formatNumber(raw, language)
}
