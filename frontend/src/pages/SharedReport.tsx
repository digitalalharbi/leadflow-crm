import { useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { cn } from '@/lib/cn'
import { useQuery } from '@tanstack/react-query'
import { Download, KeyRound, LockKeyhole, Mail, RefreshCw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, Input, Skeleton } from '@/components/ui'
import { ExecPageSection } from '@/components/reports/ExecPages'
import {
  AttributionSection, AutomationSection, CampaignsSection, FunnelSection, InsightsSection, InterestedSection, KpiCards, PlatformsSection,
  QualificationSection, RecommendationsSection, ReservationsSection, StatementSection, TeamSection, TrackingHealthSection, TrendSection, useReportCtx, VisitsSection,
} from '@/components/reports/ReportSections'
import { useBrandingTheme, usesOwnColours } from '@/hooks/useBrandingTheme'
import { Logo } from '@/components/brand'
import {
  AttentionSection, LeadQualitySection, LossSection, MarketingSection, NotesSection, ProjectsSection, RevenueSection, SalesSection, SourcesSection, TrackingHubSection, UnitsSection,
} from '@/components/reports/ClientSections'
import { downloadSharedReport, fetchSharedReport, requestShareCode, verifyShareCode, type ShareCredentials, type SharedReportData } from '@/features/reportShares'
import type { Branding } from '@/types/api'

/** Only the colour matters to the theme; the rest of a Branding is the signed-in app's. */
function themeFor(color: string | null | undefined, name: string | null | undefined): Branding {
  // No colour of its own: the platform's emerald and pine, from the brand kit — useBrandingTheme treats them as "no choice".
  return { primary_color: color ?? '#1D5E52', secondary_color: '#0C2A25', system_name: name ?? null } as Branding
}

/**
 * `/r/:token` — a live report somebody outside the workspace opens with a link.
 *
 * No sign-in and no app chrome: the workspace's name and colour, the sections the owner switched
 * on, and a quiet line saying when the numbers were taken. It refreshes itself, because the point
 * of a link over a PDF is that it is never out of date.
 */
export function SharedReportPage() {
  const { token = '' } = useParams()
  const { t } = useTranslation()
  const stored = (kind: 'password' | 'access') => {
    try { return sessionStorage.getItem(`share:${kind}:${token}`) ?? undefined } catch { return undefined }
  }
  const [credentials, setCredentials] = useState<ShareCredentials>(() => ({ password: stored('password'), access: stored('access') }))
  const [draft, setDraft] = useState('')
  const remember = (kind: 'password' | 'access', value: string) => {
    try { sessionStorage.setItem(`share:${kind}:${token}`, value) } catch { /* private window: it is simply asked again */ }
    setCredentials((c) => ({ ...c, [kind]: value }))
  }

  // The first answer is a view; the page asking again on its own is not another one.
  const loaded = useRef(false)

  const query = useQuery({
    queryKey: ['shared-report', token, credentials.password, credentials.access],
    queryFn: () => fetchSharedReport(token, credentials, loaded.current),
    // A link is never out of date: it asks again as often as the owner chose (a minute unless said otherwise).
    refetchInterval: (q) => (q.state.data?.kind === 'ok' ? Math.max(15, q.state.data.data.report.refresh_seconds ?? 60) * 1000 : false),
    retry: false,
  })

  const result = query.data
  useEffect(() => { if (result?.kind === 'ok') loaded.current = true }, [result])
  const brand = result?.kind === 'ok' ? result.data.workspace : result?.kind === 'password' || result?.kind === 'otp' ? result.workspace : null
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
              remember('password', draft)
            }}
          >
            <Input type="password" label={t('share.password')} value={draft} autoFocus data-testid="shared-password-input" onChange={(e) => setDraft(e.target.value)} />
            {credentials.password !== undefined && <p className="t-caption text-[var(--color-danger)]" data-testid="shared-password-wrong">{t('share.public.wrongPassword')}</p>}
            <Button type="submit" className="w-full" data-testid="shared-password-submit">{t('share.public.open')}</Button>
          </form>
        </CardBody></Card>
      </Shell>
    )
  }

  if (result.kind === 'otp') {
    return <OtpGate token={token} name={result.name} workspace={result.workspace.name} onPass={(pass) => remember('access', pass)} rejected={credentials.access !== undefined} />
  }

  return <ReportView data={result.data} refreshing={query.isFetching} download={(format) => downloadSharedReport(token, format, credentials)} />
}

