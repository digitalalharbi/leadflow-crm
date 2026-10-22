import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, BarChart3, CalendarClock, Copy, Mail, Eye, EyeOff, GripVertical, KeyRound, Link2, Plus, RefreshCw, ShieldOff, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardBody, Drawer, EmptyState, ErrorState, Input, Select, Skeleton } from '@/components/ui'
import {
  createShare, deleteShare, fetchShareAnalytics, fetchSharePreview, fetchShares, MONEY_SECTIONS, REPORT_TEMPLATES, regenerateShare, revokeShare, updateShare,
  type LayoutItem, type ReportPreset, type ReportPresetKey, type ReportSection, type ReportShare, type ReportTemplate, type SectionVisibility, type ShareStatus,
} from '@/features/reportShares'
import { createSchedule, deleteSchedule, fetchSchedules, updateSchedule, type ScheduleFrequency } from '@/features/reportSchedules'
import { AreaTrend } from '@/components/visual'
import { emailReport } from '@/features/emailDeliveries'
import { Modal, Textarea } from '@/components/ui'
import { ReportView } from '@/pages/SharedReport'
import { fetchPartners } from '@/features/partners'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { formatLastSeen, formatDate } from '@/lib/format'
import { cn } from '@/lib/cn'

const STATUS_TONE: Record<ShareStatus, 'success' | 'warning' | 'danger'> = { active: 'success', expired: 'warning', revoked: 'danger' }

/**
 * Live reports behind a link: the links a workspace has handed out, who has opened them, and the
 * way to make another in three steps — which report, what it shows, who may open it.
 */
