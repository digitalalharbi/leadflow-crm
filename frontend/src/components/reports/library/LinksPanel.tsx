import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, ExternalLink, KeyRound, Mail, Plus, RefreshCw, ShieldOff } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Drawer, EmptyState, Input, Skeleton, Textarea } from '@/components/ui'
import { addLink, fetchLinks, regenerateLink, revokeLink, updateLink, type LinkInput, type ReportLink } from '@/features/reportLibrary'
import type { ReportShare, ShareStatus } from '@/features/reportShares'
import { useApiError } from '@/hooks/useApiError'
import { formatDate, formatLastSeen } from '@/lib/format'
import { useUi } from '@/stores/ui'

const TONE: Record<ShareStatus, 'success' | 'warning' | 'danger' | 'neutral'> = { active: 'success', expired: 'warning', revoked: 'danger', inactive: 'neutral' }

/** What protects a link, in one word. */
export function protectionOf(link: Pick<ReportLink, 'has_password' | 'restricted'>): 'none' | 'password' | 'otp' | 'both' {
  return link.has_password && link.restricted ? 'both' : link.has_password ? 'password' : link.restricted ? 'otp' : 'none'
}

const lines = (text: string) => text.split('\n').map((l) => l.trim()).filter(Boolean)

/**
 * The links a report has been given: one per client, each with its own password, end date, download permission and — on a
 * plan that includes it — a code sent to the client’s email. The address is short and says nothing about the workspace.
 */