/** For a link meant for named people: an email address, then the code that was sent to it. */
function OtpGate({ token, name, workspace, onPass, rejected }: { token: string; name: string | null; workspace: string | null; onPass: (pass: string) => void; rejected: boolean }) {
  const { t } = useTranslation()
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [sent, setSent] = useState(false)
  const [wrong, setWrong] = useState(false)
  const [busy, setBusy] = useState(false)

  const send = async () => {
    setBusy(true)
    try { await requestShareCode(token, email.trim()); setSent(true); setWrong(false) } finally { setBusy(false) }
  }
  const verify = async () => {
    setBusy(true)
    try {
      const pass = await verifyShareCode(token, email.trim(), code.trim())
      if (pass) onPass(pass)
      else setWrong(true)
    } finally { setBusy(false) }
  }

  return (
    <Shell workspace={workspace}>
      <Card><CardBody className="mx-auto max-w-sm space-y-3 py-8" data-testid="shared-otp">
        <Mail className="mx-auto size-7 text-[var(--brand)]" aria-hidden />
        <h1 className="t-section-title text-center">{name ?? t('share.public.title')}</h1>
        <p className="t-caption text-center">{t('rsec.gate.otpHint')}</p>
        {!sent ? (
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); void send() }}>
            <Input type="email" label={t('rsec.gate.email')} value={email} autoFocus autoComplete="email" data-testid="shared-otp-email" onChange={(e) => setEmail(e.target.value)} />
            <Button type="submit" className="w-full" loading={busy} disabled={!email.includes('@')} data-testid="shared-otp-send">{t('rsec.gate.sendCode')}</Button>
          </form>
        ) : (
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); void verify() }}>
            <p className="t-caption" data-testid="shared-otp-sent">{t('rsec.gate.sent')}</p>
            <Input label={t('rsec.gate.code')} value={code} inputMode="numeric" autoComplete="one-time-code" autoFocus data-testid="shared-otp-code" onChange={(e) => setCode(e.target.value)} />
            {(wrong || rejected) && <p className="t-caption text-[var(--color-danger)]" data-testid="shared-otp-wrong">{t('rsec.gate.wrong')}</p>}
            <Button type="submit" className="w-full" loading={busy} disabled={code.trim().length < 4} data-testid="shared-otp-verify">{t('rsec.gate.verify')}</Button>
            <button type="button" className="t-caption w-full text-center underline" onClick={() => { setSent(false); setCode('') }}>{t('rsec.gate.again')}</button>
          </form>
        )}
      </CardBody></Card>
    </Shell>
  )
}

function Shell({ children, workspace }: { children: React.ReactNode; workspace?: string | null }) {
  return (
    <main className="mx-auto min-h-dvh max-w-6xl space-y-3 px-4 py-6" data-testid="shared-report">
      {/* A page with no workspace to show (gone, expired, locked) is the platform's own. */}
      <div className="flex justify-center"><Logo variant="auto" layout="horizontal" height={48} /></div>
      {workspace && <p className="t-caption text-center">{workspace}</p>}
      {children}
    </main>
  )
}

/** The sections that read best side by side take half a row; everything else a whole one. */
const HALF = new Set(['statement', 'interested', 'qualification', 'visits', 'reservations', 'automation', 'platforms', 'team', 'funnel'])

