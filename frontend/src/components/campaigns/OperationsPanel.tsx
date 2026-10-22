import { Fragment, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ChevronDown, CirclePause, CirclePlay, ClipboardList, FlaskConical, MoreHorizontal, Radar, RefreshCw, SlidersHorizontal, Wallet } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardBody, Drawer, Dropdown, DropdownItem, EmptyState, Input, Modal, Select, Skeleton, Table, TableWrap, Td, Textarea, Th, Tr } from '@/components/ui'
import { Gate } from '@/components/access/Gate'
import { Donut, KpiCard, ProgressBar, RingProgress, StatusPill, type StatusKey } from '@/components/visual'
import { platformColor } from '@/components/charts'
import { ProposalInbox } from '@/components/campaigns/ProposalInbox'
import { QualityBadge, CLASS_TONE } from '@/components/campaigns/QualityBadge'
import {
  fetchCampaignTrackingHealth, operateCampaign, QUALITY_CLASSES, useOperations,
  type OperateAction, type OperationsRow, type QualityClass,
} from '@/features/campaignOps'
import { useApiError } from '@/hooks/useApiError'
import { formatMoneyCompact, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

const CLASS_COLOR: Record<QualityClass, string> = {
  excellent: 'var(--brand)', healthy: 'color-mix(in srgb, var(--brand) 55%, white)', watch: 'var(--color-warning)',
  at_risk: 'color-mix(in srgb, var(--color-danger) 65%, white)', critical: 'var(--color-danger)',
}

/**
 * Campaign operations: every campaign in one table — what it costs, what it brought, how good it is
 * and why — and the five things a person can do to it. Changes to an ad account are proposed, not
 * made: each one waits in the inbox for a decision, is logged, and asking twice is one request.
 */
export function OperationsPanel({ days, platform }: { days: number; platform: string }) {
  const { t, i18n } = useTranslation()
  const user = useAuth((s) => s.user)
  const [status, setStatus] = useState('')
  const [klass, setKlass] = useState<QualityClass | ''>('')
  const [open, setOpen] = useState<string | null>(null)
  const [dialog, setDialog] = useState<{ row: OperationsRow; action: OperateAction } | null>(null)
  const [tracking, setTracking] = useState<OperationsRow | null>(null)
  const [pending, setPending] = useState<OperationsRow | null>(null)
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const { data, isLoading } = useOperations({ days, platform, status })
  const canOperate = can.has(user, 'campaigns.execute')

  const sync = useMutation({
    mutationFn: (row: OperationsRow) => operateCampaign(row.id, { action: 'sync' }),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['campaign-operations'] })
      if (result.kind === 'sync') toast(result.synced ? t('campaignOps.synced') : t(`campaignOps.syncReason.${result.reason === 'not_connected' ? 'not_connected' : 'failed'}`), result.synced ? 'success' : 'info')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (isLoading || !data) return <Skeleton className="h-72" />

  const money = (v: number | null | undefined) => formatMoneyCompact(v ?? null, data.currency, i18n.language)
  const num = (v: number) => formatNumber(v, i18n.language)
  const rows = data.campaigns.filter((r) => !klass || r.quality.class === klass)
  const active = data.campaigns.filter((r) => r.status === 'active').length
  const waiting = data.campaigns.reduce((n, r) => n + r.pending_proposals, 0)

  return (
    <div className="space-y-3" data-testid="campaign-operations">
      {data.sandbox && (
        <div className="flex items-center gap-2 rounded-[var(--radius-card)] bg-[color-mix(in_srgb,var(--color-warning)_14%,var(--color-surface))] px-3 py-2 text-[0.9375rem]" data-testid="ops-sandbox">
          <FlaskConical className="size-4 shrink-0 text-[var(--color-warning)]" aria-hidden />
          <span>{t('campaignOps.sandbox')}</span>
        </div>
      )}

      <div className="grid gap-3 lg:grid-cols-[minmax(0,22rem)_1fr]">
        <Card>
          <CardBody>
            <h2 className="t-card-title mb-2">{t('campaignOps.healthTitle')}</h2>
            <Donut
              testId="ops-classes"
              size={112}
              thickness={14}
              legend="side"
              centerLabel={t('campaignOps.campaigns')}
              segments={QUALITY_CLASSES.map((c) => ({ key: c, label: t(`campaignOps.class.${c}`), value: data.classes[c] ?? 0, color: CLASS_COLOR[c] }))}
            />
          </CardBody>
        </Card>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <KpiCard testId="ops-active" valueTestId="ops-active-value" label={t('campaignOps.active')} icon={CirclePlay} value={`${num(active)}/${num(data.campaigns.length)}`} />
          <Gate element="campaigns.field.spend"><KpiCard testId="ops-spend" valueTestId="ops-spend-value" label={t('campaigns.spend')} icon={Wallet} value={money(data.totals.spend)} /></Gate>
          <KpiCard testId="ops-leads" valueTestId="ops-leads-value" label={t('campaigns.leads')} icon={ClipboardList} value={num(data.totals.leads)} />
          <KpiCard testId="ops-waiting" valueTestId="ops-waiting-value" label={t('campaignOps.waiting')} icon={SlidersHorizontal} tone={waiting > 0 ? 'warn' : 'neutral'} value={num(waiting)} />
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="flex flex-wrap gap-1.5" role="group" aria-label={t('campaignOps.score')} data-testid="ops-class-filter">
          {(['', ...QUALITY_CLASSES] as const).map((c) => (
            <button
              key={c || 'all'}
              type="button"
              aria-pressed={klass === c}
              data-testid={`ops-class-${c || 'all'}`}
              onClick={() => setKlass(c)}
              className={cn('pressable rounded-full px-3 py-1 text-[0.875rem] font-semibold', klass === c ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')}
            >
              {c === '' ? t('campaignOps.all') : t(`campaignOps.class.${c}`)}
              {c !== '' && <span className="ms-1.5 tabular-nums opacity-75">{data.classes[c] ?? 0}</span>}
            </button>
          ))}
        </div>
        <Select value={status} onChange={(e) => setStatus(e.target.value)} className="filter-pill ms-auto min-w-32" aria-label={t('campaignOps.status')} data-testid="ops-status-filter">
          <option value="">{t('campaignOps.anyStatus')}</option>
          {['active', 'paused', 'ended'].map((s) => <option key={s} value={s}>{t(`campaignOps.statusName.${s}`)}</option>)}
        </Select>
      </div>

      {rows.length === 0 ? (
        <Card><CardBody><EmptyState title={t('campaignOps.none')} /></CardBody></Card>
      ) : (
        <Card>
          <CardBody className="p-0">
            <TableWrap>
              <Table data-testid="ops-table">
                <thead>
                  <tr>
                    <Th>{t('campaignOps.col.campaign')}</Th>
                    <Th>{t('campaignOps.status')}</Th>
                    <Gate element="campaigns.field.budget"><Th>{t('campaignOps.col.budget')}</Th></Gate>
                    <Gate element="campaigns.field.spend"><Th>{t('campaigns.spend')}</Th></Gate>
                    <Th>{t('campaigns.leads')}</Th>
                    <Th>{t('revenue.kpi.interested')}</Th>
                    <Th>{t('revenue.kpi.visits')}</Th>
                    <Th>{t('revenue.kpi.reservations')}</Th>
                    <Th>{t('revenue.kpi.won')}</Th>
                    {can.has(user, 'revenue.view') && <Th>{t('revenue.kpi.revenue')}</Th>}
                    <Th>{t('campaignOps.score')}</Th>
                    <Th><span className="sr-only">{t('common.actions')}</span></Th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <Fragment key={row.id}>
                      <Tr data-testid={`ops-row-${row.id}`} data-class={row.quality.class ?? 'none'}>
                        <Td className="min-w-48">
                          <button type="button" onClick={() => setOpen(open === row.id ? null : row.id)} aria-expanded={open === row.id} data-testid={`ops-toggle-${row.id}`} className="pressable flex w-full items-start gap-2 text-start">
                            <ChevronDown className={cn('mt-1 size-4 shrink-0 text-[var(--color-ink-subtle)] transition-transform', open === row.id && 'rotate-180')} aria-hidden />
                            <span className="min-w-0">
                              <span className="flex items-center gap-1.5 font-bold">
                                <span aria-hidden className="size-2 shrink-0 rounded-full" style={{ background: platformColor(row.platform) }} />
                                <span className="truncate">{row.name}</span>
                              </span>
                              <span className="t-caption block truncate">{[t(`platforms.${row.platform}`, { defaultValue: row.platform }), row.project?.name, row.objective].filter(Boolean).join(' · ')}</span>
                            </span>
                          </button>
                        </Td>
                        <Td>
                          <StatusPill status={(row.status === 'active' ? 'active' : row.status === 'paused' ? 'paused' : 'completed') as StatusKey} />
                        </Td>
                        <Gate element="campaigns.field.budget"><Td className="tabular-nums" dir="ltr">{money(row.daily_budget)}</Td></Gate>
                        <Gate element="campaigns.field.spend"><Td className="tabular-nums" dir="ltr">{money(row.spend)}</Td></Gate>
                        <Td className="tabular-nums">{num(row.leads)}</Td>
                        <Td className="tabular-nums">{num(row.interested)}</Td>
                        <Td className="tabular-nums">{num(row.visits)}</Td>
                        <Td className="tabular-nums">{num(row.reservations)}</Td>
                        <Td className="tabular-nums">{num(row.won)}</Td>
                        {can.has(user, 'revenue.view') && <Td className="tabular-nums" dir="ltr">{money(row.revenue)}</Td>}
                        <Td><QualityBadge quality={row.quality} /></Td>
                        <Td className="whitespace-nowrap">
                          <span className="inline-flex items-center gap-1">
                            {row.pending_proposals > 0 && (
                              <button type="button" onClick={() => setPending(row)} data-testid={`ops-pending-${row.id}`} className="pressable">
                                <Badge tone="warning">{t('campaignOps.pending', { count: row.pending_proposals })}</Badge>
                              </button>
                            )}
                            <RowMenu row={row} canOperate={canOperate} onAct={(action) => (action === 'sync' ? sync.mutate(row) : setDialog({ row, action }))} onTracking={() => setTracking(row)} syncing={sync.isPending} />
                          </span>
                        </Td>
                      </Tr>
                      {open === row.id && (
                        <tr data-testid={`ops-detail-${row.id}`}>
                          <td colSpan={12} className="bg-[var(--color-surface-muted)] px-4 py-3"><QualityDetail row={row} /></td>
                        </tr>
                      )}
                    </Fragment>
                  ))}
                </tbody>
              </Table>
            </TableWrap>
          </CardBody>
        </Card>
      )}

      <OperateDialog target={dialog} currency={data.currency} onClose={() => setDialog(null)} />
      <TrackingDrawer row={tracking} onClose={() => setTracking(null)} />
      <Drawer open={pending !== null} onClose={() => setPending(null)} title={pending ? `${t('proposals.title')} — ${pending.name}` : ''} size="lg" testId="ops-pending-drawer">
        {pending && <ProposalInbox campaignId={pending.id} currency={data.currency} />}
      </Drawer>
    </div>
  )
}

