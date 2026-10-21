import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { BadgeDollarSign, Coins, Eye, KeyRound, LockKeyhole, Sparkles, Target, TrendingUp, UserCheck, Users } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, Input, Skeleton, Table, TableWrap, Td, Th, Tr } from '@/components/ui'
import { AreaTrend, Donut, KpiCard, PipelineFunnel, RankBars } from '@/components/visual'
import { platformColor } from '@/components/charts'
import { useBrandingTheme } from '@/hooks/useBrandingTheme'
import { fetchSharedReport, type SharedReportData } from '@/features/reportShares'
import { formatCompact, formatMoneyCompact, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'
import type { Branding } from '@/types/api'

const TREND_KEYS = ['leads', 'interested', 'visits', 'reservations', 'won', 'spend', 'revenue'] as const
type TrendKey = (typeof TREND_KEYS)[number]

/** Only the colour matters to the theme; the rest of a Branding is the signed-in app's. */
function themeFor(color: string | null | undefined, name: string | null | undefined): Branding {
  return { primary_color: color ?? '#0f766e', secondary_color: '#0f172a', system_name: name ?? null } as Branding
}

/**
 * `/reports/share/:token` — a live report somebody outside the workspace opens with a link.
 *
 * No sign-in and no app chrome: the workspace's name and colour, the sections the owner switched
 * on, and a quiet line saying when the numbers were taken. It refreshes itself, because the point
 * of a link over a PDF is that it is never out of date.
 */
export function SharedReportPage() {
  const { token = '' } = useParams()
  const { t } = useTranslation()
  const [password, setPassword] = useState<string | undefined>(() => {
    try { return sessionStorage.getItem(`share:${token}`) ?? undefined } catch { return undefined }
  })
  const [draft, setDraft] = useState('')

  const query = useQuery({
    queryKey: ['shared-report', token, password],
    queryFn: () => fetchSharedReport(token, password),
    refetchInterval: (q) => (q.state.data?.kind === 'ok' ? 60_000 : false),
    retry: false,
  })

  const result = query.data
  const brand = result?.kind === 'ok' ? result.data.workspace : result?.kind === 'password' ? result.workspace : null
  useBrandingTheme(brand ? themeFor(brand.color, brand.name) : null)

  useEffect(() => {
    document.title = result?.kind === 'ok' ? result.data.report.name : t('share.public.title')
  }, [result, t])

  if (query.isLoading || !result) {
    return <Shell><Skeleton className="h-64" /></Shell>
  }

  if (result.kind === 'gone' || result.kind === 'expired') {
    return (
      <Shell>
        <Card><CardBody className="py-12 text-center" data-testid={`shared-${result.kind}`}>
          <LockKeyhole className="mx-auto mb-3 size-8 text-[var(--color-ink-subtle)]" aria-hidden />
          <h1 className="t-section-title">{t(`share.public.${result.kind}`)}</h1>
          <p className="t-caption mt-1">{t(`share.public.${result.kind}Hint`)}</p>
        </CardBody></Card>
      </Shell>
    )
  }

  if (result.kind === 'password') {
    return (
      <Shell workspace={result.workspace.name}>
        <Card><CardBody className="mx-auto max-w-sm space-y-3 py-8" data-testid="shared-password">
          <KeyRound className="mx-auto size-7 text-[var(--brand)]" aria-hidden />
          <h1 className="t-section-title text-center">{result.name ?? t('share.public.title')}</h1>
          <form
            className="space-y-3"
            onSubmit={(e) => {
              e.preventDefault()
              try { sessionStorage.setItem(`share:${token}`, draft) } catch { /* private window: the password is simply asked again */ }
              setPassword(draft)
            }}
          >
            <Input type="password" label={t('share.password')} value={draft} autoFocus data-testid="shared-password-input" onChange={(e) => setDraft(e.target.value)} />
            {password !== undefined && <p className="t-caption text-[var(--color-danger)]" data-testid="shared-password-wrong">{t('share.public.wrongPassword')}</p>}
            <Button type="submit" className="w-full" data-testid="shared-password-submit">{t('share.public.open')}</Button>
          </form>
        </CardBody></Card>
      </Shell>
    )
  }

  return <Report data={result.data} />
}

function Shell({ children, workspace }: { children: React.ReactNode; workspace?: string | null }) {
  return (
    <main className="mx-auto min-h-dvh max-w-6xl space-y-3 px-4 py-6" data-testid="shared-report">
      {workspace && <p className="t-caption text-center">{workspace}</p>}
      {children}
    </main>
  )
}

function Report({ data }: { data: SharedReportData }) {
  const { t, i18n } = useTranslation()
  const on = (section: string) => (data.sections as string[]).includes(section)
  const money = (v: number | null | undefined) => formatMoneyCompact(v ?? null, data.report.currency, i18n.language)
  const num = (v: number | null | undefined) => formatNumber(v ?? 0, i18n.language)
  const k = data.kpis ?? {}

  const cards: Array<{ key: string; icon: LucideIcon; value: string | null }> = [
    { key: 'leads', icon: Users, value: 'leads' in k ? num(k.leads) : null },
    { key: 'interested', icon: Sparkles, value: 'interested' in k ? num(k.interested) : null },
    { key: 'visits', icon: Eye, value: 'visits' in k ? num(k.visits) : null },
    { key: 'reservations', icon: KeyRound, value: 'reservations' in k ? num(k.reservations) : null },
    { key: 'won', icon: UserCheck, value: 'won' in k ? num(k.won) : null },
    { key: 'revenue', icon: Coins, value: 'revenue' in k ? money(k.revenue) : null },
    { key: 'cpl', icon: BadgeDollarSign, value: 'cpl' in k ? money(k.cpl) : null },
    { key: 'cac', icon: Target, value: 'cac' in k ? money(k.cac) : null },
    { key: 'roas', icon: TrendingUp, value: 'roas' in k ? (k.roas === null ? '—' : `${k.roas}x`) : null },
  ]

  return (
    <main className="mx-auto min-h-dvh max-w-6xl space-y-3 px-4 py-6" data-testid="shared-report">
      <header className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <p className="t-caption">{data.workspace.name}</p>
          <h1 className="text-[1.5rem] font-extrabold" data-testid="shared-title">{data.report.name}</h1>
        </div>
        <p className="t-caption tabular-nums" data-testid="shared-period">{data.report.from} → {data.report.to} · {t('share.public.live')}</p>
      </header>

      {data.kpis && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" data-testid="shared-kpis">
          {cards.filter((c) => c.value !== null).map(({ key, icon, value }) => (
            <KpiCard key={key} testId={`sk-${key}`} valueTestId={`sk-${key}-value`} label={t(`revenue.kpi.${key}`)} icon={icon} tone={key === 'revenue' || key === 'won' || key === 'roas' ? 'good' : 'neutral'} value={<span dir="ltr">{value}</span>} />
          ))}
        </div>
      )}

      <div className="grid items-start gap-3 lg:grid-cols-12">
        {data.funnel && (
          <Card className="lg:col-span-5" data-testid="shared-funnel"><CardBody>
            <h2 className="t-card-title mb-3">{t('revenue.funnel')}</h2>
            <PipelineFunnel steps={data.funnel.map((s) => ({ key: s.key, label: t(`revenue.step.${s.key}`), count: s.count, costPer: s.cost != null ? money(s.cost) : null }))} />
          </CardBody></Card>
        )}
        {data.trend && <SharedTrend data={data} className={data.funnel ? 'lg:col-span-7' : 'lg:col-span-12'} money={money} />}
      </div>

      {data.platforms && (
        <Card data-testid="shared-platforms"><CardBody>
          <h2 className="t-card-title mb-3">{t('revenue.leadsByPlatform')}</h2>
          <Donut size={120} thickness={14} legend="side" centerLabel={t('revenue.kpi.leads')}
            segments={data.platforms.map((p) => ({ key: p.key, label: t(`platforms.${p.key}`, { defaultValue: p.key }), value: p.leads, color: platformColor(p.key) }))} />
        </CardBody></Card>
      )}

      {data.team && (
        <Card data-testid="shared-team"><CardBody>
          <h2 className="t-card-title mb-3">{t('manager.team')}</h2>
          <RankBars limit={8} items={data.team.map((m) => ({ key: m.name, label: m.name, value: m.won, note: <span className="tabular-nums" dir="ltr">{m.leads} · {m.won}</span> }))} />
        </CardBody></Card>
      )}

      {data.campaigns && (
        <Card data-testid="shared-campaigns"><CardBody className="p-0">
          <TableWrap>
            <Table>
              <thead><tr>
                <Th>{t('revenue.table.campaign')}</Th><Th>{t('revenue.kpi.leads')}</Th><Th>{t('revenue.kpi.interested')}</Th><Th>{t('revenue.kpi.visits')}</Th><Th>{t('revenue.kpi.reservations')}</Th><Th>{t('revenue.kpi.won')}</Th>
                {data.campaigns.some((c) => 'spend' in c) && <Th>{t('revenue.kpi.spend')}</Th>}
                {data.campaigns.some((c) => 'revenue' in c) && <Th>{t('revenue.kpi.revenue')}</Th>}
              </tr></thead>
              <tbody>
                {data.campaigns.map((c) => (
                  <Tr key={c.name}>
                    <Td className="max-w-[14rem] truncate font-medium">{c.name}</Td>
                    <Td className="tabular-nums">{num(c.leads)}</Td><Td className="tabular-nums">{num(c.interested)}</Td><Td className="tabular-nums">{num(c.visits)}</Td><Td className="tabular-nums">{num(c.reservations)}</Td><Td className="tabular-nums">{num(c.won)}</Td>
                    {'spend' in c && <Td className="tabular-nums" dir="ltr">{money(c.spend)}</Td>}
                    {'revenue' in c && <Td className="tabular-nums" dir="ltr">{money(c.revenue)}</Td>}
                  </Tr>
                ))}
              </tbody>
            </Table>
          </TableWrap>
        </CardBody></Card>
      )}

      {!on('kpi_volume') && !data.kpis && !data.funnel && !data.trend && !data.platforms && !data.team && !data.campaigns && (
        <Card><CardBody className="py-10 text-center t-caption">{t('share.public.empty')}</CardBody></Card>
      )}

      <p className="t-caption text-center" data-testid="shared-generated">{t('share.public.generated', { when: new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(data.report.generated_at)) })}</p>
    </main>
  )
}

