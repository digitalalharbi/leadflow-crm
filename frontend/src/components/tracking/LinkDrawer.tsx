import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '@/lib/api'
import { Button, Drawer, Input, Select, Skeleton } from '@/components/ui'
import { fetchProjects } from '@/features/leads'
import { CHANNELS, createLink, deleteLink, fetchLink, updateLink, type Channel, type LinkInput } from '@/features/tracking'
import { formatRelative } from '@/lib/format'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'

const BLANK: LinkInput = { name: '', channel: 'meta', kind: 'link', destination_url: '' }

/**
 * Make or open a link. New: a name, a channel, a project and where it goes — the rest is optional
 * and folded away. Existing: the numbers, the two things to copy, and the last events it recorded.
 */
export function LinkDrawer({ open, linkId, onClose, canEdit }: { open: boolean; linkId: string | null; onClose: () => void; canEdit: boolean }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [form, setForm] = useState<LinkInput>(BLANK)
  const [error, setError] = useState<string | null>(null)

  const { data: projects } = useQuery({ queryKey: ['projects-simple'], queryFn: fetchProjects, enabled: open })
  const { data: detail, isLoading } = useQuery({ queryKey: ['tracking-link', linkId], queryFn: () => fetchLink(linkId!), enabled: open && linkId !== null })

  useEffect(() => {
    if (!open) return
    setError(null)
    if (linkId === null) setForm(BLANK)
  }, [open, linkId])

  useEffect(() => {
    const l = detail?.link
    if (l) setForm({ name: l.name, channel: l.channel, kind: l.kind, project_id: l.project?.id ?? null, destination_url: l.destination_url ?? '', utm_source: l.utm.source ?? '', utm_medium: l.utm.medium ?? '', utm_campaign: l.utm.campaign ?? '', is_active: l.is_active })
  }, [detail])

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['tracking-links'] })
    void queryClient.invalidateQueries({ queryKey: ['tracking-link'] })
  }

  const clean = (): LinkInput => ({ ...form, destination_url: form.destination_url || null, project_id: form.project_id || null, utm_source: form.utm_source || null, utm_medium: form.utm_medium || null, utm_campaign: form.utm_campaign || null })

  const save = useMutation({
    mutationFn: () => (linkId ? updateLink(linkId, clean()) : createLink(clean())),
    onSuccess: () => {
      refresh()
      toast(t('common.saved'), 'success')
      onClose()
    },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.errors) setError(Object.values(caught.errors)[0]?.[0] ?? t('errors.validation'))
      else toast(translateError(caught), 'error')
    },
  })

  const remove = useMutation({
    mutationFn: () => deleteLink(linkId!),
    onSuccess: () => {
      refresh()
      onClose()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const copy = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text)
      toast(t('tracking.copied'), 'success')
    } catch {
      toast(text, 'info')
    }
  }

  const text = (key: keyof LinkInput) => ({ value: (form[key] ?? '') as string, onChange: (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value })) })
  const link = detail?.link

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={linkId ? t('tracking.edit') : t('tracking.new')}
      testId="link-drawer"
      size="lg"
      footer={
        canEdit ? (
          <>
            {linkId && <Button variant="ghost" icon={<Trash2 className="size-4" />} loading={remove.isPending} onClick={() => remove.mutate()} data-testid="delete-link">{t('common.delete')}</Button>}
            <Button onClick={() => save.mutate()} loading={save.isPending} disabled={!form.name} data-testid="save-link">{t('common.save')}</Button>
          </>
        ) : undefined
      }
    >
      {linkId && isLoading ? <Skeleton className="h-40" /> : (
        <div className="space-y-5">
          {link && detail && (
            <section className="space-y-2" data-testid="link-embed">
              <div className="flex items-center gap-2">
                <code className="min-w-0 flex-1 truncate rounded-lg bg-[var(--color-surface-muted)] px-3 py-1.5 text-[0.875rem]" dir="ltr">{detail.embed.link}</code>
                <Button size="sm" variant="outline" icon={<Copy className="size-4" />} onClick={() => void copy(detail.embed.link)} data-testid="copy-embed-link">{t('tracking.copy')}</Button>
              </div>
              <div className="flex items-center gap-2">
                <code className="min-w-0 flex-1 truncate rounded-lg bg-[var(--color-surface-muted)] px-3 py-1.5 text-[0.875rem]" dir="ltr">{detail.embed.script}</code>
                <Button size="sm" variant="outline" icon={<Copy className="size-4" />} onClick={() => void copy(detail.embed.script)} data-testid="copy-embed-script">{t('tracking.script')}</Button>
              </div>
            </section>
          )}

          <div className="grid gap-4 sm:grid-cols-2" data-testid="link-form">
            <Input label={t('tracking.name')} data-testid="link-name" {...text('name')} disabled={!canEdit} />
            <Select label={t('tracking.channel')} data-testid="link-channel" value={form.channel ?? 'other'} disabled={!canEdit} onChange={(e) => setForm((f) => ({ ...f, channel: e.target.value as Channel }))}>
              {CHANNELS.map((c) => <option key={c} value={c}>{t(`tracking.channels.${c}`)}</option>)}
            </Select>
            <Select label={t('tracking.project')} data-testid="link-project" value={form.project_id ?? ''} disabled={!canEdit} onChange={(e) => setForm((f) => ({ ...f, project_id: e.target.value || null }))}>
              <option value="">—</option>
              {(projects ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </Select>
            <Select label={t('tracking.kind')} data-testid="link-kind" value={form.kind ?? 'link'} disabled={!canEdit || linkId !== null} onChange={(e) => setForm((f) => ({ ...f, kind: e.target.value as 'link' | 'qr' }))}>
              <option value="link">{t('tracking.kinds.link')}</option>
              <option value="qr">{t('tracking.kinds.qr')}</option>
            </Select>
            <div className="sm:col-span-2">
              <Input label={t('tracking.destination')} dir="ltr" placeholder="https://" data-testid="link-destination" {...text('destination_url')} disabled={!canEdit} />
            </div>
            <Input label="utm_source" dir="ltr" {...text('utm_source')} disabled={!canEdit} />
            <Input label="utm_medium" dir="ltr" {...text('utm_medium')} disabled={!canEdit} />
            <Input label="utm_campaign" dir="ltr" {...text('utm_campaign')} disabled={!canEdit} />
            {linkId && (
              <Select label={t('tracking.status')} data-testid="link-active" value={form.is_active === false ? '0' : '1'} disabled={!canEdit} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === '1' }))}>
                <option value="1">{t('tracking.live')}</option>
                <option value="0">{t('tracking.off')}</option>
              </Select>
            )}
          </div>
          {error && <p role="alert" className="text-[0.9375rem] font-semibold text-[var(--color-danger)]" data-testid="link-error">{error}</p>}

          {detail && detail.recent.length > 0 && (
            <section data-testid="link-recent">
              <h4 className="mb-2 text-[1rem] font-extrabold">{t('tracking.recent')}</h4>
              <ul className="space-y-1">
                {detail.recent.slice(0, 8).map((event, i) => (
                  <li key={i} className="flex items-center justify-between gap-2 rounded-lg bg-[var(--color-surface-muted)] px-3 py-1.5 text-[0.9375rem]">
                    <span className="font-semibold">{t(`tracking.event.${event.type}`, { defaultValue: event.type })}</span>
                    <span className="text-[var(--color-ink-subtle)]">{[event.device, event.browser].filter(Boolean).join(' · ')}</span>
                    <span className="text-[var(--color-ink-subtle)]">{formatRelative(event.at, i18n.language)}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>
      )}
    </Drawer>
  )
}
