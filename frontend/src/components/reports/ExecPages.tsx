import { BadgeDollarSign, Bot, CalendarCheck, Coins, Eye, Gauge, KeyRound, Link2, Percent, Radar, Send, Sparkles, Target, Timer, TrendingUp, TriangleAlert, UserCheck, Users, Workflow } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody } from '@/components/ui'
import { ComparisonCard, KpiCard, RingProgress } from '@/components/visual'
import type { ExecPage, SharedReportData } from '@/features/reportShares'
import { formatMoneyCompact, formatNumber } from '@/lib/format'

type Kind = 'money' | 'number' | 'pct' | 'minutes' | 'ratio'

interface Item { key: string; kind: Kind; icon: LucideIcon; /** Lower is better: a cost, a failure, a wait. */ lower?: boolean }

/** The figures of each page, in the order a manager reads them. */
const PAGES = {
  exec_marketing: {
    icon: Coins,
    items: [
      { key: 'spend', kind: 'money', icon: Coins, lower: true }, { key: 'leads', kind: 'number', icon: Users }, { key: 'cpl', kind: 'money', icon: BadgeDollarSign, lower: true },
      { key: 'qualified', kind: 'number', icon: Target }, { key: 'cpql', kind: 'money', icon: BadgeDollarSign, lower: true }, { key: 'interested', kind: 'number', icon: Sparkles },
      { key: 'visits', kind: 'number', icon: Eye }, { key: 'reservations', kind: 'number', icon: KeyRound }, { key: 'cac', kind: 'money', icon: Target, lower: true }, { key: 'roas', kind: 'ratio', icon: TrendingUp },
    ],
  },
  exec_sales: {
    icon: UserCheck,
    items: [
      { key: 'assigned', kind: 'number', icon: Users }, { key: 'contacted', kind: 'number', icon: Send }, { key: 'response_minutes', kind: 'minutes', icon: Timer, lower: true },
      { key: 'follow_up_completion', kind: 'pct', icon: CalendarCheck }, { key: 'visits', kind: 'number', icon: Eye }, { key: 'reservations', kind: 'number', icon: KeyRound },
      { key: 'won', kind: 'number', icon: UserCheck }, { key: 'revenue', kind: 'money', icon: Coins }, { key: 'close_rate', kind: 'pct', icon: Percent }, { key: 'sla', kind: 'pct', icon: Gauge },
    ],
  },
  exec_automation: {
    icon: Workflow,
    items: [
      { key: 'coverage', kind: 'pct', icon: Bot }, { key: 'success', kind: 'pct', icon: Workflow }, { key: 'failures', kind: 'number', icon: TriangleAlert, lower: true }, { key: 'needs_human', kind: 'number', icon: Users, lower: true },
    ],
  },
  exec_tracking: {
    icon: Radar,
    items: [
      { key: 'attribution', kind: 'pct', icon: Link2 }, { key: 'utm_health', kind: 'pct', icon: Radar }, { key: 'unattributed', kind: 'pct', icon: TriangleAlert, lower: true },
      { key: 'offline_sent', kind: 'number', icon: Send }, { key: 'offline_failed', kind: 'number', icon: TriangleAlert, lower: true }, { key: 'offline_success', kind: 'pct', icon: Percent },
    ],
  },
} as const satisfies Record<string, { icon: LucideIcon; items: readonly Item[] }>

export type ExecPageKey = keyof typeof PAGES

/** The headline of a page: a ring for the share that matters most, where there is one. */
const RING: Partial<Record<ExecPageKey, string>> = { exec_sales: 'sla', exec_automation: 'coverage', exec_tracking: 'attribution' }

/**
 * One page of the management report — marketing, sales, automation or tracking — as a group of
 * figures that are pictures first: each a card, with the period before beside it (an arrow and a
 * percentage) when the report compares. A figure nobody could compute is a dash, never a zero.
 */
export function ExecPageSection({ page, data }: { page: ExecPageKey; data: SharedReportData }) {
  const { t, i18n } = useTranslation()
  const content = data[page] as ExecPage<string> | undefined

  if (!content) return null

  const spec = PAGES[page]
  const money = (v: number | null | undefined) => formatMoneyCompact(v ?? null, data.report.currency, i18n.language)
  const format = (kind: Kind, v: number | null | undefined) => {
    if (v === null || v === undefined) return '—'

    switch (kind) {
      case 'money': return money(v)
      case 'pct': return `${formatNumber(v, i18n.language)}%`
      case 'ratio': return `${formatNumber(v, i18n.language)}x`
      case 'minutes': return t('reportView.exec.minutes', { count: v })
      default: return formatNumber(v, i18n.language)
    }
  }

  const ringKey = RING[page]
  const ringValue = ringKey ? content.figures[ringKey] : null

  return (
    <Card data-testid={`shared-${page}`}><CardBody>
      <div className="mb-3 flex flex-wrap items-center gap-3">
        <span className="grid size-9 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden><spec.icon className="size-5" /></span>
        <h2 className="t-card-title flex-1">{t(`reportView.exec.${page}`)}</h2>
        {ringKey && (
          <RingProgress size={64} thickness={8} value={ringValue ?? 0} tone={(ringValue ?? 0) >= 80 ? 'good' : (ringValue ?? 0) >= 60 ? 'warn' : 'bad'} label={t(`reportView.exec.label.${ringKey}`)} testId={`${page}-ring`}>
            <span className="text-[0.8125rem] font-bold tabular-nums" dir="ltr">{ringValue === null || ringValue === undefined ? '—' : `${ringValue}%`}</span>
          </RingProgress>
        )}
      </div>

      <div className="grid grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-3">
        {spec.items.map((item: Item) => {
          const value = content.figures[item.key]
          const before = content.previous?.[item.key]
          const label = t(`reportView.exec.label.${item.key}`)

          return content.previous && typeof value === 'number' && before !== undefined ? (
            <ComparisonCard
              key={item.key}
              testId={`${page}-${item.key}`}
              label={label}
              value={format(item.kind, value)}
              current={value}
              before={before}
              previous={format(item.kind, before)}
              invert={item.lower}
              nowLabel={t('reportView.now')}
              beforeLabel={t('reportView.before')}
            />
          ) : (
            <KpiCard key={item.key} testId={`${page}-${item.key}`} valueTestId={`${page}-${item.key}-value`} label={label} icon={item.icon} tone={item.lower && typeof value === 'number' && value > 0 ? 'warn' : 'neutral'} value={<span dir="ltr">{format(item.kind, value)}</span>} />
          )
        })}
      </div>
    </CardBody></Card>
  )
}