export function ReportView({ data, refreshing = false, download }: { data: SharedReportData; refreshing?: boolean; download?: (format: 'csv' | 'xlsx' | 'pdf') => Promise<Blob> }) {
  const { t, i18n } = useTranslation()
  const ctx = useReportCtx(data)

  const render = (section: string) => {
    switch (section) {
      case 'kpi_volume':
      case 'kpi_cost':
      case 'kpi_revenue': return <KpiCards ctx={ctx} section={section} />
      case 'funnel': return <FunnelSection ctx={ctx} />
      case 'interested': return <InterestedSection ctx={ctx} />
      case 'qualification': return <QualificationSection ctx={ctx} />
      case 'visits': return <VisitsSection ctx={ctx} />
      case 'reservations': return <ReservationsSection ctx={ctx} />
      case 'trend': return <TrendSection ctx={ctx} />
      case 'platforms': return <PlatformsSection ctx={ctx} />
      case 'campaigns': return <CampaignsSection ctx={ctx} />
      case 'marketing': return <MarketingSection ctx={ctx} />
      case 'sources': return <SourcesSection ctx={ctx} />
      case 'sales': return <SalesSection ctx={ctx} />
      case 'lead_quality': return <LeadQualitySection ctx={ctx} />
      case 'loss': return <LossSection ctx={ctx} />
      case 'projects': return <ProjectsSection ctx={ctx} />
      case 'units': return <UnitsSection ctx={ctx} />
      case 'tracking_hub': return <TrackingHubSection ctx={ctx} />
      case 'revenue': return <RevenueSection ctx={ctx} />
      case 'attention': return <AttentionSection ctx={ctx} />
      case 'attribution': return <AttributionSection ctx={ctx} />
      case 'team': return <TeamSection ctx={ctx} />
      case 'automation': return <AutomationSection ctx={ctx} />
      case 'tracking_health': return <TrackingHealthSection ctx={ctx} />
      case 'insights': return <InsightsSection ctx={ctx} />
      case 'recommendations': return <RecommendationsSection ctx={ctx} />
      case 'statement': return <StatementSection ctx={ctx} />
      case 'exec_marketing':
      case 'exec_sales':
      case 'exec_automation':
      case 'exec_tracking': return <ExecPageSection page={section} data={data} />
      default: return null
    }
  }

  // Sections in order; the figures that sit next to each other become one block.
  const blocks = data.sections.reduce<({ kind: 'kpi'; sections: string[] } | { kind: 'section'; section: string })[]>((out, section) => {
    const last = out[out.length - 1]

    if (section.startsWith('kpi_')) {
      if (last?.kind === 'kpi') last.sections.push(section)
      else out.push({ kind: 'kpi', sections: [section] })
    } else if (render(section)) {
      out.push({ kind: 'section', section })
    }

    return out
  }, [])

  // A workspace that chose no colours of its own is shown as مهتم, logo and all; one that did keeps its own name alone.
  const platformLook = !usesOwnColours(data.workspace.color)
  const poweredBy = data.workspace.powered_by !== false
  const projects = (data.report.projects ?? []).map((p) => p.name)
  const exports = data.link?.exports
  const save = async (format: 'csv' | 'xlsx' | 'pdf') => {
    const blob = await download!(format)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${data.report.name}.${format}`
    link.click()
    URL.revokeObjectURL(url)
  }

  const when = new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(data.report.generated_at))


  // Two half-width sections share a row; one left on its own takes the whole row instead of leaving a hole beside it.
  const spans = new Map<number, 'half' | 'full'>()
  let pending: number | null = null
  blocks.forEach((block, i) => {
    if (block.kind === 'section' && HALF.has(block.section)) {
      if (pending === null) pending = i
      else { spans.set(pending, 'half'); spans.set(i, 'half'); pending = null }
    } else if (pending !== null) {
      spans.set(pending, 'full')
      pending = null
    }
  })
  if (pending !== null) spans.set(pending, 'full')

  return (
    <main className="mx-auto min-h-dvh max-w-6xl space-y-3 px-4 py-6" data-testid="shared-report">
      {platformLook && poweredBy && <Logo variant="auto" layout="horizontal" height={44} />}

      <header className="space-y-2" data-testid="shared-header">
        <div className="flex flex-wrap items-center gap-3">
          {/* Images are https addresses the owner typed; they are only ever drawn as images. */}
          {data.workspace.logo_url && <img src={data.workspace.logo_url} alt="" className="h-10 max-w-[9rem] object-contain" referrerPolicy="no-referrer" data-testid="shared-logo" />}
          {data.workspace.project_logo_url && <img src={data.workspace.project_logo_url} alt="" className="h-10 max-w-[9rem] object-contain" referrerPolicy="no-referrer" data-testid="shared-project-logo" />}
          <p className="t-caption" data-testid="shared-workspace">{data.workspace.name}</p>
        </div>
        <div className="flex flex-wrap items-end justify-between gap-2">
          <div className="min-w-0">
            {projects.length > 0 && <p className="t-caption" data-testid="shared-projects-line">{projects.join(' · ')}</p>}
            <h1 className="text-[1.5rem] font-extrabold" data-testid="shared-title">{data.report.name}</h1>
            {(data.report.client_name || data.report.owner) && (
              <p className="t-caption" data-testid="shared-client">
                {data.report.client_name && <>{t('rsec.client')}: {data.report.client_name}</>}
                {data.report.client_name && data.report.owner && ' · '}
                {data.report.owner && <>{t('rsec.owner')}: {data.report.owner}</>}
              </p>
            )}
          </div>
          <div className="text-end">
            <p className="t-caption tabular-nums" data-testid="shared-period">{t('rsec.periodLabel')}: {data.report.from} → {data.report.to}</p>
            {data.report.previous && <p className="t-caption tabular-nums" data-testid="shared-previous-period">{t('reportView.comparedWith', { from: data.report.previous.from, to: data.report.previous.to })}</p>}
            <p className="t-caption inline-flex items-center gap-1.5" data-testid="shared-updated">
              <span className="inline-block size-2 rounded-full bg-[var(--color-success)]" aria-hidden />
              {t('rsec.live')} · {t('rsec.updated')} {when}
              {data.report.cached && typeof data.report.cache_age_seconds === 'number' && <span data-testid="shared-cache-age">· {t('rsec.cachedAge', { s: data.report.cache_age_seconds })}</span>}
            </p>
          </div>
        </div>
        {download && exports && (exports.csv || exports.xlsx || exports.pdf) && (
          <div className="flex flex-wrap gap-1.5" data-testid="shared-downloads">
            {(['pdf', 'xlsx', 'csv'] as const).filter((f) => exports[f]).map((f) => (
              <Button key={f} size="sm" variant="outline" icon={<Download className="size-3.5" aria-hidden />} data-testid={`shared-download-${f}`} onClick={() => void save(f)}>{t('rsec.download')} {t(`library.exports.${f}`)}</Button>
            ))}
          </div>
        )}
      </header>

      <NotesSection data={data} />

      {/* Sections in the order the owner chose; the ones that read well together share a row. */}
      <div className="grid items-start gap-3 lg:grid-cols-2">
        {blocks.map((block, index) => block.kind === 'kpi' ? (
          // Consecutive figures flow as one grid, whatever mix of volume, cost and revenue they are.
          <div key={block.sections[0]} data-section={block.sections.join(' ')} data-testid="shared-kpis" className="grid min-w-0 grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-3 lg:col-span-2">
            {block.sections.map((section) => <KpiCards key={section} ctx={ctx} section={section as 'kpi_volume'} />)}
          </div>
        ) : (
          <div key={block.section} data-section={block.section} className={cn('min-w-0', spans.get(index) !== 'half' && 'lg:col-span-2')}>{render(block.section)}</div>
        ))}
      </div>

      {data.sections.length === 0 && (
        <Card><CardBody className="py-10 text-center t-caption">{t('share.public.empty')}</CardBody></Card>
      )}

      <p className="t-caption flex items-center justify-center gap-1.5 text-center" data-testid="shared-generated">
        <RefreshCw className={cn('size-3.5', refreshing && 'animate-spin')} aria-hidden />
        {t('share.public.generated', { when })}
        {platformLook && poweredBy && <Logo variant="auto" layout="mark" height={20} className="ms-1 opacity-80" />}
      </p>
      {data.workspace.footer && <p className="t-caption text-center" dir="auto" data-testid="shared-footer">{data.workspace.footer}</p>}
      {poweredBy && <p className="t-caption text-center" data-testid="shared-powered">{t('rsec.poweredBy')}</p>}
    </main>
  )
}
