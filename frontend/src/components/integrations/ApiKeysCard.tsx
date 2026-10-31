import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Badge, Button, Card, CardBody, CardHeader, Input, Skeleton } from '@/components/ui'
import { API_SCOPES, createApiKey, fetchApiKeys, revokeApiKey, type ApiScope } from '@/features/apiKeys'
import { formatDate } from '@/lib/format'

const STATUS_TONE = { active: 'success', expired: 'neutral', revoked: 'danger' } as const

/**
 * The company's keys to its own data through the public API.
 *
 * A new key is shown once, here, to be copied into the system that will use it; after that only its first characters
 * are ever shown. Revoking is immediate and kept in the audit trail.
 */
export function ApiKeysCard() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [name, setName] = useState('')
  const [scopes, setScopes] = useState<ApiScope[]>(['leads:read'])
  const [days, setDays] = useState('')
  const [plain, setPlain] = useState<string | null>(null)

  const { data, isLoading } = useQuery({ queryKey: ['api-keys'], queryFn: fetchApiKeys })
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['api-keys'] })

  const create = useMutation({
    mutationFn: () => createApiKey({ name: name.trim(), scopes, expires_in_days: days ? Number(days) : null }),
    onSuccess: (result) => {
      setPlain(result.plain)
      setName('')
      setDays('')
      refresh()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const revoke = useMutation({
    mutationFn: revokeApiKey,
    onSuccess: () => {
      toast(t('apiKeys.revoked'), 'success')
      refresh()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const toggle = (scope: ApiScope) => setScopes((current) => (current.includes(scope) ? current.filter((s) => s !== scope) : [...current, scope]))

  const copy = async () => {
    if (!plain) return
    try {
      await navigator.clipboard.writeText(plain)
      toast(t('apiKeys.copied'), 'success')
    } catch {
      // The key stays on screen to copy by hand.
    }
  }

  if (isLoading) return <Skeleton className="h-40" />

  return (
    <Card data-testid="api-keys">
      <CardHeader title={t('apiKeys.title')} description={t('apiKeys.hint')} />
      <CardBody>
        {plain && (
          <div role="alert" className="notice notice-warning mb-4" data-testid="api-key-plain">
            <p className="font-semibold">{t('apiKeys.showOnce')}</p>
            <code className="mt-2 block break-all rounded-md bg-[var(--color-surface-sunken)] p-2 font-mono text-[0.875rem]" dir="ltr">{plain}</code>
            <div className="mt-2 flex gap-2">
              <Button size="sm" variant="outline" onClick={() => void copy()}>{t('apiKeys.copy')}</Button>
              <Button size="sm" variant="ghost" onClick={() => setPlain(null)} data-testid="api-key-dismiss">{t('apiKeys.done')}</Button>
            </div>
          </div>
        )}

        <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem]">
          <Input label={t('apiKeys.name')} value={name} onChange={(e) => setName(e.target.value)} maxLength={120} data-testid="api-key-name" />
          <Input label={t('apiKeys.expiresInDays')} type="number" min={1} max={730} dir="ltr" value={days} onChange={(e) => setDays(e.target.value)} placeholder={t('apiKeys.never')} data-testid="api-key-days" />
        </div>

        <fieldset className="mt-3">
          <legend className="t-label mb-1.5">{t('apiKeys.scopes')}</legend>
          <div className="flex flex-wrap gap-x-4 gap-y-2">
            {API_SCOPES.map((scope) => (
              <label key={scope} className="inline-flex min-h-11 items-center gap-2 text-[0.9375rem]">
                <input type="checkbox" checked={scopes.includes(scope)} onChange={() => toggle(scope)} className="size-4 accent-[var(--brand)]" data-testid={`api-scope-${scope}`} />
                {t(`apiKeys.scope.${scope.replace(':', '_')}`)}
              </label>
            ))}
          </div>
        </fieldset>

        <div className="mt-3">
          <Button icon={<KeyRound className="size-4" />} loading={create.isPending} disabled={!name.trim() || scopes.length === 0} onClick={() => create.mutate()} data-testid="api-key-create">
            {t('apiKeys.create')}
          </Button>
        </div>

        {data && data.length > 0 ? (
          <ul className="mt-5 divide-y divide-[var(--color-line)]" data-testid="api-key-list">
            {data.map((key) => (
              <li key={key.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <div className="min-w-0">
                  <p className="font-semibold">{key.name} <code className="ms-1 font-mono text-[0.8125rem] text-[var(--color-ink-muted)]" dir="ltr">{key.prefix}…</code></p>
                  <p className="t-caption">
                    {key.scopes.map((scope) => t(`apiKeys.scope.${scope.replace(':', '_')}`)).join('، ')}
                    {' · '}
                    {key.last_used_at ? t('apiKeys.lastUsed', { date: formatDate(key.last_used_at, i18n.language) }) : t('apiKeys.neverUsed')}
                    {key.expires_at && ` · ${t('apiKeys.expires', { date: formatDate(key.expires_at, i18n.language) })}`}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone={STATUS_TONE[key.status]}>{t(`apiKeys.status.${key.status}`)}</Badge>
                  {key.status === 'active' && (
                    <Button size="sm" variant="ghost" loading={revoke.isPending && revoke.variables === key.id} onClick={() => revoke.mutate(key.id)} data-testid={`api-key-revoke-${key.id}`}>
                      {t('apiKeys.revoke')}
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <p className="t-caption mt-4">{t('apiKeys.empty')}</p>
        )}
      </CardBody>
    </Card>
  )
}
