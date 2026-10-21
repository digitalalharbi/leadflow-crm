import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, Eye, KeyRound, Link2, Plus, RefreshCw, ShieldOff, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardBody, Drawer, EmptyState, ErrorState, Input, Select, Skeleton } from '@/components/ui'
import {
  createShare, deleteShare, fetchShares, MONEY_SECTIONS, REPORT_SECTIONS, REPORT_TEMPLATES, regenerateShare, revokeShare, updateShare,
  type ReportSection, type ReportShare, type ReportTemplate, type ShareStatus,
} from '@/features/reportShares'
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

              <div className="flex flex-wrap gap-1.5">
                <Button size="sm" icon={<Copy className="size-3.5" aria-hidden />} onClick={() => void copy(share.url)} disabled={share.status === 'revoked'} data-testid="share-copy">{t('share.copy')}</Button>
                <Button size="sm" variant="secondary" onClick={() => setEditing(share)} data-testid="share-edit">{t('share.edit')}</Button>
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

      <ShareBuilder
        key={typeof editing === 'object' && editing ? editing.id : 'new'}
        open={editing !== null}
        share={editing === 'new' ? null : editing}
        templates={data.templates}
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
function ShareBuilder({ open, share, templates, onClose, onSaved }: {
  open: boolean
  share: ReportShare | null
  templates: { key: ReportTemplate; sections: Record<ReportSection, boolean> }[]
  onClose: () => void
  onSaved: (share: ReportShare) => void
}) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [name, setName] = useState(share?.name ?? '')
  const [template, setTemplate] = useState<ReportTemplate>(share?.template ?? 'executive')
  const [sections, setSections] = useState<Record<ReportSection, boolean>>(share?.sections ?? templates.find((x) => x.key === 'executive')!.sections)
  const [days, setDays] = useState(String(share?.days ?? 30))
  const [password, setPassword] = useState('')
  const [removePassword, setRemovePassword] = useState(false)
  const [expires, setExpires] = useState(share?.expires_at?.slice(0, 10) ?? '')
  const [partnerId, setPartnerId] = useState(share?.partner?.id ?? '')
  const { data: partners } = useQuery({ queryKey: ['partners-simple'], queryFn: () => fetchPartners(30), enabled: open, select: (d) => d.partners })

  const pick = (key: ReportTemplate) => {
    setTemplate(key)
    setSections(templates.find((x) => x.key === key)!.sections)
  }

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        name,
        template,
        sections,
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

  const money = REPORT_SECTIONS.filter((s) => sections[s] && MONEY_SECTIONS.includes(s))

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
          <ul className="space-y-1" data-testid="share-sections">
            {REPORT_SECTIONS.map((section) => (
              <li key={section}>
                <label className="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-2 py-1.5 hover:bg-[var(--color-surface-muted)]">
                  <span className="t-small flex items-center gap-1.5">
                    {t(`share.section.${section}`)}
                    {MONEY_SECTIONS.includes(section) && <Badge tone="warning">{t('share.money')}</Badge>}
                  </span>
                  <input
                    type="checkbox"
                    role="switch"
                    checked={sections[section]}
                    data-testid={`share-section-${section}`}
                    onChange={(e) => setSections((prev) => ({ ...prev, [section]: e.target.checked }))}
                    className="size-4 accent-[var(--brand)]"
                  />
                </label>
              </li>
            ))}
          </ul>
          {money.length > 0 && <p className="t-caption text-[var(--color-warning)]" data-testid="share-money-warning">{t('share.moneyWarning')}</p>}
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
