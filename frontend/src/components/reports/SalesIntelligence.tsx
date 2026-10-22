import { useState } from 'react'
import { Clock, Info, PhoneCall, Trophy } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Card, CardBody, Skeleton } from '@/components/ui'
import { HeatGrid, KpiCard, RingProgress } from '@/components/visual'
import { useSalesPerformance, type RepRow } from '@/features/salesPerformance'
import { formatMoneyCompact, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

const RATES = ['contact_rate', 'interested_rate', 'visit_rate', 'reservation_rate', 'close_rate', 'sla_compliance', 'follow_up_completion'] as const

/**
 * Each rep, and how they are doing for the leads they were *given*.
 *
 * A bare league table rewards whoever got the best leads, so the ranking here is by sales above what
 * the team's own close rate would have made of the same number of leads — and a rep on a handful of
 * leads is marked as such and ranked after those with enough to judge. Every rate is on one grid so
 * a rep's weak step is the pale cell in their row.
 */
export function SalesIntelligence({ dateFrom, projectId }: { dateFrom?: string; projectId?: string }) {
  const { t, i18n } = useTranslation()
  const days = dateFrom ? Math.min(365, Math.max(1, Math.ceil((Date.now() - new Date(dateFrom).getTime()) / 86_400_000) + 1)) : 30
  const { data, isLoading } = useSalesPerformance(days, projectId ?? '')
  const [open, setOpen] = useState<string | null>(null)

  if (isLoading) return <Skeleton className="h-64" />
  if (!data || data.reps.length === 0) return null

  const money = (v: number) => formatMoneyCompact(v, 'SAR', i18n.language)
  const num = (v: number) => formatNumber(v, i18n.language)
  const pct = (v: number | null) => (v === null ? '—' : `${v}%`)
  const top = Math.max(1, ...data.reps.map((r) => Math.abs(r.won_vs_expected)))
  const hasMoney = data.reps.some((r) => r.revenue !== undefined)

  return (
    <section className="space-y-3" data-testid="sales-intelligence">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard testId="si-close" valueTestId="si-close-value" label={t('salesIntel.team.close')} icon={Trophy} tone="good" value={pct(data.team.close_rate)} />
        <KpiCard testId="si-contact" valueTestId="si-contact-value" label={t('salesIntel.team.contact')} icon={PhoneCall} value={pct(data.team.contact_rate)} />
        <KpiCard testId="si-sla" valueTestId="si-sla-value" label={t('salesIntel.team.sla', { minutes: data.team.sla_target_minutes })} icon={Clock} tone={(data.team.sla_compliance ?? 0) >= 80 ? 'good' : 'warn'} value={pct(data.team.sla_compliance)} />
        <KpiCard testId="si-leads" valueTestId="si-leads-value" label={t('salesIntel.team.leads')} icon={Info} tone="neutral" value={num(data.team.leads)} />
      </div>

      <div className="grid gap-3 lg:grid-cols-12">
        <Card className="lg:col-span-5" data-testid="si-ranking">
          <CardBody>
            <h3 className="t-card-title mb-1">{t('salesIntel.rankTitle')}</h3>
            <p className="t-caption mb-3">{t('salesIntel.rankHint')}</p>
            <ol className="space-y-2.5">
              {data.reps.map((rep) => <RankRow key={rep.id ?? rep.name} rep={rep} top={top} open={open === (rep.id ?? rep.name)} onToggle={() => setOpen(open === (rep.id ?? rep.name) ? null : rep.id ?? rep.name)} hasMoney={hasMoney} money={money} />)}
            </ol>
          </CardBody>
        </Card>

        <Card className="lg:col-span-7" data-testid="si-grid">
          <CardBody>
            <h3 className="t-card-title mb-3">{t('salesIntel.gridTitle')}</h3>
            <HeatGrid
              testId="si-heat"
              columns={RATES.map((r) => t(`salesIntel.rate.${r}`))}
              rows={data.reps.map((rep) => ({
                key: rep.id ?? rep.name,
                label: rep.name,
                note: rep.small_sample ? t('salesIntel.small') : undefined,
                cells: RATES.map((r) => (r === 'follow_up_completion' ? rep.follow_up_completion : rep[r])),
              }))}
            />
            <p className="t-caption mt-2">{t('salesIntel.gridHint')}</p>
          </CardBody>
        </Card>
      </div>
    </section>
  )
}

function RankRow({ rep, top, open, onToggle, hasMoney, money }: { rep: RepRow; top: number; open: boolean; onToggle: () => void; hasMoney: boolean; money: (v: number) => string }) {
  const { t } = useTranslation()
  const diff = rep.won_vs_expected
  const width = Math.max(3, (Math.abs(diff) / top) * 100)
  const good = diff >= 0

  return (
    <li data-testid={`si-rank-${rep.rank}`} data-small={rep.small_sample}>
      <button type="button" onClick={onToggle} aria-expanded={open} className="pressable block w-full text-start">
        <div className="flex items-center gap-2">
          <span className="grid size-7 shrink-0 place-items-center rounded-full bg-[var(--color-chrome-fill)] text-[0.8125rem] font-semibold tabular-nums">{rep.rank}</span>
          <span className="min-w-0 flex-1 truncate text-[0.9375rem] font-semibold">{rep.name}</span>
          {rep.small_sample && <Badge tone="neutral">{t('salesIntel.small')}</Badge>}
          <span className={cn('text-[0.9375rem] font-bold tabular-nums', good ? 'text-[var(--brand)]' : 'text-[var(--color-danger)]')} dir="ltr" data-testid={`si-diff-${rep.rank}`}>{good ? '+' : ''}{diff}</span>
        </div>
        <div className="mt-1 flex items-center gap-2 ps-9">
          <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-[var(--color-chrome-fill)]" aria-hidden>
            <span className="block h-full rounded-full" style={{ width: `${width}%`, background: good ? 'var(--brand)' : 'var(--color-danger)' }} />
          </span>
          <span className="t-caption tabular-nums" dir="ltr">{rep.leads} · {rep.volume_share ?? 0}%</span>
        </div>
      </button>
      {open && (
        <div className="mt-2 grid grid-cols-3 gap-1.5 ps-9 text-center" data-testid={`si-detail-${rep.rank}`}>
          <Tile label={t('salesIntel.response')} value={rep.response_minutes === null ? '—' : t('salesIntel.minutes', { count: rep.response_minutes })} />
          <Tile label={t('salesIntel.won')} value={String(rep.won)} />
          {hasMoney && rep.revenue !== undefined ? <Tile label={t('salesIntel.revenue')} value={money(rep.revenue)} /> : <Tile label={t('salesIntel.visits')} value={String(rep.visits)} />}
          <div className="col-span-3 flex justify-center">
            <RingProgress size={44} thickness={5} value={rep.sla_compliance ?? 0} tone={(rep.sla_compliance ?? 0) >= 80 ? 'good' : 'warn'} label={t('salesIntel.rate.sla_compliance')}>
              <span className="text-[0.6875rem] font-bold tabular-nums" dir="ltr">{rep.sla_compliance === null ? '—' : `${Math.round(rep.sla_compliance)}`}</span>
            </RingProgress>
          </div>
        </div>
      )}
    </li>
  )
}

function Tile({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-[var(--color-surface-muted)] px-1 py-1">
      <p className="text-[0.9375rem] font-extrabold tabular-nums" dir="ltr">{value}</p>
      <p className="text-[0.75rem] text-[var(--color-ink-subtle)]">{label}</p>
    </div>
  )
}