function RowMenu({ row, canOperate, onAct, onTracking, syncing }: { row: OperationsRow; canOperate: boolean; onAct: (a: OperateAction) => void; onTracking: () => void; syncing: boolean }) {
  const { t } = useTranslation()
  const can_ = (a: OperateAction) => canOperate && row.can.includes(a)

  return (
    <Dropdown
      trigger={({ toggle }) => (
        <Button variant="ghost" size="icon" aria-label={t('campaignOps.actions')} onClick={toggle} data-testid={`ops-menu-${row.id}`} icon={<MoreHorizontal className="size-4" />} />
      )}
    >
      {({ close }) => (
        <>
          {row.status === 'active' && can_('pause') && <DropdownItem icon={<CirclePause className="size-4" />} testId={`ops-pause-${row.id}`} onClick={() => { close(); onAct('pause') }}>{t('proposals.action.pause')}</DropdownItem>}
          {row.status === 'paused' && can_('resume') && <DropdownItem icon={<CirclePlay className="size-4" />} testId={`ops-resume-${row.id}`} onClick={() => { close(); onAct('resume') }}>{t('proposals.action.resume')}</DropdownItem>}
          {can_('set_budget') && <DropdownItem icon={<Wallet className="size-4" />} testId={`ops-budget-${row.id}`} onClick={() => { close(); onAct('set_budget') }}>{t('proposals.action.update_budget')}</DropdownItem>}
          {can_('set_status') && <DropdownItem icon={<SlidersHorizontal className="size-4" />} testId={`ops-status-${row.id}`} onClick={() => { close(); onAct('set_status') }}>{t('campaignOps.adjustStatus')}</DropdownItem>}
          {canOperate && <DropdownItem icon={<RefreshCw className={cn('size-4', syncing && 'animate-spin')} />} testId={`ops-sync-${row.id}`} onClick={() => { close(); onAct('sync') }}>{t('campaignOps.sync')}</DropdownItem>}
          <DropdownItem icon={<Radar className="size-4" />} testId={`ops-tracking-${row.id}`} onClick={() => { close(); onTracking() }}>{t('campaignOps.viewTracking')}</DropdownItem>
        </>
      )}
    </Dropdown>
  )
}

