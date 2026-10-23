import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Drawer, EmptyState, ErrorState, Input, Select, Skeleton } from '@/components/ui'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useApiError } from '@/hooks/useApiError'
import { createDestination, deleteDestination, fetchDestinationCatalogue, fetchDestinations, updateDestination, type Destination, type DestinationInput } from '@/features/hub'
import { formatRelative } from '@/lib/format'
import { useUi } from '@/stores/ui'

const TONE: Record<string, string> = {
  ready: 'bg-[color-mix(in_srgb,var(--color-success)_16%,transparent)] text-[var(--color-success)]',
  browser_only: 'bg-[color-mix(in_srgb,var(--color-warning)_18%,transparent)] text-[var(--color-warning)]',
  awaiting_credentials: 'bg-[color-mix(in_srgb,var(--color-warning)_18%,transparent)] text-[var(--color-warning)]',
  not_configured: 'bg-[var(--color-surface-muted)] text-[var(--color-ink-muted)]',
  disabled: 'bg-[var(--color-surface-muted)] text-[var(--color-ink-muted)]',
}

export function ReadinessBadge({ readiness, enabled, testId }: { readiness: string; enabled: boolean; testId?: string }) {
  const { t } = useTranslation()
  const state = enabled ? readiness : 'disabled'

  return <span className={cn('inline-flex rounded-full px-2 py-0.5 text-[0.8125rem] font-bold', TONE[state] ?? TONE.not_configured)} data-testid={testId} data-state={state}>{t(`hub.readiness.${state as 'ready'}`, { defaultValue: state })}</span>
}

/**
 * Where events can go: the analytics and ad platforms of the workspace. Each shows whether it is ready to receive, what it has
 * been sent this week and what failed. A credential is written here and never read back — only that one is set.
 */
export function DestinationsPanel({ canEdit }: { canEdit: boolean }) {
  const { t, i18n } = useTranslation()
  const [editing, setEditing] = useState<string | null | undefined>(undefined)
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['tracking-destinations'], queryFn: fetchDestinations })

  return (
    <div className="space-y-3" data-testid="destinations-panel">
      <div className="flex items-center justify-between gap-2">
        <p className="text-[0.9375rem] text-[var(--color-ink-muted)]">{t('hub.destinations.intro')}</p>
        {canEdit && <Button icon={<Plus className="size-4" />} onClick={() => setEditing(null)} data-testid="new-destination">{t('hub.destinations.new')}</Button>}
      </div>
      {isLoading ? <Skeleton className="h-40" /> : isError ? <ErrorState onRetry={() => void refetch()} /> : (data ?? []).length === 0 ? (
        <EmptyState title={t('hub.destinations.emptyTitle')} body={t('hub.destinations.emptyBody')} />
      ) : (
        <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid="destination-list">
          {data!.map((d) => (
            <li key={d.id} className="rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]" data-testid={`destination-${d.id}`}>
              <button type="button" className="pressable w-full text-start" onClick={() => setEditing(d.id)} data-testid={`open-destination-${d.id}`}>
                <span className="flex items-start justify-between gap-2">
                  <span className="min-w-0"><span className="block truncate text-[1.0625rem] font-extrabold">{d.name}</span><span className="block text-[0.875rem] text-[var(--color-ink-subtle)]">{d.label}{d.project ? ` · ${d.project.name}` : ''}</span></span>
                  <ReadinessBadge readiness={d.readiness} enabled={d.is_enabled} testId={`readiness-${d.id}`} />
                </span>
                <span className="mt-2 block text-[0.8125rem] text-[var(--color-ink-muted)]">
                  {[d.browser ? t('hub.destinations.browser') : null, d.server ? t('hub.destinations.server') : null].filter(Boolean).join(' + ')}
                  {d.last_event_at ? ` · ${formatRelative(d.last_event_at, i18n.language)}` : ''}
                </span>
                {d.last_error && <span className="mt-1 block truncate text-[0.8125rem] text-[var(--color-status-lost)]" data-testid={`error-${d.id}`}>{d.last_error}</span>}
              </button>
            </li>
          ))}
        </ul>
      )}
      <DestinationEditor open={editing !== undefined} id={editing ?? null} destinations={data ?? []} canEdit={canEdit} onClose={() => setEditing(undefined)} />
    </div>
  )
}

const BLANK: DestinationInput & { kind: string } = { kind: 'ga4', name: '', is_enabled: false, config: {}, credentials: {}, events: [], send_value: true, hash_identifiers: true, consent_mode: 'required' }