export function ShareTab({ startOpen = false }: { startOpen?: boolean }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [editing, setEditing] = useState<ReportShare | 'new' | null>(startOpen ? 'new' : null)
  const [analytics, setAnalytics] = useState<ReportShare | null>(null)
  const [preview, setPreview] = useState<ReportShare | null>(null)
  const [mailing, setMailing] = useState<ReportShare | null>(null)
  const [scheduling, setScheduling] = useState<ReportShare | null>(null)

  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['report-shares'], queryFn: fetchShares })

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['report-shares'] })

  const copy = async (url: string) => {
    try {
      await navigator.clipboard.writeText(url)
      toast(t('share.copied'), 'success')
    } catch {
      toast(url, 'info')
    }
  }

  const act = useMutation({
    mutationFn: async ({ kind, id }: { kind: 'revoke' | 'regenerate' | 'delete'; id: string }) => {
      if (kind === 'revoke') await revokeShare(id)
      else if (kind === 'regenerate') await regenerateShare(id)
      else await deleteShare(id)
    },
    onSuccess: (_, { kind }) => { toast(t(`share.done.${kind}`), 'success'); refresh() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (isError) return <Card><ErrorState onRetry={() => void refetch()} /></Card>
  if (isLoading || !data) return <Skeleton className="h-48" />

  return (
    <div className="space-y-3" data-testid="share-tab">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="t-caption">{t('share.intro')}</p>
        <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')} data-testid="new-share">{t('share.new')}</Button>
      </div>

      {data.shares.length === 0 ? (
        <Card><CardBody><EmptyState icon={<Link2 className="size-6" />} title={t('share.emptyTitle')} body={t('share.emptyBody')} /></CardBody></Card>
      ) : (
        <ul className="grid gap-3 md:grid-cols-2" data-testid="share-list">
          {data.shares.map((share) => (
            <li key={share.id} className="space-y-2 rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]" data-testid={`share-${share.id}`} data-status={share.status}>
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <h3 className="t-section-title truncate">{share.name}</h3>
                  <p className="t-caption">{t(`share.template.${share.template}`)}{share.partner ? ` · ${share.partner.name}` : ''} · {t('share.lastDays', { count: share.days })}{share.project ? ` · ${share.project.name}` : ''}</p>
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                  <Badge tone={STATUS_TONE[share.status]} testId="share-status">{t(`share.status.${share.status}`)}</Badge>
                  {share.has_password && <Badge tone="neutral"><KeyRound className="size-3" aria-hidden />{t('share.protected')}</Badge>}
                </div>
              </div>

              <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                <span className="t-caption inline-flex items-center gap-1" data-testid="share-views"><Eye className="size-3.5" aria-hidden />{t('share.views', { count: share.view_count })}</span>
                <span className="t-caption" data-testid="share-last">{share.last_viewed_at ? t('share.lastViewed', { when: formatLastSeen(share.last_viewed_at, i18n.language) }) : t('share.neverViewed')}</span>
                {share.expires_at && <span className="t-caption">{t('share.expiresOn', { date: formatDate(share.expires_at, i18n.language) })}</span>}
              </div>

              {(share.schedules ?? []).length > 0 && (
                <p className="t-caption inline-flex flex-wrap items-center gap-x-2 gap-y-0.5" data-testid="share-schedules-line">
                  <CalendarClock className="size-3.5" aria-hidden />
                  {(share.schedules ?? []).map((s) => <span key={s.id} className={s.enabled ? undefined : 'line-through opacity-60'}>{t(`schedule.frequency.${s.frequency}`)}</span>)}
                </p>
              )}

              <div className="flex flex-wrap gap-1.5">
                <Button size="sm" icon={<Copy className="size-3.5" aria-hidden />} onClick={() => void copy(share.url)} disabled={share.status === 'revoked'} data-testid="share-copy">{t('share.copy')}</Button>
                <Button size="sm" variant="secondary" onClick={() => setEditing(share)} data-testid="share-edit">{t('share.edit')}</Button>
                <Button size="sm" variant="outline" icon={<Eye className="size-3.5" aria-hidden />} onClick={() => setPreview(share)} data-testid="share-preview">{t('share.preview')}</Button>
                {share.status === 'active' && <Button size="sm" variant="outline" icon={<Mail className="size-3.5" aria-hidden />} onClick={() => setMailing(share)} data-testid="share-email">{t('emailDelivery.send')}</Button>}
                <Button size="sm" variant="outline" icon={<CalendarClock className="size-3.5" aria-hidden />} onClick={() => setScheduling(share)} data-testid="share-schedule">{t('schedule.action')}</Button>
                <Button size="sm" variant="outline" icon={<BarChart3 className="size-3.5" aria-hidden />} onClick={() => setAnalytics(share)} data-testid="share-analytics">{t('share.analytics')}</Button>
                <Button size="sm" variant="outline" icon={<RefreshCw className="size-3.5" aria-hidden />} loading={act.isPending} onClick={() => act.mutate({ kind: 'regenerate', id: share.id })} data-testid="share-regenerate">{t('share.regenerate')}</Button>
                {share.status !== 'revoked' && (
                  <Button size="sm" variant="outline" icon={<ShieldOff className="size-3.5" aria-hidden />} loading={act.isPending} onClick={() => act.mutate({ kind: 'revoke', id: share.id })} data-testid="share-revoke">{t('share.revoke')}</Button>
                )}
                <Button size="sm" variant="ghost" icon={<Trash2 className="size-3.5" aria-hidden />} loading={act.isPending} onClick={() => act.mutate({ kind: 'delete', id: share.id })} data-testid="share-delete">{t('common.delete')}</Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      <ShareAnalyticsDrawer share={analytics} onClose={() => setAnalytics(null)} />
      <SharePreviewDrawer share={preview} onClose={() => setPreview(null)} />
      <ShareEmailDialog share={mailing} onClose={() => setMailing(null)} />
      <ScheduleDialog share={scheduling} onClose={() => setScheduling(null)} onChanged={refresh} />

      <ShareBuilder
        key={typeof editing === 'object' && editing ? editing.id : 'new'}
        open={editing !== null}
        share={editing === 'new' ? null : editing}
        templates={data.templates}
        presets={data.presets ?? []}
        unavailable={data.unavailable ?? []}
        onClose={() => setEditing(null)}
        onSaved={(share) => { setEditing(null); refresh(); void copy(share.url) }}
      />
    </div>
  )
}

/**
 * The builder: which report (a template), what it shows (each section a switch, money marked),
 * and who may open it (a password, an end date). Saving copies the link.
 */
function ShareBuilder({ open, share, templates, presets, unavailable, onClose, onSaved }: {
  open: boolean
  share: ReportShare | null
  templates: { key: ReportTemplate; sections: Record<ReportSection, boolean>; layout: LayoutItem[] }[]
  presets: ReportPreset[]
  unavailable: ReportSection[]
  onClose: () => void
  onSaved: (share: ReportShare) => void
}) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [name, setName] = useState(share?.name ?? '')
  const [template, setTemplate] = useState<ReportTemplate>(share?.template ?? 'executive')
  const [layout, setLayout] = useState<LayoutItem[]>(share?.layout ?? templates.find((x) => x.key === 'executive')!.layout)
  const [compare, setCompare] = useState(share?.compare ?? false)
  const [refreshSeconds, setRefreshSeconds] = useState(String(share?.refresh_seconds ?? 60))
  const [dragging, setDragging] = useState<number | null>(null)
  const [days, setDays] = useState(String(share?.days ?? 30))
  const [password, setPassword] = useState('')
  const [removePassword, setRemovePassword] = useState(false)
  const [expires, setExpires] = useState(share?.expires_at?.slice(0, 10) ?? '')
  const [partnerId, setPartnerId] = useState(share?.partner?.id ?? '')
  const { data: partners } = useQuery({ queryKey: ['partners-simple'], queryFn: () => fetchPartners(30), enabled: open, select: (d) => d.partners })

  const [preset, setPreset] = useState<ReportPresetKey | null>(null)

  const pick = (key: ReportTemplate) => {
    setPreset(null)
    setTemplate(key)
    setLayout(templates.find((x) => x.key === key)!.layout)
  }

  /** A preset fills the form in; everything on it can still be changed. */
  const applyPreset = (p: ReportPreset) => {
    setPreset(p.key)
    setTemplate(p.template)
    setLayout(p.layout)
    setCompare(p.compare)
    setDays(String(p.days))
    setRefreshSeconds(String(p.refresh_seconds))
    if (!name.trim()) setName(t(`share.preset.${p.key}`))
  }
  /** A preset whose pages are built from what this person may not see is not offered to them. */
  const offered = (p: ReportPreset) => !p.layout.some((row) => row.on && unavailable.includes(row.key))

  const move = (from: number, to: number) => setLayout((prev) => {
    if (to < 0 || to >= prev.length || from === to) return prev
    const next = [...prev]
    const [item] = next.splice(from, 1)
    next.splice(to, 0, item)

    return next
  })
  const change = (key: ReportSection, patch: Partial<LayoutItem>) => setLayout((prev) => prev.map((row) => (row.key === key ? { ...row, ...patch } : row)))

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        name,
        ...(!share && preset ? { preset } : {}),
        template,
        layout,
        sections: Object.fromEntries(layout.map((row) => [row.key, row.on])),
        compare,
        refresh_seconds: Number(refreshSeconds) || 60,
        days: Number(days) || 30,
        expires_at: expires ? new Date(`${expires}T23:59:59`).toISOString() : null,
        partner_id: template === 'partner' ? partnerId || null : null,
        ...(password ? { password } : removePassword ? { password: null } : {}),
      }

      return share ? updateShare(share.id, payload) : createShare(payload)
    },
    onSuccess: (result) => { toast(t('common.saved'), 'success'); onSaved(result.share) },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const money = layout.filter((row) => row.on && MONEY_SECTIONS.includes(row.key))

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={share ? t('share.edit') : t('share.new')}
      size="lg"
      testId="share-builder"
      footer={<Button disabled={!name.trim()} loading={save.isPending} onClick={() => save.mutate()} data-testid="share-save">{t('common.save')}</Button>}
    >
      <div className="space-y-5">
        <Input label={t('share.name')} value={name} data-testid="share-name" onChange={(e) => setName(e.target.value)} />

        {/* Ready-made reports: who it is for, how far back it looks, what it carries. */}
        {!share && presets.length > 0 && (
          <fieldset className="space-y-2">
            <legend className="t-small mb-1 font-medium">{t('share.step.preset')}</legend>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3" data-testid="share-presets">
              {presets.map((p) => (
                <button
                  key={p.key}
                  type="button"
                  aria-pressed={preset === p.key}
                  disabled={!offered(p)}
                  data-testid={`share-preset-${p.key}`}
                  onClick={() => applyPreset(p)}
                  className={cn('pressable rounded-xl border p-2 text-start disabled:opacity-50', preset === p.key ? 'border-[var(--brand)] bg-[var(--brand-soft)]' : 'border-[var(--color-border)]')}
                >
                  <span className="t-small block font-semibold">{t(`share.preset.${p.key}`)}</span>
                  <span className="t-caption block">{offered(p) ? t(`share.presetHint.${p.key}`) : t('share.presetUnavailable')}</span>
                </button>
              ))}
            </div>
          </fieldset>
        )}

        <fieldset className="space-y-2">
          <legend className="t-small mb-1 font-medium">{t('share.step.template')}</legend>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3" data-testid="share-templates">
            {REPORT_TEMPLATES.map((key) => (
              <button
                key={key}
                type="button"
                aria-pressed={template === key}
                data-testid={`share-template-${key}`}
                onClick={() => pick(key)}
                className={cn('pressable rounded-xl border p-2 text-start', template === key ? 'border-[var(--brand)] bg-[var(--brand-soft)]' : 'border-[var(--color-border)]')}
              >
                <span className="t-small block font-semibold">{t(`share.template.${key}`)}</span>
                <span className="t-caption block">{t(`share.templateHint.${key}`)}</span>
              </button>
            ))}
          </div>
        </fieldset>

        {/* A partner's report: their own leads, and never the company's cost or anyone else's data. */}
        {template === 'partner' && (
          <div className="space-y-1" data-testid="share-partner-box">
            <Select label={t('share.partner')} value={partnerId} data-testid="share-partner" onChange={(e) => setPartnerId(e.target.value)}>
              <option value="">—</option>
              {(partners ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </Select>
            <p className="t-caption">{t('share.partnerHint')}</p>
          </div>
        )}

        <fieldset className="space-y-2">
          <legend className="t-small mb-1 font-medium">{t('share.step.sections')}</legend>
          <p className="t-caption">{t('share.layoutHint')}</p>
          <ol className="space-y-1" data-testid="share-sections">
            {layout.map((row, index) => row.key === 'statement' && template !== 'partner' ? null : (
              <li
                key={row.key}
                draggable
                data-testid={`share-row-${row.key}`}
                onDragStart={() => setDragging(index)}
                onDragOver={(e) => e.preventDefault()}
                onDrop={() => { if (dragging !== null) move(dragging, index); setDragging(null) }}
                onDragEnd={() => setDragging(null)}
                className={cn('flex flex-wrap items-center gap-2 rounded-lg border px-2 py-1.5', row.on ? 'border-[var(--color-line)] bg-[var(--color-surface)]' : 'border-transparent bg-[var(--color-surface-muted)] opacity-70', dragging === index && 'opacity-50')}
              >
                <GripVertical className="size-4 shrink-0 cursor-grab text-[var(--color-ink-subtle)]" aria-hidden />
                <span className="t-small flex min-w-0 flex-1 items-center gap-1.5">
                  {t(`share.section.${row.key}`)}
                  {MONEY_SECTIONS.includes(row.key) && <Badge tone="warning">{t('share.money')}</Badge>}
                </span>
                <span className="flex items-center gap-0.5">
                  <Button size="icon" variant="ghost" aria-label={t('share.moveUp')} disabled={index === 0} data-testid={`share-up-${row.key}`} icon={<ArrowUp className="size-4" />} onClick={() => move(index, index - 1)} />
                  <Button size="icon" variant="ghost" aria-label={t('share.moveDown')} disabled={index === layout.length - 1} data-testid={`share-down-${row.key}`} icon={<ArrowDown className="size-4" />} onClick={() => move(index, index + 1)} />
                </span>
                <select
                  aria-label={t('share.visibility')}
                  value={row.visible_to}
                  data-testid={`share-visibility-${row.key}`}
                  onChange={(e) => change(row.key, { visible_to: e.target.value as SectionVisibility })}
                  className="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-1.5 py-1 text-[0.8125rem]"
                >
                  <option value="public">{t('share.visibleTo.public')}</option>
                  <option value="team">{t('share.visibleTo.team')}</option>
                </select>
                <select
                  aria-label={t('share.scope')}
                  value={row.days ?? ''}
                  data-testid={`share-scope-${row.key}`}
                  onChange={(e) => change(row.key, { days: e.target.value === '' ? null : Number(e.target.value) })}
                  className="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-1.5 py-1 text-[0.8125rem]"
                >
                  <option value="">{t('share.scopeReport')}</option>
                  {[7, 30, 90, 365].map((d) => <option key={d} value={d}>{t('share.lastDays', { count: d })}</option>)}
                </select>
                <label className="flex cursor-pointer items-center gap-1.5">
                  {row.on ? <Eye className="size-4 text-[var(--brand)]" aria-hidden /> : <EyeOff className="size-4 text-[var(--color-ink-subtle)]" aria-hidden />}
                  <input
                    type="checkbox"
                    role="switch"
                    checked={row.on}
                    aria-label={t(`share.section.${row.key}`)}
                    data-testid={`share-section-${row.key}`}
                    onChange={(e) => change(row.key, { on: e.target.checked })}
                    className="size-4 accent-[var(--brand)]"
                  />
                </label>
              </li>
            ))}
          </ol>
          {money.length > 0 && <p className="t-caption text-[var(--color-warning)]" data-testid="share-money-warning">{t('share.moneyWarning')}</p>}
        </fieldset>

        <fieldset className="grid gap-3 sm:grid-cols-2">
          <label className="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-2 py-1.5 hover:bg-[var(--color-surface-muted)]">
            <span className="t-small">{t('share.compare')}<span className="t-caption block">{t('share.compareHint')}</span></span>
            <input type="checkbox" role="switch" checked={compare} data-testid="share-compare" onChange={(e) => setCompare(e.target.checked)} className="size-4 accent-[var(--brand)]" />
          </label>
          <Select label={t('share.refresh')} value={refreshSeconds} data-testid="share-refresh" onChange={(e) => setRefreshSeconds(e.target.value)}>
            {[30, 60, 300, 900].map((s) => <option key={s} value={s}>{t(`share.refreshEvery.${s}`)}</option>)}
          </Select>
        </fieldset>

        <div className="grid gap-3 sm:grid-cols-2">
          <Select label={t('share.period')} value={days} onChange={(e) => setDays(e.target.value)} data-testid="share-days">
            {[7, 30, 90, 365].map((d) => <option key={d} value={d}>{t('share.lastDays', { count: d })}</option>)}
          </Select>
          <Input type="date" label={t('share.expires')} hint={t('common.optional')} value={expires} data-testid="share-expires" onChange={(e) => setExpires(e.target.value)} />
        </div>

        <div className="space-y-1">
          <Input
            type="text"
            label={t('share.password')}
            hint={share?.has_password ? t('share.passwordKeep') : t('common.optional')}
            value={password}
            autoComplete="off"
            data-testid="share-password"
            onChange={(e) => { setPassword(e.target.value); setRemovePassword(false) }}
          />
          {share?.has_password && (
            <label className="t-caption flex items-center gap-2">
              <input type="checkbox" checked={removePassword} data-testid="share-remove-password" onChange={(e) => { setRemovePassword(e.target.checked); if (e.target.checked) setPassword('') }} className="size-4 accent-[var(--brand)]" />
              {t('share.removePassword')}
            </label>
          )}
        </div>
      </div>
    </Drawer>
  )
}


/** Who opened a link, and when: the views over time, how many different people, and the last few. */
function ShareAnalyticsDrawer({ share, onClose }: { share: ReportShare | null; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['share-analytics', share?.id], queryFn: () => fetchShareAnalytics(share!.id), enabled: share !== null })
  const day = new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { day: 'numeric', month: 'short' })

  return (
    <Drawer open={share !== null} onClose={onClose} title={t('share.analyticsTitle', { name: share?.name ?? '' })} size="md" testId="share-analytics-drawer">
      {isLoading || !data ? <Skeleton className="h-48" /> : (
        <div className="space-y-4">
          <div className="grid grid-cols-3 gap-2 text-center">
            {([['total', data.total], ['unique', data.unique]] as const).map(([key, value]) => (
              <div key={key} className="rounded-xl bg-[var(--color-surface-muted)] p-2"><p className="t-metric" dir="ltr" data-testid={`analytics-${key}`}>{value}</p><p className="t-caption">{t(`share.analyticsStat.${key}`)}</p></div>
            ))}
            <div className="rounded-xl bg-[var(--color-surface-muted)] p-2"><p className="t-small font-semibold" data-testid="analytics-last">{data.last_viewed_at ? formatLastSeen(data.last_viewed_at, i18n.language) : '—'}</p><p className="t-caption">{t('share.analyticsStat.last')}</p></div>
          </div>
          <AreaTrend height={120} axes valueLabel={t('share.analyticsStat.total')} points={data.daily.map((p) => ({ label: day.format(new Date(p.date)), value: p.views }))} />
          <ul className="space-y-1" data-testid="analytics-recent">
            {data.recent.map((r, i) => (
              <li key={i} className="flex items-center justify-between rounded-lg bg-[var(--color-surface-muted)] px-3 py-1.5 text-[0.875rem]">
                <span>{formatLastSeen(r.at, i18n.language)}</span><span className="t-caption">{t(`share.device.${r.device}`, { defaultValue: r.device })}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </Drawer>
  )
}


/** The report as the team sees it — every section that is on, the team-only ones too. */
function SharePreviewDrawer({ share, onClose }: { share: ReportShare | null; onClose: () => void }) {
  const { t } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['share-preview', share?.id], queryFn: () => fetchSharePreview(share!.id), enabled: share !== null })

  return (
    <Drawer open={share !== null} onClose={onClose} title={t('share.previewTitle', { name: share?.name ?? '' })} description={t('share.previewHint')} size="xl" testId="share-preview-drawer">
      {isLoading || !data ? <Skeleton className="h-64" /> : <ReportView data={{ ...data, workspace: data.workspace ?? { name: null, color: null, logo_url: null } }} />}
    </Drawer>
  )
}


/** Email the link to a report to a few people. The email carries the link — never the numbers or the password. */
function ShareEmailDialog({ share, onClose }: { share: ReportShare | null; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const queryClient = useQueryClient()
  const [recipients, setRecipients] = useState('')
  const [message, setMessage] = useState('')
  const list = recipients.split(/[\s,;،]+/).map((r) => r.trim()).filter(Boolean)

  const send = useMutation({
    mutationFn: () => emailReport(share!.id, { recipients: list, message: message || undefined }),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['email-deliveries'] })
      const loggedOnly = result.deliveries.every((d) => d.provider === 'log')
      toast(loggedOnly ? t('emailDelivery.notDelivered') : t('emailDelivery.queued', { count: result.deliveries.length }), loggedOnly ? 'info' : 'success')
      setRecipients('')
      setMessage('')
      onClose()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Modal
      open={share !== null}
      onClose={onClose}
      title={share ? t('emailDelivery.sendTitle', { name: share.name }) : ''}
      size="sm"
      footer={<Button disabled={list.length === 0 || list.length > 20} loading={send.isPending} onClick={() => send.mutate()} data-testid="share-email-send">{t('emailDelivery.sendNow')}</Button>}
    >
      <div className="space-y-3" data-testid="share-email-dialog">
        <Textarea label={t('emailDelivery.recipients')} hint={t('emailDelivery.recipientsHint')} dir="ltr" rows={3} value={recipients} onChange={(e) => setRecipients(e.target.value)} data-testid="share-email-recipients" />
        <Textarea label={t('emailDelivery.message')} rows={2} value={message} onChange={(e) => setMessage(e.target.value)} />
      </div>
    </Modal>
  )
}


const WEEKDAYS = [7, 1, 2, 3, 4, 5, 6] as const

/**
 * A report that sends itself: every day, every week on a weekday, every month on a day — at an hour
 * of the workspace's own clock, to a list of people, as a link. The existing schedules are listed
 * (each can be paused or removed), and a new one is a few fields.
 */
function ScheduleDialog({ share, onClose, onChanged }: { share: ReportShare | null; onClose: () => void; onChanged: () => void }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [frequency, setFrequency] = useState<ScheduleFrequency>('weekly')
  const [weekday, setWeekday] = useState('7')
  const [monthDay, setMonthDay] = useState('1')
  const [hour, setHour] = useState('8')
  const [recipients, setRecipients] = useState('')
  const [message, setMessage] = useState('')

  const key = ['report-schedules', share?.id]
  const { data } = useQuery({ queryKey: key, queryFn: () => fetchSchedules(share!.id), enabled: share !== null })
  const done = () => { void queryClient.invalidateQueries({ queryKey: key }); onChanged() }
  const list = recipients.split(/[\s,;،]+/).map((r) => r.trim()).filter(Boolean)

  const add = useMutation({
    mutationFn: () => createSchedule(share!.id, {
      frequency, send_hour: Number(hour), recipients: list, message: message || null,
      ...(frequency === 'weekly' ? { weekday: Number(weekday) } : {}), ...(frequency === 'monthly' ? { month_day: Number(monthDay) } : {}),
    }),
    onSuccess: () => { setRecipients(''); setMessage(''); toast(t('schedule.created'), 'success'); done() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })
  const toggle = useMutation({
    mutationFn: ({ id, enabled }: { id: string; enabled: boolean }) => updateSchedule(id, { enabled }),
    onSuccess: done,
    onError: (caught) => toast(translateError(caught), 'error'),
  })
  const remove = useMutation({
    mutationFn: (id: string) => deleteSchedule(id),
    onSuccess: () => { toast(t('schedule.deleted'), 'success'); done() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const when = (s: { frequency: ScheduleFrequency; weekday: number | null; month_day: number | null; send_hour: number }) => {
    const at = `${String(s.send_hour).padStart(2, '0')}:00`

    return s.frequency === 'daily' ? t('schedule.whenDaily', { at }) : s.frequency === 'weekly' ? t('schedule.whenWeekly', { day: t(`schedule.weekday.${s.weekday}`), at }) : t('schedule.whenMonthly', { day: s.month_day, at })
  }

  return (
    <Modal open={share !== null} onClose={onClose} title={share ? t('schedule.title', { name: share.name }) : ''} size="lg" footer={<Button disabled={list.length === 0 || list.length > 20} loading={add.isPending} onClick={() => add.mutate()} data-testid="schedule-add">{t('schedule.add')}</Button>}>
      <div className="space-y-4" data-testid="schedule-dialog">
        <p className="t-caption">{t('schedule.hint')}</p>

        {(data?.schedules ?? []).length > 0 && (
          <ul className="space-y-1.5" data-testid="schedule-list">
            {(data?.schedules ?? []).map((s) => (
              <li key={s.id} data-testid={`schedule-${s.id}`} data-enabled={s.enabled} className="flex flex-wrap items-center gap-2 rounded-lg bg-[var(--color-surface-muted)] px-2.5 py-1.5">
                <span className="t-small min-w-0 flex-1">
                  <strong>{t(`schedule.frequency.${s.frequency}`)}</strong> · {when(s)}
                  <span className="t-caption block">{t('schedule.to', { count: s.recipients.length })}{s.next_run_at ? ` · ${t('schedule.next', { when: formatLastSeen(s.next_run_at, i18n.language) })}` : ''}{s.last_status === 'stopped' ? ` · ${t('schedule.stopped')}` : ''}</span>
                </span>
                <label className="flex cursor-pointer items-center gap-1.5 text-[0.875rem]">
                  <input type="checkbox" role="switch" checked={s.enabled} data-testid={`schedule-enabled-${s.id}`} onChange={(e) => toggle.mutate({ id: s.id, enabled: e.target.checked })} className="size-4 accent-[var(--brand)]" />
                  {s.enabled ? t('schedule.on') : t('schedule.off')}
                </label>
                <Button size="icon" variant="ghost" aria-label={t('common.delete')} icon={<Trash2 className="size-4" />} data-testid={`schedule-delete-${s.id}`} onClick={() => remove.mutate(s.id)} />
              </li>
            ))}
          </ul>
        )}

        <div className="grid gap-3 sm:grid-cols-3">
          <Select label={t('schedule.every')} value={frequency} data-testid="schedule-frequency" onChange={(e) => setFrequency(e.target.value as ScheduleFrequency)}>
            {(['daily', 'weekly', 'monthly'] as const).map((f) => <option key={f} value={f}>{t(`schedule.frequency.${f}`)}</option>)}
          </Select>
          {frequency === 'weekly' && (
            <Select label={t('schedule.onWeekday')} value={weekday} data-testid="schedule-weekday" onChange={(e) => setWeekday(e.target.value)}>
              {WEEKDAYS.map((d) => <option key={d} value={d}>{t(`schedule.weekday.${d}`)}</option>)}
            </Select>
          )}
          {frequency === 'monthly' && (
            <Select label={t('schedule.onDay')} value={monthDay} data-testid="schedule-monthday" onChange={(e) => setMonthDay(e.target.value)}>
              {Array.from({ length: 28 }, (_, i) => i + 1).map((d) => <option key={d} value={d}>{d}</option>)}
            </Select>
          )}
          <Select label={t('schedule.atHour')} value={hour} data-testid="schedule-hour" onChange={(e) => setHour(e.target.value)}>
            {Array.from({ length: 24 }, (_, h) => h).map((h) => <option key={h} value={h}>{String(h).padStart(2, '0')}:00</option>)}
          </Select>
        </div>

        <Textarea label={t('emailDelivery.recipients')} hint={t('schedule.recipientsHint')} dir="ltr" rows={2} value={recipients} onChange={(e) => setRecipients(e.target.value)} data-testid="schedule-recipients" />
        <Textarea label={t('emailDelivery.message')} rows={2} value={message} onChange={(e) => setMessage(e.target.value)} />
      </div>
    </Modal>
  )
}