/** Why the score is what it is, and the one thing that would help most. */
function QualityDetail({ row }: { row: OperationsRow }) {
  const { t } = useTranslation()
  const q = row.quality

  if (q.score === null) return <p className="t-caption">{t('campaignOps.notEnoughHint')}</p>

  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_1fr]" data-testid="quality-detail">
      <div className="space-y-2">
        <p className="t-small font-semibold">{t('campaignOps.why')}</p>
        {q.why.length === 0 ? <p className="t-caption">{t('campaignOps.nothingWrong')}</p> : (
          <ul className="space-y-1.5" data-testid="quality-why">
            {q.why.map((w) => (
              <li key={w.key} className="flex items-center gap-2 text-[0.9375rem]">
                <RingProgress size={30} thickness={4} value={w.score} tone={w.score >= 55 ? 'warn' : 'bad'}><span className="text-[0.6875rem] font-bold tabular-nums">{w.score}</span></RingProgress>
                <span><strong>{t(`campaignOps.component.${w.key}`)}</strong>{w.benchmark !== null && <span className="t-caption"> — {t('campaignOps.against', { value: w.value, benchmark: w.benchmark })}</span>}</span>
              </li>
            ))}
          </ul>
        )}
        {q.action && (
          <p className="rounded-xl bg-[var(--brand-soft)] px-3 py-2 text-[0.9375rem] font-semibold" data-testid="quality-action">
            {t(`campaignOps.action.${q.action.key}`)}
            {row.can.includes('pause') && (q.class === 'critical' || q.class === 'at_risk') && <Link to={`/campaigns/${row.id}`} className="ms-2 text-[var(--brand)] underline">{t('campaignOps.openCampaign')}</Link>}
          </p>
        )}
      </div>
      <div className="space-y-1.5">
        <p className="t-small font-semibold">{t('campaignOps.components')}</p>
        <ul className="grid grid-cols-2 gap-x-4 gap-y-1">
          {q.components.map((c) => (
            <li key={c.key} className="text-[0.8125rem]">
              <span className="flex justify-between"><span>{t(`campaignOps.component.${c.key}`)}</span><span className="tabular-nums" dir="ltr">{c.score}</span></span>
              <ProgressBar value={c.score} tone={CLASS_TONE[c.score >= 85 ? 'excellent' : c.score >= 70 ? 'healthy' : c.score >= 55 ? 'watch' : c.score >= 40 ? 'at_risk' : 'critical']} />
            </li>
          ))}
        </ul>
        <p className="t-caption">{t(`campaignOps.confidence.${q.confidence}`)}</p>
      </div>
    </div>
  )
}

