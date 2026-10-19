import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft, ArrowRight, CirclePause, Copy, Film, Image as ImageIcon, LayoutGrid, MessageSquareQuote, Replace, Shuffle, Sparkles, Type, Waves,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, Drawer, EmptyState, PageHeader, Select, Skeleton, Textarea } from '@/components/ui'
import { RingProgress, StatusPill, type StatusKey } from '@/components/visual'
import { platformColor } from '@/components/charts'
import { ApiError } from '@/lib/api'
import { generateDrafts, setDraftStatus, useCreatives, useDrafts, writeDraft, type CreativeRow } from '@/features/creatives'
import { propose } from '@/features/proposals'
import type { DraftView } from '@/features/agentChat'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { formatMoneyCompact, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

const FORMAT_ICON: Record<string, LucideIcon> = { video: Film, image: ImageIcon, carousel: LayoutGrid }
const STATUS: Record<CreativeRow['status'], StatusKey | null> = { active: 'active', paused: 'paused', ended: 'completed', unknown: null }

type Ask = { ad: CreativeRow; kind: 'pause' | 'duplicate' | 'replace' } | null

/**
 * Creative operations: every ad as what it is — a preview — and what it
 * brought: qualified leads, won deals, revenue, and how tired it is.
 *
 * New copy, hooks and variations are drafts (from the Creative Agent when the
 * AI is on, from a person when it is not). Pausing, duplicating and replacing a
 * live ad are proposals, decided in the approval inbox like every change.
 */
export function CreativesPage() {
  const { t, i18n } = useTranslation()
  const [days, setDays] = useState(30)
  const [platform, setPlatform] = useState('')
  const [draftsFor, setDraftsFor] = useState<CreativeRow | null>(null)
  const [ask, setAsk] = useState<Ask>(null)
  const { data, isLoading } = useCreatives(days, platform)
  const Back = i18n.dir() === 'rtl' ? ArrowRight : ArrowLeft

  return (
    <>
      <PageHeader
        title={t('creatives.title')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Select value={String(days)} onChange={(e) => setDays(Number(e.target.value))} className="filter-pill min-w-32" aria-label={t('common.date')}>
              {[7, 30, 90].map((d) => <option key={d} value={d}>{t('dashboard.lastDays', { count: d })}</option>)}
            </Select>
            <Select value={platform} onChange={(e) => setPlatform(e.target.value)} className="filter-pill min-w-32" aria-label={t('leads.platform')}>
              <option value="">{t('campaigns.allPlatforms')}</option>
              {['meta', 'tiktok', 'google_ads', 'snapchat', 'x', 'linkedin'].map((p) => <option key={p} value={p}>{t(`platforms.${p}`)}</option>)}
            </Select>
          </div>
        }
      />
      <Link to="/campaigns" className="mb-3 inline-flex items-center gap-1.5 text-[0.9375rem] text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">
        <Back className="size-4" aria-hidden />
        {t('campaigns.title')}
      </Link>

      {isLoading || !data ? (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-80" />)}</div>
      ) : data.creatives.length === 0 ? (
        <Card><CardBody><EmptyState icon={<Sparkles className="size-6" />} title={t('creatives.emptyTitle')} body={t('creatives.emptyBody')} /></CardBody></Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="creative-grid">
          {data.creatives.map((c) => (
            <CreativeCard key={c.id} row={c} currency={data.currency} onDrafts={() => setDraftsFor(c)} onAsk={(kind) => setAsk({ ad: c, kind })} />
          ))}
        </div>
      )}

      {draftsFor && <DraftsDrawer ad={draftsFor} onClose={() => setDraftsFor(null)} />}
      {ask && <ProposeModal ask={ask} candidates={data?.creatives ?? []} onClose={() => setAsk(null)} />}
    </>
  )
}

function CreativeCard({ row, currency, onDrafts, onAsk }: { row: CreativeRow; currency: string; onDrafts: () => void; onAsk: (kind: 'pause' | 'duplicate' | 'replace') => void }) {
  const { t, i18n } = useTranslation()
  const Format = FORMAT_ICON[row.creative?.format ?? ''] ?? Type
  const status = STATUS[row.status]
  const money = (v: number | null) => formatMoneyCompact(v, currency, i18n.language)
  const tired = row.fatigue !== null && row.fatigue >= 30

  return (
    <article className="overflow-hidden rounded-[var(--radius-card)] bg-[var(--color-surface)] shadow-[var(--shadow-card)]" data-testid="creative-card">
      <div className="relative grid h-40 place-items-center bg-[var(--color-chrome-fill)]">
        {row.creative?.thumbnail_url ? (
          <img src={row.creative.thumbnail_url} alt="" className="size-full object-cover" loading="lazy" referrerPolicy="no-referrer" />
        ) : (
          <Format className="size-9 text-[var(--color-ink-subtle)]" aria-hidden />
        )}
        <span className="absolute start-2 top-2 size-3 rounded-full ring-2 ring-[var(--color-surface)]" style={{ background: platformColor(row.platform ?? '') }} title={t(`platforms.${row.platform ?? 'other'}`)} />
        {status && <span className="absolute end-2 top-2"><StatusPill status={status} iconOnly /></span>}
        {row.fatigue !== null && (
          <span className="absolute bottom-2 end-2 rounded-full bg-[var(--color-surface)] p-0.5 shadow-[var(--shadow-card)]" title={t('creatives.fatigue')} data-testid="creative-fatigue">
            <RingProgress size={38} thickness={4} value={row.fatigue} tone={tired ? (row.fatigue >= 50 ? 'bad' : 'warn') : 'good'}>
              <Waves className="size-3.5" aria-hidden />
            </RingProgress>
          </span>
        )}
      </div>

      <div className="space-y-3 p-3">
        <div>
          <p className="truncate text-[1rem] font-semibold" dir="auto">{row.name}</p>
          {row.creative?.headline && <p className="truncate text-[0.875rem] text-[var(--color-ink-muted)]" dir="auto">{row.creative.headline}</p>}
          <p className="truncate text-[0.8125rem] text-[var(--color-ink-subtle)]" dir="auto">{row.campaign?.name}</p>
        </div>

        <dl className="grid grid-cols-4 gap-1 text-center">
          {[
            ['ctr', row.ctr === null ? '—' : `${row.ctr}%`],
            ['qualified', formatNumber(row.qualified, i18n.language)],
            ['won', formatNumber(row.won, i18n.language)],
            ['revenue', row.revenue ? money(row.revenue) : '—'],
          ].map(([k, v]) => (
            <div key={k} className="min-w-0">
              <dd className="truncate text-[0.9375rem] font-semibold tabular-nums" dir="ltr">{v}</dd>
              <dt className="truncate text-[0.8125rem] text-[var(--color-ink-subtle)]">{t(`creatives.metric.${k}`)}</dt>
            </div>
          ))}
        </dl>

        {/* Six things to do, as icons: three drafts, three proposals. */}
        <div className="flex flex-wrap items-center gap-1 border-t border-[var(--color-line)] pt-2.5" data-testid="creative-actions">
          <ActionIcon icon={MessageSquareQuote} label={t('creatives.kind.copy')} onClick={onDrafts} testId="act-copy" badge={row.drafts} />
          <ActionIcon icon={Sparkles} label={t('creatives.kind.hook')} onClick={onDrafts} testId="act-hook" />
          <ActionIcon icon={Shuffle} label={t('creatives.kind.variation')} onClick={onDrafts} testId="act-variation" />
          <span className="mx-1 h-5 w-px bg-[var(--color-line)]" aria-hidden />
          <ActionIcon icon={Copy} label={t('creatives.duplicate')} onClick={() => onAsk('duplicate')} disabled={!row.on_platform || !row.ad_set} testId="act-duplicate" />
          <ActionIcon icon={CirclePause} label={t('proposals.action.pause')} onClick={() => onAsk('pause')} disabled={!row.on_platform || row.status !== 'active'} testId="act-pause" />
          <ActionIcon icon={Replace} label={t('creatives.replace')} onClick={() => onAsk('replace')} disabled={!row.on_platform} testId="act-replace" />
        </div>
      </div>
    </article>
  )
}

function ActionIcon({ icon: Icon, label, onClick, disabled, testId, badge }: { icon: LucideIcon; label: string; onClick: () => void; disabled?: boolean; testId: string; badge?: number }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      title={label}
      aria-label={label}
      data-testid={testId}
      className={cn('relative grid size-8 place-items-center rounded-full text-[var(--color-ink-muted)] hover:bg-[var(--brand-soft)] hover:text-[var(--brand)] disabled:opacity-35 disabled:hover:bg-transparent')}
    >
      <Icon className="size-4" aria-hidden />
      {badge ? <span className="absolute -end-0.5 -top-0.5 grid size-4 place-items-center rounded-full bg-[var(--brand)] text-[0.8125rem] font-bold text-[var(--brand-contrast)]">{badge}</span> : null}
    </button>
  )
}

