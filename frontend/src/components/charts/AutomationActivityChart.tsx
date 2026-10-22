import { useTranslation } from 'react-i18next'
import { SERIES } from './palette'
import { StackedDayChart } from './StackedDayChart'

export interface AutomationDay {
  date: string
  applied: number
  skipped: number
  failed: number
}

type Key = 'applied' | 'skipped' | 'failed'

const KEYS: readonly Key[] = ['applied', 'skipped', 'failed']

const COLOR: Record<Key, string> = {
  applied: SERIES[0],
  skipped: SERIES[1],
  // Failures get the last slot and the warning colour because they are the
  // only part anybody needs to act on today.
  failed: 'var(--color-status-lost)',
}

/**
 * What the rules did, day by day.
 *
 * Skips are drawn, not hidden. A week of two hundred skipped runs and no
 * applications is a workspace whose conditions stopped matching its leads, and
 * on a chart of successes alone that is indistinguishable from a quiet week.
 */
export function AutomationActivityChart({
  days,
  height = 200,
}: {
  days: AutomationDay[]
  height?: number
}) {
  const { t } = useTranslation()

  return (
    <StackedDayChart
      days={days}
      keys={KEYS}
      color={COLOR}
      label={(key) => t(`charts.automation.${key}`)}
      aria={t('charts.automation.aria')}
      height={height}
    />
  )
}