export function LinksPanel({ share, onClose, canVerify }: { share: ReportShare | null; onClose: () => void; canVerify: boolean }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [editing, setEditing] = useState<ReportLink | 'new' | null>(null)
  const { data, isLoading } = useQuery({ queryKey: ['report-links', share?.id], queryFn: () => fetchLinks(share!.id), enabled: share !== null })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['report-links', share?.id] })
    void queryClient.invalidateQueries({ queryKey: ['report-shares'] })
    void queryClient.invalidateQueries({ queryKey: ['client-links'] })
  }
  const copy = async (url: string) => {
    try { await navigator.clipboard.writeText(url); toast(t('links.copied'), 'success') } catch { toast(url, 'info') }
  }
  const act = useMutation({
    mutationFn: ({ kind, link }: { kind: 'revoke' | 'regenerate'; link: string }) => (kind === 'revoke' ? revokeLink(share!.id, link) : regenerateLink(share!.id, link)),
    onSuccess: (_, { kind }) => { toast(t(`links.done.${kind}`), 'success'); refresh() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Drawer open={share !== null} onClose={onClose} title={t('links.title', { name: share?.name ?? '' })} size="lg" testId="links-panel"
      footer={<Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')} data-testid="link-new">{t('links.add')}</Button>}>
      <div className="space-y-3">
        <p className="t-caption">{t('links.hint')}</p>
        {isLoading || !data ? <Skeleton className="h-32" /> : data.links.length === 0 ? (
          <EmptyState title={t('links.empty')} />
        ) : (
          <ul className="space-y-2" data-testid="link-list">
            {data.links.map((link) => (
              <li key={link.id} className="space-y-2 rounded-xl border border-[var(--color-line)] p-3" data-testid={`link-${link.id}`} data-status={link.status}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="t-small font-semibold">{link.client_name || link.label || t('clientAccess.noClient')}</p>
                  <div className="flex flex-wrap items-center gap-1.5">
                    <Badge tone={TONE[link.status]} testId="link-status">{t(`links.status.${link.status}`)}</Badge>
                    <Badge tone="neutral"><KeyRound className="size-3" aria-hidden />{t(`links.protection.${protectionOf(link)}`)}</Badge>
                  </div>
                </div>
                <p className="t-caption break-all" dir="ltr" data-testid="link-url">{link.url}</p>
                <p className="t-caption">
                  {t('links.views', { n: link.view_count })} · {link.last_viewed_at ? t('links.lastViewed', { when: formatLastSeen(link.last_viewed_at, i18n.language) }) : t('links.neverViewed')}
                  {link.expires_at ? ` · ${t('links.expires')} ${formatDate(link.expires_at, i18n.language)}` : ''}
                </p>
                <div className="flex flex-wrap gap-1.5">
                  <Button size="sm" icon={<Copy className="size-3.5" aria-hidden />} onClick={() => void copy(link.url)} disabled={link.status === 'revoked'} data-testid="link-copy">{t('links.copy')}</Button>
                  <a href={link.url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 rounded-lg border border-[var(--color-border)] px-2 py-1 text-[0.8125rem]" data-testid="link-open"><ExternalLink className="size-3.5" aria-hidden />{t('links.open')}</a>
                  <Button size="sm" variant="secondary" onClick={() => setEditing(link)} data-testid="link-edit">{t('library.action.edit')}</Button>
                  <Button size="sm" variant="outline" icon={<RefreshCw className="size-3.5" aria-hidden />} loading={act.isPending} onClick={() => act.mutate({ kind: 'regenerate', link: link.id })} data-testid="link-regenerate">{t('links.regenerate')}</Button>
                  {link.status !== 'revoked' && <Button size="sm" variant="outline" icon={<ShieldOff className="size-3.5" aria-hidden />} loading={act.isPending} onClick={() => act.mutate({ kind: 'revoke', link: link.id })} data-testid="link-revoke">{t('links.revoke')}</Button>}
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>

      {share && editing !== null && (
        <LinkForm key={editing === 'new' ? 'new' : editing.id} shareId={share.id} link={editing === 'new' ? null : editing} canVerify={canVerify} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); refresh() }} />
      )}
    </Drawer>
  )
}

function LinkForm({ shareId, link, canVerify, onClose, onSaved }: { shareId: string; link: ReportLink | null; canVerify: boolean; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [client, setClient] = useState(link?.client_name ?? '')
  const [email, setEmail] = useState(link?.client_email ?? '')
  const [password, setPassword] = useState('')
  const [removePassword, setRemovePassword] = useState(false)
  const [expires, setExpires] = useState(link?.expires_at?.slice(0, 10) ?? '')
  const [allowExport, setAllowExport] = useState(link?.allow_export ?? false)
  const [otp, setOtp] = useState(link?.require_otp ?? false)
  const [emails, setEmails] = useState((link?.allowed_emails ?? []).join('\n'))
  const [domains, setDomains] = useState((link?.allowed_domains ?? []).join('\n'))

  const save = useMutation({
    mutationFn: () => {
      const payload: LinkInput = {
        client_name: client || null, client_email: email || null,
        expires_at: expires ? new Date(`${expires}T23:59:59`).toISOString() : null,
        allow_export: allowExport,
        ...(password ? { password } : removePassword ? { password: null } : {}),
        ...(canVerify ? { require_otp: otp, allowed_emails: lines(emails), allowed_domains: lines(domains) } : {}),
      }

      return link ? updateLink(shareId, link.id, payload) : addLink(shareId, payload)
    },
    onSuccess: () => { toast(t(link ? 'links.done.save' : 'links.done.add'), 'success'); onSaved() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Drawer open onClose={onClose} title={link ? t('library.action.edit') : t('links.add')} size="md" testId="link-form"
      footer={<Button loading={save.isPending} onClick={() => save.mutate()} data-testid="link-save">{t('common.save')}</Button>}>
      <div className="space-y-4">
        <Input label={t('links.clientName')} value={client} data-testid="link-client" onChange={(e) => setClient(e.target.value)} />
        <Input type="email" label={t('links.clientEmail')} hint={t('common.optional')} value={email} data-testid="link-email" onChange={(e) => setEmail(e.target.value)} />
        <Input type="text" label={t('links.password')} hint={link?.has_password ? t('links.passwordKeep') : t('common.optional')} value={password} autoComplete="off" data-testid="link-password" onChange={(e) => { setPassword(e.target.value); setRemovePassword(false) }} />
        {link?.has_password && (
          <label className="t-caption flex items-center gap-2"><input type="checkbox" checked={removePassword} data-testid="link-remove-password" onChange={(e) => { setRemovePassword(e.target.checked); if (e.target.checked) setPassword('') }} className="size-4 accent-[var(--brand)]" />{t('share.removePassword')}</label>
        )}
        <Input type="date" label={t('links.expires')} hint={t('common.optional')} value={expires} data-testid="link-expires" onChange={(e) => setExpires(e.target.value)} />
        <label className="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-2 py-1.5 hover:bg-[var(--color-surface-muted)]">
          <span className="t-small">{t('links.allowExport')}</span>
          <input type="checkbox" role="switch" checked={allowExport} data-testid="link-allow-export" onChange={(e) => setAllowExport(e.target.checked)} className="size-4 accent-[var(--brand)]" />
        </label>

        <fieldset className="space-y-3 rounded-xl border border-[var(--color-line)] p-3" disabled={!canVerify} data-testid="link-otp-box">
          <legend className="t-small flex items-center gap-1.5 px-1 font-medium"><Mail className="size-4" aria-hidden />{t('links.requireOtp')}</legend>
          {!canVerify && <p className="t-caption" data-testid="link-otp-locked">{t('links.otpLocked')}</p>}
          <label className="flex cursor-pointer items-center justify-between gap-3">
            <span className="t-small">{t('links.requireOtp')}</span>
            <input type="checkbox" role="switch" checked={otp} data-testid="link-require-otp" onChange={(e) => setOtp(e.target.checked)} className="size-4 accent-[var(--brand)]" />
          </label>
          <Textarea label={t('links.allowedEmails')} value={emails} rows={3} dir="ltr" data-testid="link-allowed-emails" onChange={(e) => setEmails(e.target.value)} />
          <Textarea label={t('links.allowedDomains')} value={domains} rows={2} dir="ltr" data-testid="link-allowed-domains" onChange={(e) => setDomains(e.target.value)} />
        </fieldset>
      </div>
    </Drawer>
  )
}