function SharedTrend({ data, className, money }: { data: SharedReportData; className?: string; money: (v: number | null | undefined) => string }) {
  const { t, i18n } = useTranslation()
  const available = TREND_KEYS.filter((key) => data.trend!.some((p) => key in p))
  const [key, setKey] = useState<TrendKey>(available[0] ?? 'leads')
  const isMoney = key === 'spend' || key === 'revenue'
  const day = new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { day: 'numeric', month: 'short' })
  const total = data.trend!.reduce((sum, p) => sum + ((p[key] as number | undefined) ?? 0), 0)

  return (
    <Card className={className} data-testid="shared-trend"><CardBody>
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 className="t-card-title">{t('revenue.trend')}</h2>
        <div role="group" className="flex flex-wrap gap-1">
          {available.map((k) => (
            <button key={k} type="button" aria-pressed={key === k} data-testid={`shared-trend-${k}`} onClick={() => setKey(k)}
              className={cn('pressable rounded-full px-2.5 py-0.5 text-[0.875rem] font-semibold', key === k ? 'bg-[var(--brand)] text-[var(--brand-ink)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')}>
              {t(`revenue.kpi.${k}`)}
            </button>
          ))}
        </div>
      </div>
      <p className="mb-2 text-[1.375rem] font-bold tabular-nums" dir="ltr">{isMoney ? money(total) : formatCompact(total, i18n.language)}</p>
      <AreaTrend height={150} axes valueLabel={t(`revenue.kpi.${key}`)} formatValue={(v) => (isMoney ? money(v) : formatNumber(v, i18n.language))}
        points={data.trend!.map((p) => ({ label: day.format(new Date(p.date)), value: (p[key] as number | undefined) ?? 0 }))} />
    </CardBody></Card>
  )
}
