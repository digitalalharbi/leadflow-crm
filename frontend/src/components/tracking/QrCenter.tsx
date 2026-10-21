import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CalendarCheck, Download, KeyRound, QrCode, Printer, ScanLine, Sparkles, TrendingUp, Users } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, EmptyState, Modal, Skeleton } from '@/components/ui'
import { KpiCard } from '@/components/visual'
import { downloadLinkQr, fetchLinkQr, PLACEMENTS, PRINT_SIZES, printableQr, type Placement, type PrintSize, type TrackingLink } from '@/features/tracking'
import { formatMoneyCompact, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * Every printed code in one place: filter by what it is printed on, see what each one has
 * brought — scans, leads, interested, visits, reservations, revenue — and take it away as an SVG,
 * a PNG, or a page ready for the printer.
 */
export function QrCenter({ links, onOpen }: { links: TrackingLink[]; onOpen: (id: string) => void }) {
  const { t, i18n } = useTranslation()
  const user = useAuth((s) => s.user)
  const [placement, setPlacement] = useState<Placement | 'all'>('all')
  const [printing, setPrinting] = useState<TrackingLink | null>(null)

  const codes = links.filter((l) => l.kind === 'qr')
  const shown = placement === 'all' ? codes : codes.filter((l) => (l.placement ?? 'other') === placement)
  const count = (p: Placement) => codes.filter((l) => (l.placement ?? 'other') === p).length
  const sum = (key: 'clicks' | 'leads' | 'interested' | 'visits_done' | 'reservations' | 'revenue') => shown.reduce((n, l) => n + (l.stats[key] ?? 0), 0)

  if (codes.length === 0) return <EmptyState title={t('qrCenter.emptyTitle')} body={t('qrCenter.emptyBody')} />

  const kpis: [string, LucideIcon, number][] = [
    ['scans', ScanLine, sum('clicks')], ['leads', Users, sum('leads')], ['interested', Sparkles, sum('interested')],
    ['visits', CalendarCheck, sum('visits_done')], ['reservations', KeyRound, sum('reservations')],
  ]

  return (
    <div className="space-y-3" data-testid="qr-center">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6" data-testid="qr-kpis">
        {kpis.map(([key, Icon, value]) => (
          <KpiCard key={key} testId={`qk-${key}`} valueTestId={`qk-${key}-value`} label={t(`qrCenter.stat.${key}`)} icon={Icon} value={formatNumber(value, i18n.language)} />
        ))}
        {can.has(user, 'revenue.view') && <KpiCard testId="qk-revenue" valueTestId="qk-revenue-value" label={t('qrCenter.stat.revenue')} icon={TrendingUp} tone="good" value={formatMoneyCompact(sum('revenue'), 'SAR', i18n.language)} />}
      </div>

      <div className="flex flex-wrap gap-1.5" role="group" aria-label={t('qrCenter.printedOn')} data-testid="qr-placements">
        {(['all', ...PLACEMENTS] as const).map((p) => (
          <button
            key={p}
            type="button"
            aria-pressed={placement === p}
            data-testid={`qr-placement-${p}`}
            onClick={() => setPlacement(p)}
            className={cn('pressable rounded-full px-3 py-1 text-[0.9375rem] font-semibold', placement === p ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')}
          >
            {p === 'all' ? t('qrCenter.all') : t(`tracking.placements.${p}`)}
            <span className="ms-1.5 tabular-nums opacity-75">{p === 'all' ? codes.length : count(p)}</span>
          </button>
        ))}
      </div>

      {shown.length === 0 ? (
        <EmptyState title={t('qrCenter.noneHere')} />
      ) : (
        <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid="qr-list">
          {shown.map((link) => <QrCard key={link.id} link={link} onOpen={() => onOpen(link.id)} onPrint={() => setPrinting(link)} />)}
        </ul>
      )}

      <PrintDialog link={printing} onClose={() => setPrinting(null)} />
    </div>
  )
}

function QrCard({ link, onOpen, onPrint }: { link: TrackingLink; onOpen: () => void; onPrint: () => void }) {
  const { t, i18n } = useTranslation()
  const user = useAuth((s) => s.user)
  const toast = useUi((s) => s.toast)
  const { data: svg } = useQuery({ queryKey: ['tracking-qr', link.id], queryFn: () => fetchLinkQr(link.id), staleTime: 5 * 60_000 })
  const s = link.stats

  const cells: [LucideIcon, string, number | undefined][] = [
    [ScanLine, 'scans', s.clicks], [Users, 'leads', s.leads], [Sparkles, 'interested', s.interested],
    [CalendarCheck, 'visits', s.visits_done], [KeyRound, 'reservations', s.reservations],
  ]

  const save = (format: 'svg' | 'png') => downloadLinkQr(link.id, format, link.name).catch(() => toast(t('errors.network'), 'error'))

  return (
    <li className="rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]" data-testid={`qr-${link.token}`}>
      <div className="flex items-start gap-3">
        <div className="size-24 shrink-0 overflow-hidden rounded-xl bg-white p-1" data-testid={`qr-thumb-${link.token}`}>
          {svg ? <img src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`} alt={t('tracking.qr.alt', { name: link.name })} className="size-full" /> : <Skeleton className="size-full" />}
        </div>
        <div className="min-w-0 flex-1">
          <button type="button" onClick={onOpen} className="pressable block max-w-full truncate text-start text-[1.0625rem] font-extrabold">{link.name}</button>
          <p className="truncate text-[0.875rem] text-[var(--color-ink-subtle)]">
            {[link.placement ? t(`tracking.placements.${link.placement}`) : null, link.project?.name, link.rep?.name, link.partner?.name].filter(Boolean).join(' · ')}
          </p>
          <p className="mt-1 truncate text-[0.8125rem] text-[var(--color-ink-muted)]" dir="ltr">/t/{link.token}</p>
          {can.has(user, 'revenue.view') && <p className="mt-1 text-[1rem] font-bold tabular-nums" dir="ltr" data-testid={`qr-revenue-${link.token}`}>{formatMoneyCompact(s.revenue, 'SAR', i18n.language)}</p>}
        </div>
      </div>

      <dl className="mt-3 grid grid-cols-5 gap-1.5 text-center">
        {cells.map(([Icon, key, value]) => (
          <div key={key} className="rounded-xl bg-[var(--color-surface-muted)] px-1 py-1.5" data-testid={`qr-${link.token}-${key}`}>
            <Icon className="mx-auto size-3.5 text-[var(--color-ink-subtle)]" aria-hidden />
            <dd className="text-[1.0625rem] font-extrabold tabular-nums">{formatNumber(value ?? 0, i18n.language)}</dd>
            <dt className="truncate text-[0.75rem] text-[var(--color-ink-subtle)]">{t(`qrCenter.stat.${key}`)}</dt>
          </div>
        ))}
      </dl>

      <div className="mt-3 flex flex-wrap gap-1.5">
        <Button size="sm" variant="outline" icon={<Download className="size-3.5" />} onClick={() => void save('svg')} data-testid={`qr-svg-${link.token}`}>SVG</Button>
        <Button size="sm" variant="outline" icon={<Download className="size-3.5" />} onClick={() => void save('png')} data-testid={`qr-png-${link.token}`}>PNG</Button>
        <Button size="sm" icon={<Printer className="size-3.5" />} onClick={onPrint} data-testid={`qr-print-${link.token}`}>{t('qrCenter.print')}</Button>
      </div>
    </li>
  )
}

/** Pick the size it will be printed at, see it, send it to the printer. */
function PrintDialog({ link, onClose }: { link: TrackingLink | null; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const [size, setSize] = useState<PrintSize>('flyer')
  const { data: svg } = useQuery({ queryKey: ['tracking-qr', link?.id], queryFn: () => fetchLinkQr(link!.id), enabled: link !== null })

  const html = link && svg
    ? printableQr(svg, {
      title: link.name, url: link.url, size, dir: i18n.language.startsWith('ar') ? 'rtl' : 'ltr',
      subtitle: [link.placement ? t(`tracking.placements.${link.placement}`) : null, link.project?.name].filter(Boolean).join(' · '),
    })
    : null

  const print = () => {
    if (!html) return
    const w = window.open('', '_blank', 'width=720,height=900')
    if (!w) return
    w.document.write(html)
    w.document.close()
    w.focus()
    w.print()
  }

  return (
    <Modal
      open={link !== null}
      onClose={onClose}
      title={link ? `${t('qrCenter.printReady')} — ${link.name}` : ''}
      size="md"
      footer={<Button icon={<Printer className="size-4" />} onClick={print} disabled={!html} data-testid="qr-print-go">{t('qrCenter.print')}</Button>}
    >
      <div className="space-y-3" data-testid="qr-print-dialog">
        <div className="flex gap-2" role="group" aria-label={t('qrCenter.size')}>
          {PRINT_SIZES.map((s) => (
            <button key={s} type="button" aria-pressed={size === s} data-testid={`qr-size-${s}`} onClick={() => setSize(s)}
              className={cn('pressable flex-1 rounded-xl border p-2 text-start', size === s ? 'border-[var(--brand)] bg-[var(--brand-soft)]' : 'border-[var(--color-border)]')}>
              <span className="t-small block font-semibold">{t(`qrCenter.sizes.${s}`)}</span>
              <span className="t-caption block">{t(`qrCenter.sizeHint.${s}`)}</span>
            </button>
          ))}
        </div>
        <div className="grid place-items-center rounded-xl bg-[var(--color-surface-muted)] p-3">
          {svg ? <img src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`} alt="" className="size-48 rounded-lg bg-white p-2" /> : <QrCode className="size-16 text-[var(--color-ink-subtle)]" aria-hidden />}
        </div>
      </div>
    </Modal>
  )
}
