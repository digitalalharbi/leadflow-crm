import { Link } from 'react-router-dom'
import { AlertTriangle, FileText, Gauge, Rocket, ShieldAlert, Sparkles, TrendingDown, Waves, Activity } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody } from '@/components/ui'
import { RankBars } from '@/components/visual'
import { TONE_COLOR, TONE_TINT } from '@/components/visual/tones'
import { ProposalCard } from '@/components/campaigns/ProposalCard'
import type { Block } from '@/features/agentChat'
import { formatMoneyCompact, formatNumber } from '@/lib/format'

const FINDING_ICON: Record<string, LucideIcon> = {
  no_results: ShieldAlert, over_target: Gauge, cpl_over_target: Gauge, outperforming: Rocket,
  quality_drop: TrendingDown, low_qualified_rate: TrendingDown, many_invalid: TrendingDown, spend_spike: Activity, over_ceiling: AlertTriangle, fatigue: Waves,
  weak_creative: AlertTriangle, winner_creative: Rocket,
}

/**
 * What a tool returned, drawn — not written out. The agent's text says what it
 * means; the figures, tables, rankings and proposals beneath it are the tools'
 * own results, so the numbers on screen are the numbers the agent read.
 */
export function MessageBlocks({ blocks, currency = 'SAR' }: { blocks: Block[]; currency?: string }) {
  const { t, i18n } = useTranslation()

  const format = (value: unknown, kind: string) => {
    if (value === null || value === undefined) return '—'
    const n = Number(value)
    if (kind === 'money') return formatMoneyCompact(n, currency, i18n.language)
    if (kind === 'percent') return `${n}%`
    if (kind === 'ratio') return `${n}x`
    return formatNumber(n, i18n.language)
  }

  const cell = (key: string, value: unknown) => {
    if (['spend', 'cpl', 'cpql', 'cpa', 'revenue'].includes(key)) return format(value, 'money')
    if (['qualified_rate', 'ctr', 'fatigue'].includes(key)) return value === null || value === undefined ? '—' : `${value}%`
    if (key === 'roas') return format(value, 'ratio')
    if (key === 'platform') return t(`platforms.${String(value)}`, { defaultValue: String(value) })
    return typeof value === 'number' ? formatNumber(value, i18n.language) : String(value ?? '—')
  }

  return (
    <div className="space-y-2" data-testid="message-blocks">
      {blocks.map((block, i) => {
        switch (block.type) {
          case 'kpis':
            return (
              <div key={i} className="grid grid-cols-3 gap-2 sm:grid-cols-6" data-testid="block-kpis">
                {block.items.map((k) => (
                  <div key={k.key} className="rounded-[var(--radius-control)] bg-[var(--color-surface)] px-3 py-2 shadow-[var(--shadow-card)]">
                    <p className="text-[1.0625rem] font-bold tabular-nums" dir="ltr">{format(k.value, k.format)}</p>
                    <p className="text-[0.8125rem] text-[var(--color-ink-muted)]">{t(`campaigns.figure.${k.key}`, { defaultValue: k.key })}</p>
                  </div>
                ))}
              </div>
            )
          case 'table':
            return (
              <Card key={i}>
                <div className="overflow-x-auto" data-testid="block-table">
                  <table className="w-full text-[0.875rem]">
                    <thead>
                      <tr className="text-[var(--color-ink-subtle)]">
                        {block.columns.map((c) => <th key={c} className="px-3 py-2 text-start font-medium">{t(`agentChat.col.${c}`, { defaultValue: c })}</th>)}
                      </tr>
                    </thead>
                    <tbody>
                      {block.rows.map((row, r) => (
                        <tr key={r} className="border-t border-[var(--color-line)]">
                          {block.columns.map((c) => <td key={c} className="px-3 py-1.5 tabular-nums" dir={c === 'name' ? 'auto' : 'ltr'}>{cell(c, row[c])}</td>)}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Card>
            )
          case 'ranking':
            return (
              <Card key={i}>
                <CardBody>
                  <RankBars
                    testId="block-ranking"
                    limit={8}
                    items={block.items.map((it, n) => ({
                      key: `${it.label}-${n}`,
                      label: t(`platforms.${it.label}`, { defaultValue: it.label }),
                      value: it.value,
                      note: it.note ?? undefined,
                    }))}
                  />
                </CardBody>
              </Card>
            )
          case 'findings':
            return block.items.length === 0 ? (
              <p key={i} className="text-[0.9375rem] text-[var(--color-ink-subtle)]">{t('agentsPanel.allClear')}</p>
            ) : (
              <div key={i} className="grid gap-2 sm:grid-cols-2" data-testid="block-findings">
                {block.items.slice(0, 8).map((f, n) => {
                  const Icon = FINDING_ICON[f.key] ?? Sparkles
                  const tone = f.severity === 'bad' ? 'bad' : f.severity === 'good' ? 'good' : 'warn'
                  return (
                    <Link key={n} to={`/campaigns/${f.campaign.id}`} className="flex items-center gap-2.5 rounded-[var(--radius-control)] bg-[var(--color-surface)] p-2.5 shadow-[var(--shadow-card)]">
                      <span className="grid size-8 shrink-0 place-items-center rounded-full" style={{ background: TONE_TINT[tone], color: TONE_COLOR[tone] }} aria-hidden>
                        <Icon className="size-4" />
                      </span>
                      <span className="min-w-0">
                        <span className="block truncate text-[0.875rem] font-semibold">{t(`agentsPanel.finding.${f.key}`, { defaultValue: f.key })}</span>
                        <span className="block truncate text-[0.8125rem] text-[var(--color-ink-muted)]" dir="auto">{f.campaign.name}</span>
                      </span>
                    </Link>
                  )
                })}
              </div>
            )
          case 'proposal':
            return <ProposalCard key={i} proposal={block.proposal} currency={currency} />
          case 'drafts':
            return (
              <div key={i} className="space-y-2" data-testid="block-drafts">
                {block.items.map((d) => (
                  <Card key={d.id}>
                    <CardBody className="space-y-2">
                      <p className="flex items-center gap-1.5 text-[0.875rem] font-semibold">
                        <Sparkles className="size-3.5 text-[var(--brand)]" aria-hidden />
                        {t(`creatives.kind.${d.kind}`)} · <span dir="auto">{d.ad?.name}</span>
                      </p>
                      <ol className="list-decimal space-y-1 ps-5 text-[0.9375rem]" dir="auto">
                        {d.items.map((line, n) => <li key={n}>{line}</li>)}
                      </ol>
                      {d.flags.length > 0 && (
                        <p className="flex items-center gap-1 text-[0.8125rem] text-[var(--color-danger)]"><ShieldAlert className="size-3" aria-hidden />{t('creatives.flagged', { words: d.flags.join('، ') })}</p>
                      )}
                    </CardBody>
                  </Card>
                ))}
              </div>
            )
          case 'report':
            return (
              <Link key={i} to="/reports" className="inline-flex items-center gap-1.5 rounded-full bg-[var(--brand-soft)] px-3 py-1.5 text-[0.875rem] font-medium text-[var(--brand)]" data-testid="block-report">
                <FileText className="size-3.5" aria-hidden />
                {t('agentChat.openReport')}
              </Link>
            )
          default:
            return null
        }
      })}
    </div>
  )
}