function DestinationEditor({ open, id, destinations, canEdit, onClose }: { open: boolean; id: string | null; destinations: Destination[]; canEdit: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [form, setForm] = useState(BLANK)
  const [error, setError] = useState<string | null>(null)
  const { data: catalogue } = useQuery({ queryKey: ['destination-catalogue'], queryFn: fetchDestinationCatalogue, enabled: open })
  const current = id ? destinations.find((d) => d.id === id) : undefined
  const kind = catalogue?.kinds.find((k) => k.kind === form.kind)

  useEffect(() => {
    if (!open) return
    setError(null)
    setForm(current ? { kind: current.kind, name: current.name, is_enabled: current.is_enabled, config: current.config, credentials: {}, events: current.events, send_value: current.send_value, hash_identifiers: current.hash_identifiers, consent_mode: current.consent_mode } : { ...BLANK, events: catalogue?.default_events ?? [] })
  }, [open, current, catalogue?.default_events])

  const save = useMutation({
    mutationFn: () => {
      const { kind: k, ...rest } = form
      // A credential left blank is "keep the one held": only what was typed is sent.
      const credentials = Object.fromEntries(Object.entries(form.credentials ?? {}).filter(([, v]) => v !== ''))
      return id ? updateDestination(id, { ...rest, credentials }) : createDestination({ ...rest, kind: k, credentials })
    },
    onSuccess: () => { void queryClient.invalidateQueries({ queryKey: ['tracking-destinations'] }); toast(t('common.saved'), 'success'); onClose() },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.errors) setError(Object.values(caught.errors).flat().join(' · '))
      else toast(translateError(caught), 'error')
    },
  })

  const remove = useMutation({
    mutationFn: () => deleteDestination(id!),
    onSuccess: () => { void queryClient.invalidateQueries({ queryKey: ['tracking-destinations'] }); onClose() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const toggleEvent = (e: string) => setForm((f) => ({ ...f, events: f.events?.includes(e) ? f.events.filter((x) => x !== e) : [...(f.events ?? []), e] }))

  return (
    <Drawer open={open} onClose={onClose} title={id ? t('hub.destinations.edit') : t('hub.destinations.new')} testId="destination-editor" size="lg"
      footer={canEdit ? (
        <>
          {id && <Button variant="ghost" icon={<Trash2 className="size-4" />} loading={remove.isPending} onClick={() => remove.mutate()} data-testid="delete-destination">{t('common.delete')}</Button>}
          <Button onClick={() => save.mutate()} loading={save.isPending} disabled={!form.name} data-testid="save-destination">{t('common.save')}</Button>
        </>
      ) : undefined}>
      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <Select label={t('hub.destinations.kind')} value={form.kind} onChange={(e) => setForm({ ...BLANK, kind: e.target.value, name: form.name, events: catalogue?.default_events ?? [] })} disabled={!canEdit || id !== null} data-testid="destination-kind">
            {(catalogue?.kinds ?? []).map((k) => <option key={k.kind} value={k.kind}>{k.label}</option>)}
          </Select>
          <Input label={t('hub.destinations.name')} value={form.name ?? ''} onChange={(e) => setForm({ ...form, name: e.target.value })} disabled={!canEdit} data-testid="destination-name" />
          {(kind?.config ?? []).map((key) => (
            <Input key={key} label={t(`hub.destinations.config.${key}`, { defaultValue: key })} dir="ltr" value={form.config?.[key] ?? ''} onChange={(e) => setForm({ ...form, config: { ...form.config, [key]: e.target.value } })} disabled={!canEdit} data-testid={`config-${key}`} />
          ))}
          {(kind?.credentials ?? []).map((key) => (
            <Input key={key} type="password" autoComplete="off" label={t(`hub.destinations.credential.${key}`, { defaultValue: key })} dir="ltr"
              hint={current?.credentials_set.includes(key) ? t('hub.destinations.credentialSet') : t('hub.destinations.credentialNotSet')}
              value={form.credentials?.[key] ?? ''} onChange={(e) => setForm({ ...form, credentials: { ...form.credentials, [key]: e.target.value } })} disabled={!canEdit} data-testid={`credential-${key}`} />
          ))}
        </div>

        <fieldset>
          <legend className="mb-1.5 font-semibold">{t('hub.destinations.events')}</legend>
          <div className="flex flex-wrap gap-2">
            {(catalogue?.events ?? []).map((e) => (
              <label key={e} className="flex min-h-9 cursor-pointer items-center gap-2 rounded-full border border-[var(--color-border)] px-3 text-[0.875rem] has-[:checked]:border-[var(--brand)] has-[:checked]:bg-[var(--brand-soft)]">
                <input type="checkbox" className="size-4" checked={form.events?.includes(e) ?? false} onChange={() => toggleEvent(e)} disabled={!canEdit} data-testid={`event-${e}`} /><span dir="ltr">{e}</span>
              </label>
            ))}
          </div>
        </fieldset>

        <div className="space-y-2">
          <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={form.send_value ?? true} onChange={(e) => setForm({ ...form, send_value: e.target.checked })} disabled={!canEdit} />{t('hub.destinations.sendValue')}</label>
          <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={form.hash_identifiers ?? true} onChange={(e) => setForm({ ...form, hash_identifiers: e.target.checked })} disabled={!canEdit} data-testid="hash-identifiers" />{t('hub.destinations.hash')}</label>
          <Select label={t('hub.destinations.consentMode')} value={form.consent_mode} onChange={(e) => setForm({ ...form, consent_mode: e.target.value as 'required' })} disabled={!canEdit} data-testid="consent-mode">
            <option value="required">{t('hub.destinations.consent.required')}</option>
            <option value="unless_denied">{t('hub.destinations.consent.unless_denied')}</option>
          </Select>
          <label className="flex items-center gap-2 text-[0.9375rem] font-semibold"><input type="checkbox" className="size-5" checked={form.is_enabled ?? false} onChange={(e) => setForm({ ...form, is_enabled: e.target.checked })} disabled={!canEdit} data-testid="destination-enabled" />{t('hub.destinations.enabled')}</label>
        </div>

        {current && <p className="t-caption text-[var(--color-ink-subtle)]" data-testid="destination-deliveries">{t('hub.destinations.week', { sent: current.deliveries_7d.sent ?? 0, failed: current.deliveries_7d.failed ?? 0, skipped: current.deliveries_7d.skipped ?? 0 })}</p>}
        {error && <p role="alert" className="text-[0.9375rem] text-[var(--color-status-lost)]" data-testid="destination-error">{error}</p>}
      </div>
    </Drawer>
  )
}