function DraftsDrawer({ ad, onClose }: { ad: CreativeRow; onClose: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const { data: drafts = [] } = useDrafts(ad.id)
  const [kind, setKind] = useState<DraftView['kind']>('copy')
  const [manual, setManual] = useState<string | null>(null)

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['creative-drafts', ad.id] })
    void queryClient.invalidateQueries({ queryKey: ['creatives'] })
  }

  const ai = useMutation({
    mutationFn: () => generateDrafts(ad.id, kind),
    onSuccess: refresh,
    onError: (caught) => {
      // The AI is off: say so, and let a person write the draft instead.
      // 409 is the stated "AI is off" answer; anything else is a real error.
      if (caught instanceof ApiError && caught.status === 409) {
        setManual('')
        toast(t('creatives.aiOff'), 'info')
        return
      }
      toast(translateError(caught), 'error')
    },
  })

  const write = useMutation({
    mutationFn: () => writeDraft(ad.id, kind, (manual ?? '').split('\n').map((s) => s.trim()).filter(Boolean)),
    onSuccess: () => {
      setManual(null)
      refresh()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const mark = useMutation({ mutationFn: ({ id, status }: { id: string; status: 'used' | 'dismissed' }) => setDraftStatus(id, status), onSuccess: refresh })

  return (
    <Drawer open onClose={onClose} title={ad.name} description={t('creatives.draftsHint')} testId="drafts-drawer">
      <div className="space-y-4">
        <div className="flex gap-1" role="radiogroup">
          {(['copy', 'hook', 'variation'] as const).map((k) => (
            <button key={k} type="button" role="radio" aria-checked={kind === k} onClick={() => setKind(k)} className={cn('flex-1 rounded-full px-3 py-1.5 text-[0.9375rem]', kind === k ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--color-chrome-fill)]')}>
              {t(`creatives.kind.${k}`)}
            </button>
          ))}
        </div>
        <div className="flex gap-2">
          <Button icon={<Sparkles className="size-4" />} loading={ai.isPending} onClick={() => ai.mutate()} data-testid="drafts-ai">{t('creatives.askAgent')}</Button>
          <Button variant="outline" onClick={() => setManual('')} data-testid="drafts-manual">{t('creatives.writeMyself')}</Button>
        </div>

        {manual !== null && (
          <div className="space-y-2">
            <Textarea label={t('creatives.oneLinePerOption')} value={manual} onChange={(e) => setManual(e.target.value)} data-testid="drafts-text" />
            <Button size="sm" loading={write.isPending} disabled={manual.trim() === ''} onClick={() => write.mutate()} data-testid="drafts-save">{t('common.save')}</Button>
          </div>
        )}

        <ul className="space-y-2" data-testid="drafts-list">
          {drafts.map((d) => (
            <li key={d.id} className={cn('rounded-[var(--radius-control)] bg-[var(--color-surface-muted)] p-3', d.status !== 'draft' && 'opacity-60')}>
              <p className="mb-1 text-[0.8125rem] text-[var(--color-ink-subtle)]">{t(`creatives.kind.${d.kind}`)} · {t(`creatives.source.${d.source ?? 'user'}`, { defaultValue: '' })}</p>
              <ol className="list-decimal space-y-1 ps-5 text-[0.9375rem]" dir="auto">{d.items.map((line, i) => <li key={i}>{line}</li>)}</ol>
              {d.flags.length > 0 && <p className="mt-1 text-[0.8125rem] text-[var(--color-danger)]">{t('creatives.flagged', { words: d.flags.join('، ') })}</p>}
              {d.status === 'draft' && (
                <div className="mt-2 flex gap-1.5">
                  <Button size="sm" variant="ghost" onClick={() => mark.mutate({ id: d.id, status: 'used' })}>{t('creatives.markUsed')}</Button>
                  <Button size="sm" variant="ghost" onClick={() => mark.mutate({ id: d.id, status: 'dismissed' })}>{t('creatives.dismiss')}</Button>
                </div>
              )}
            </li>
          ))}
        </ul>
      </div>
    </Drawer>
  )
}

function ProposeModal({ ask, candidates, onClose }: { ask: NonNullable<Ask>; candidates: CreativeRow[]; onClose: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [reason, setReason] = useState('')
  const [replacement, setReplacement] = useState('')
  const { ad, kind } = ask

  const others = candidates.filter((c) => c.id !== ad.id && c.platform === ad.platform && c.creative?.external_id)

  const send = useMutation({
    mutationFn: () => {
      if (kind === 'pause') return propose({ action: 'pause', ad_id: ad.id, reason })
      if (kind === 'duplicate') return propose({ action: 'create_ad', ad_set_id: ad.ad_set?.id, payload: { name: `${ad.name} (2)`, creative_id: ad.creative?.external_id }, reason })
      return propose({ action: 'update_creative', ad_id: ad.id, payload: { creative_id: replacement }, reason })
    },
    onSuccess: ({ proposal }) => {
      void queryClient.invalidateQueries({ queryKey: ['proposals'] })
      toast(proposal.status === 'blocked' ? t('proposals.filedBlocked') : t('proposals.filed'), proposal.status === 'blocked' ? 'error' : 'success')
      onClose()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Drawer
      open
      onClose={onClose}
      title={t(`creatives.ask.${kind}`)}
      description={t('proposals.needsApproval')}
      size="sm"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button loading={send.isPending} disabled={reason.trim() === '' || (kind === 'replace' && !replacement)} onClick={() => send.mutate()} data-testid="creative-propose">{t('proposals.submit')}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <p className="text-[0.9375rem] font-semibold" dir="auto">{ad.name}</p>
        {kind === 'replace' && (
          <Select label={t('creatives.replaceWith')} value={replacement} onChange={(e) => setReplacement(e.target.value)}>
            <option value="">—</option>
            {others.map((c) => <option key={c.id} value={c.creative!.external_id!}>{c.name}</option>)}
          </Select>
        )}
        <Textarea label={t('proposals.reason')} value={reason} onChange={(e) => setReason(e.target.value)} data-testid="creative-reason" />
      </div>
    </Drawer>
  )
}
