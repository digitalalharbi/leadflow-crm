import { Link } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Bot, Crown, Handshake, Megaphone, Radar, Siren, TrendingUp, TriangleAlert, Workflow } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { platformColor } from '@/components/charts'
import { Gate } from '@/components/access/Gate'
import { AreaTrend, Delta, Donut, RankBars, RingProgress, StatusPill, type StatusKey } from '@/components/visual'
import { Card, CardBody } from '@/components/ui'
import { useCommandCenter } from '@/features/commandCenter'
import { QUALITY_CLASSES, type QualityClass } from '@/features/campaignOps'
import { useOperationsHome } from '@/features/ops'
import { formatMoneyCompact, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

const CLASS_COLOR: Record<QualityClass, string> = {
  excellent: 'var(--brand)', healthy: 'color-mix(in srgb, var(--brand) 55%, white)', watch: 'var(--color-warning)',
  at_risk: 'color-mix(in srgb, var(--color-danger) 65%, white)', critical: 'var(--color-danger)',
}
const HEALTH_STATUS: Record<string, StatusKey> = { healthy: 'healthy', attention: 'attention', risk: 'risk', idle: 'pending' }

/**
 * The operations home, second half — below today's nine numbers: what needs a person, how the
 * campaigns, the tracking and the automation are doing, who on the team is doing well for the leads
 * they were given, the revenue trend, and the best source, campaign and partner of the month.
 * Pictures first; every panel is a link to where it is fixed. A panel a person may not see is not here.
 */
export function OperationsHome() {
  const { t, i18n } = useTranslation()
  const { data } = useOperationsHome()
  const command = useCommandCenter()
  const Arrow = i18n.dir() === 'rtl' ? ArrowLeft : ArrowRight

  if (!data) return null

  const money = (v: number) => formatMoneyCompact(v, 'SAR', i18n.language)
  const num = (v: number) => formatNumber(v, i18n.language)
  const day = new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { day: 'numeric', month: 'short' })
  const queue = (command.data?.attention ?? []).slice(0, 5)
  const trend = data.revenue_trend
  const top = Math.max(1, ...(data.sales_ranking ?? []).map((r) => Math.abs(r.won_vs_expected)))

  return (
    <section className="space-y-3" data-testid="operations-home" aria-label={t('opsHome.title')}>
      <div className="grid gap-3 lg:grid-cols-12">
        {/* What needs a person, worst first. */}
        <Card className="lg:col-span-4" data-testid="home-attention">
          <CardBody>
            <h3 className="t-card-title mb-2 flex items-center gap-1.5"><Siren className="size-4 text-[var(--color-danger)]" aria-hidden />{t('opsHome.attention')}</h3>
            {queue.length === 0 ? <p className="t-caption" data-testid="home-attention-clear">{t('opsHome.attentionClear')}</p> : (
              <ul className="space-y-1.5">
                {queue.map((row) => (
                  <li key={row.key}>
                    <Link to={row.href} data-testid={`home-attention-${row.key}`} className="pressable flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-[var(--color-surface-muted)]">
                      <span className={cn('grid size-7 shrink-0 place-items-center rounded-full text-[0.875rem] font-extrabold tabular-nums text-white')} style={{ background: row.severity === 'critical' ? 'var(--color-danger)' : 'var(--color-warning)' }}>{num(row.count)}</span>
                      <span className="min-w-0 flex-1 truncate text-[0.9375rem]">{t(`commandCenter.attention.${row.key}`, { minutes: row.minutes })}</span>
                      <Arrow className="size-4 shrink-0 text-[var(--color-ink-subtle)]" aria-hidden />
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>

        {data.campaign_health && (
          <Card className="lg:col-span-4" data-testid="home-campaigns">
            <CardBody>
              <h3 className="t-card-title mb-2 flex items-center gap-1.5"><Megaphone className="size-4" aria-hidden />{t('opsHome.campaigns')}</h3>
              {data.campaign_health.total === 0 ? <p className="t-caption">{t('opsHome.noCampaigns')}</p> : (
                <>
                  <Donut size={96} thickness={12} legend="side" centerLabel={t('campaignOps.campaigns')} testId="home-campaign-classes"
                    segments={QUALITY_CLASSES.map((c) => ({ key: c, label: t(`campaignOps.class.${c}`), value: data.campaign_health!.classes[c] ?? 0, color: CLASS_COLOR[c] }))} />
                  {data.campaign_health.worst.length > 0 && (
                    <ul className="mt-2 space-y-1" data-testid="home-campaign-worst">
                      {data.campaign_health.worst.map((c) => (
                        <li key={c.id}>
                          <Link to={`/campaigns/${c.id}`} className="pressable flex items-center gap-2 text-[0.875rem] hover:underline">
                            <span aria-hidden className="size-2 shrink-0 rounded-full" style={{ background: platformColor(c.platform) }} />
                            <span className="min-w-0 flex-1 truncate">{c.name}</span>
                            <span className="font-bold tabular-nums" dir="ltr">{c.score}</span>
                          </Link>
                        </li>
                      ))}
                    </ul>
                  )}
                </>
              )}
            </CardBody>
          </Card>
        )}

        {(data.tracking_health || data.automation_health) && (
          <div className="grid gap-3 lg:col-span-4">
            {data.tracking_health && (
              <Card data-testid="home-tracking">
                <CardBody>
                  <h3 className="t-card-title mb-2 flex items-center gap-1.5"><Radar className="size-4" aria-hidden />{t('opsHome.tracking')}</h3>
                  <ul className="flex flex-wrap gap-1.5">
                    {data.tracking_health.platforms.length === 0 && <li className="t-caption">{t('opsHome.noTracking')}</li>}
                    {data.tracking_health.platforms.map((p) => (
                      <li key={p.platform} className="inline-flex items-center gap-1.5 rounded-full bg-[var(--color-surface-muted)] py-0.5 pe-1 ps-2 text-[0.8125rem]">
                        <span aria-hidden className="size-2 rounded-full" style={{ background: platformColor(p.platform) }} />
                        {t(`platforms.${p.platform}`, { defaultValue: p.platform })}
                        <StatusPill status={HEALTH_STATUS[p.status] ?? 'pending'} />
                      </li>
                    ))}
                  </ul>
                </CardBody>
              </Card>
            )}
            {data.automation_health && (
              <Card data-testid="home-automation">
                <CardBody className="flex items-center gap-3">
                  <RingProgress size={64} thickness={8} value={data.automation_health.handled_pct ?? 0} tone="good" label={t('opsHome.handled')}>
                    <span className="text-[0.8125rem] font-bold tabular-nums" dir="ltr">{data.automation_health.handled_pct === null ? '—' : `${Math.round(data.automation_health.handled_pct)}%`}</span>
                  </RingProgress>
                  <div className="min-w-0 flex-1">
                    <h3 className="t-card-title flex items-center gap-1.5"><Workflow className="size-4" aria-hidden />{t('opsHome.automation')}</h3>
                    <p className="t-caption">{t('opsHome.automationLine', { failed: data.automation_health.failed, human: data.automation_health.needs_human })}</p>
                  </div>
                  <Link to="/automation" className="text-[var(--brand)]" aria-label={t('opsHome.automation')}><Arrow className="size-4" aria-hidden /></Link>
                </CardBody>
              </Card>
            )}
          </div>
        )}
      </div>

      <div className="grid gap-3 lg:grid-cols-12">
        {trend && (
          <Gate element="dashboard.chart.revenue_trend">
            <Card className="lg:col-span-5" data-testid="home-revenue">
              <CardBody>
                <div className="mb-1 flex items-start justify-between gap-2">
                  <h3 className="t-card-title flex items-center gap-1.5"><TrendingUp className="size-4" aria-hidden />{t('opsHome.revenue')}</h3>
                  <Delta value={trend.total} previous={trend.previous_total} />
                </div>
                <p className="mb-2 text-[1.375rem] font-bold tabular-nums" dir="ltr" data-testid="home-revenue-total">{money(trend.total)}</p>
                <AreaTrend height={110} axes valueLabel={t('opsHome.revenue')} formatValue={(v) => money(v)} points={trend.days.map((d) => ({ label: day.format(new Date(d.date)), value: d.revenue }))} />
              </CardBody>
            </Card>
          </Gate>
        )}

        {data.sales_ranking && data.sales_ranking.length > 0 && (
          <Card className="lg:col-span-4" data-testid="home-ranking">
            <CardBody>
              <h3 className="t-card-title mb-2 flex items-center gap-1.5"><Crown className="size-4" aria-hidden />{t('opsHome.ranking')}</h3>
              <RankBars
                limit={5}
                items={data.sales_ranking.map((r) => ({
                  key: r.id ?? r.name, label: r.name, value: Math.max(0, r.won_vs_expected) + 0.0001,
                  note: <span className="tabular-nums" dir="ltr">{r.won_vs_expected >= 0 ? '+' : ''}{r.won_vs_expected} · {r.leads}{r.small_sample ? ` · ${t('salesIntel.small')}` : ''}</span>,
                }))}
              />
              <p className="t-caption mt-2">{t('opsHome.rankingHint')}</p>
              <span className="sr-only">{top}</span>
            </CardBody>
          </Card>
        )}

        <div className="grid gap-3 lg:col-span-3" data-testid="home-tops">
          {data.tops.source && (
            <Card data-testid="home-top-source"><CardBody className="flex items-center gap-2.5">
              <span className="grid size-9 shrink-0 place-items-center rounded-full text-white" style={{ background: platformColor(data.tops.source.platform) }} aria-hidden><Bot className="size-4" /></span>
              <div className="min-w-0"><p className="t-caption">{t('opsHome.topSource')}</p><p className="truncate font-extrabold">{t(`platforms.${data.tops.source.platform}`, { defaultValue: data.tops.source.platform })}</p><p className="t-caption tabular-nums" dir="ltr">{num(data.tops.source.leads)} · {data.tops.source.share ?? 0}%</p></div>
            </CardBody></Card>
          )}
          {data.tops.campaign && (
            <Card data-testid="home-top-campaign"><CardBody className="flex items-center gap-2.5">
              <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden><Megaphone className="size-4" /></span>
              <Link to={`/campaigns/${data.tops.campaign.id}`} className="min-w-0 hover:underline"><p className="t-caption">{t('opsHome.topCampaign')}</p><p className="truncate font-extrabold">{data.tops.campaign.name}</p><p className="t-caption tabular-nums" dir="ltr">{num(data.tops.campaign.reservations)} {t('revenue.kpi.reservations')}</p></Link>
            </CardBody></Card>
          )}
          {data.tops.partner && (
            <Card data-testid="home-top-partner"><CardBody className="flex items-center gap-2.5">
              <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden><Handshake className="size-4" /></span>
              <Link to="/partners" className="min-w-0 hover:underline"><p className="t-caption">{t('opsHome.topPartner')}</p><p className="truncate font-extrabold">{data.tops.partner.name}</p><p className="t-caption tabular-nums" dir="ltr">{num(data.tops.partner.won)} {t('revenue.kpi.won')} · {num(data.tops.partner.leads)}</p></Link>
            </CardBody></Card>
          )}
        </div>
      </div>
      <span className="sr-only" aria-hidden><TriangleAlert className="size-1" /></span>
    </section>
  )
}