/** Pause, resume, adjust the status, change the budget: a reason, and (for a budget) the number. It is filed, not made. */
function OperateDialog({ target, currency, onClose }: { target: { row: OperationsRow; action: OperateAction } | null; currency: string; onClose: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [reason, setReason] = useState('')
  const [budget, setBudget] = useState('')
  const [next, setNext] = useState<'active' | 'paused'>('paused')

  const row = target?.row
  const action = target?.action

  const send = useMutation({
    mutationFn: () => operateCampaign(row!.id, {
      action: action!, reason,
      ...(action === 'set_budget' ? { daily_budget: Number(budget) } : {}),
      ...(action === 'set_status' ? { status: next } : {}),
    }),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['campaign-operations'] })
      void queryClient.invalidateQueries({ queryKey: ['proposals'] })
      setReason('')
      onClose()
      if (result.kind !== 'proposal') return
      const p = result.proposal
      // The safe self-approve mode may have run it (or tried to) without the inbox: say so, honestly.
      if (p.approval === 'self') toast(p.status === 'blocked' ? t('proposals.selfBlocked', { why: p.policy.blocks.map((b) => t(`proposals.block.${b}`, { defaultValue: b })).join('، ') }) : t('proposals.selfRan'), p.status === 'blocked' || p.status === 'failed' ? 'error' : 'success')
      else toast(p.status === 'blocked' ? t('proposals.filedBlocked') : t('proposals.filed'), p.status === 'blocked' ? 'error' : 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const needsBudget = action === 'set_budget'
  // Pause and resume halves of "adjust status" are judged separately: the one being asked for is what counts.
  const instant = !!row && !!action && (action === 'set_status' ? row.instant.includes(next === 'active' ? 'resume' : 'pause') : row.instant.includes(action))

  return (
    <Modal
      open={target !== null}
      onClose={onClose}
      title={action && row ? `${action === 'set_status' ? t('campaignOps.adjustStatus') : t(`proposals.action.${action === 'set_budget' ? 'update_budget' : action}`)} — ${row.name}` : ''}
      description={instant ? t('proposals.runsNow') : t('proposals.needsApproval')}
      size="sm"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button disabled={reason.trim() === '' || (needsBudget && !(Number(budget) > 0))} loading={send.isPending} onClick={() => send.mutate()} data-testid="ops-submit">{instant ? t('proposals.runNow') : t('proposals.submit')}</Button>
        </>
      }
    >
      <div className="space-y-4" data-testid="ops-dialog" data-instant={instant}>
        {needsBudget && (
          <Input label={`${t('proposals.newBudget')} (${currency})`} type="number" min="1" dir="ltr" value={budget} placeholder={row?.daily_budget ? String(row.daily_budget) : ''} onChange={(e) => setBudget(e.target.value)} data-testid="ops-budget-input" />
        )}
        {action === 'set_status' && (
          <Select label={t('campaignOps.status')} value={next} onChange={(e) => setNext(e.target.value as 'active' | 'paused')} data-testid="ops-status-select">
            <option value="paused">{t('campaignOps.statusName.paused')}</option>
            <option value="active">{t('campaignOps.statusName.active')}</option>
          </Select>
        )}
        <Textarea label={t('proposals.reason')} value={reason} onChange={(e) => setReason(e.target.value)} data-testid="ops-reason" />
      </div>
    </Modal>
  )
}

const HEALTH_STATUS: Record<string, StatusKey> = { healthy: 'healthy', attention: 'attention', risk: 'risk', idle: 'pending' }

/** "View tracking health": can the numbers of this campaign be believed? */
function TrackingDrawer({ row, onClose }: { row: OperationsRow | null; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['campaign-tracking', row?.id], queryFn: () => fetchCampaignTrackingHealth(row!.id), enabled: row !== null })

  return (
    <Drawer open={row !== null} onClose={onClose} title={row ? `${t('campaignOps.viewTracking')} — ${row.name}` : ''} size="md" testId="ops-tracking-drawer">
      {isLoading || !data ? <Skeleton className="h-48" /> : (
        <div className="space-y-4">
          <div className="flex items-center gap-3">
            <StatusPill status={HEALTH_STATUS[data.status] ?? 'pending'} />
            <span className="t-caption">{t(`campaignOps.trackingStatus.${data.status}`)}</span>
          </div>
          <div className="grid grid-cols-3 gap-2 text-center">
            <div className="rounded-xl bg-[var(--color-surface-muted)] p-2">
              <RingProgress size={64} thickness={7} value={data.leads.traced_percent ?? 0} tone={(data.leads.traced_percent ?? 0) >= 80 ? 'good' : (data.leads.traced_percent ?? 0) >= 60 ? 'warn' : 'bad'} label={t('campaignOps.traced')}>
                <span className="text-[0.8125rem] font-bold tabular-nums" dir="ltr">{data.leads.traced_percent === null ? '—' : `${data.leads.traced_percent}%`}</span>
              </RingProgress>
              <p className="t-caption mt-1">{t('campaignOps.traced')}</p>
            </div>
            <div className="rounded-xl bg-[var(--color-surface-muted)] p-2"><p className="t-metric" dir="ltr" data-testid="tracking-links">{formatNumber(data.links, i18n.language)}</p><p className="t-caption">{t('campaignOps.links')}</p></div>
            <div className="rounded-xl bg-[var(--color-surface-muted)] p-2"><p className="t-metric" dir="ltr">{formatNumber(data.conversions.sent, i18n.language)}<span className="t-caption">/{formatNumber(data.conversions.sent + data.conversions.failed + data.conversions.pending, i18n.language)}</span></p><p className="t-caption">{t('campaignOps.conversionsSent')}</p></div>
          </div>
          {data.platform && (
            <p className="t-small" data-testid="tracking-platform">
              {t(`platforms.${data.platform.key}`, { defaultValue: data.platform.key })} · {data.platform.connected ? t('campaignOps.connected') : t('campaignOps.notConnected')}
              {data.platform.success_rate !== null && ` · ${data.platform.success_rate}%`}
            </p>
          )}
          {data.issues.length === 0 ? <p className="t-caption" data-testid="tracking-clean">{t('campaignOps.noIssues')}</p> : (
            <ul className="space-y-1.5" data-testid="tracking-issues">
              {data.issues.map((issue) => <li key={issue} className="rounded-xl bg-[color-mix(in_srgb,var(--color-warning)_14%,var(--color-surface))] px-3 py-2 text-[0.9375rem]">{t(`campaignOps.issue.${issue}`)}</li>)}
            </ul>
          )}
          <Link to="/tracking" className="t-small font-medium text-[var(--brand)] underline">{t('campaignOps.openTracking')}</Link>
        </div>
      )}
    </Drawer>
  )
}
