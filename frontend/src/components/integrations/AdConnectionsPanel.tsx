import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Check, CircleSlash, Link2, Plug, ShieldCheck, Unplug, X as XIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, Drawer, Input, Skeleton } from '@/components/ui'
import { platformColor } from '@/components/charts'
import { ConversionHealthPanel } from './ConversionHealthPanel'
import {
  AD_PLATFORMS, LADDER, disconnectAdConnection, saveAdConnection, testAdConnection, useAdConnection, type AdConnectionView,
} from '@/features/adConnections'
import type { AdPlatformKey } from '@/features/campaigns'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { formatRelative } from '@/lib/format'
import { cn } from '@/lib/cn'

const ACTIONS = ['pause', 'resume', 'update_budget', 'duplicate_ad_set', 'create_campaign', 'create_ad', 'update_creative', 'update_targeting', 'schedule'] as const

/**
 * Ad accounts for campaign management — one card per platform.
 *
 * The ladder is the card's headline: six dots, filled as far as the platform
 * has actually been proven, never further. Underneath, every action the product
 * knows, ticked or crossed for this platform, so nobody discovers in an approval
 * that the platform never allowed it.
 */
export function AdConnectionsPanel() {
  const { t } = useTranslation()
  const [open, setOpen] = useState<AdPlatformKey | null>(null)

  return (
    <section data-testid="ad-connections">
      <h2 className="t-card-title mb-2 flex items-center gap-1.5 px-1">
        <Plug className="size-4 text-[var(--brand)]" aria-hidden />
        {t('adConnections.title')}
      </h2>
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {AD_PLATFORMS.map((p) => <PlatformCard key={p} platform={p} onOpen={() => setOpen(p)} />)}
      </div>
      {open && <ConnectDrawer platform={open} onClose={() => setOpen(null)} />}
      <div className="mt-4"><ConversionHealthPanel /></div>
    </section>
  )
}

function Ladder({ state }: { state: AdConnectionView['state'] }) {
  const { t } = useTranslation()
  const reached = LADDER.indexOf(state)

  return (
    <div className="flex items-center gap-1" title={t(`integrationState.hint.${state}`)} data-testid="ladder" data-state={state}>
      {LADDER.slice(1).map((rung, i) => (
        <span key={rung} className={cn('h-1.5 flex-1 rounded-full', i < reached ? 'bg-[var(--brand)]' : 'bg-[var(--color-chrome-fill)]')} aria-hidden />
      ))}
    </div>
  )
}

function PlatformCard({ platform, onOpen }: { platform: AdPlatformKey; onOpen: () => void }) {
  const { t, i18n } = useTranslation()
  const { data } = useAdConnection(platform)

  if (!data) return <Skeleton className="h-44" />

  const supported = ACTIONS.filter((a) => data.capabilities[a]?.supported).length

  return (
    <Card data-testid={`ad-connection-${platform}`}>
      <CardBody className="space-y-3">
        <div className="flex items-center gap-2.5">
          <span className="grid size-10 place-items-center rounded-full text-white" style={{ background: platformColor(platform) }} aria-hidden>
            <Link2 className="size-4" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="text-[1.0625rem] font-semibold">{t(`platforms.${platform}`)}</p>
            <p className="text-[0.875rem] text-[var(--color-ink-muted)]">{t(`integrationState.${data.state}`)}{data.mode === 'sandbox' ? ` · ${t('adConnections.sandbox')}` : ''}</p>
          </div>
          <Button size="sm" variant={data.filled.length > 0 ? 'outline' : 'primary'} onClick={onOpen} data-testid={`connect-${platform}`}>
            {data.filled.length > 0 ? t('adConnections.manage') : t('adConnections.connect')}
          </Button>
        </div>

        <Ladder state={data.state} />

        <div className="flex flex-wrap gap-1" aria-label={t('adConnections.actions')}>
          {ACTIONS.map((a) => {
            const ok = data.capabilities[a]?.supported
            return (
              <span
                key={a}
                title={ok ? undefined : t(`adConnections.reason.${data.capabilities[a]?.reason ?? 'not_built'}`, { defaultValue: '' })}
                className={cn('inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-[0.8125rem]', ok ? 'bg-[var(--brand-soft)] text-[var(--brand)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-subtle)] line-through decoration-1')}
                data-supported={ok ? 'yes' : 'no'}
              >
                {ok ? <Check className="size-2.5" aria-hidden /> : <CircleSlash className="size-2.5" aria-hidden />}
                {t(`proposals.action.${a}`)}
              </span>
            )
          })}
        </div>

        <p className="flex items-center justify-between text-[0.8125rem] text-[var(--color-ink-subtle)]">
          <span>{t('adConnections.supported', { count: supported, total: ACTIONS.length })}</span>
          {data.last_sync_ok_at && <span>{t('adConnections.synced', { when: formatRelative(data.last_sync_ok_at, i18n.language) })}</span>}
        </p>
        {data.last_error && <p className="truncate text-[0.8125rem] text-[var(--color-danger)]" title={data.last_error}>{data.last_error}</p>}
      </CardBody>
    </Card>
  )
}

function ConnectDrawer({ platform, onClose }: { platform: AdPlatformKey; onClose: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const { data } = useAdConnection(platform)
  const [values, setValues] = useState<Record<string, string>>({})
  const [accounts, setAccounts] = useState<string>('')
  const [mode, setMode] = useState<'live' | 'sandbox' | null>(null)

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['ad-connection', platform] })
    void queryClient.invalidateQueries({ queryKey: ['campaign-platforms'] })
  }

  const save = useMutation({
    mutationFn: async () => {
      const secretKeys = new Set([...(data?.required ?? []), 'app_id', 'secret', 'refresh_token', 'client_id', 'client_secret', 'login_customer_id'])
      const credentials = Object.fromEntries(Object.entries(values).filter(([k, v]) => secretKeys.has(k) && v.trim() !== ''))
      const ids = accounts.split(/[,\s]+/).map((s) => s.trim()).filter(Boolean)
      await saveAdConnection(platform, { credentials, config: ids.length ? { ...data?.config, account_ids: ids } : data?.config, mode: mode ?? data?.mode })
      return testAdConnection(platform)
    },
    onSuccess: (result) => {
      refresh()
      toast(result.ok ? t('adConnections.testOk', { count: result.accounts ?? 0 }) : (result.error ?? t('adConnections.testFailed')), result.ok ? 'success' : 'error')
      if (result.ok) onClose()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const disconnect = useMutation({
    mutationFn: () => disconnectAdConnection(platform),
    onSuccess: () => {
      refresh()
      onClose()
    },
  })

  const fields = [...(data?.required ?? []), ...(data?.optional ?? []).filter((k) => ['app_id', 'secret', 'refresh_token', 'client_id', 'client_secret', 'login_customer_id'].includes(k))]

  return (
    <Drawer
      open
      onClose={onClose}
      title={t('adConnections.drawerTitle', { platform: t(`platforms.${platform}`) })}
      description={t('adConnections.drawerHint')}
      testId="ad-connection-drawer"
      footer={
        <>
          {(data?.filled.length ?? 0) > 0 && !data?.stand_in && (
            <Button variant="ghost" icon={<Unplug className="size-4" />} loading={disconnect.isPending} onClick={() => disconnect.mutate()}>{t('adConnections.disconnect')}</Button>
          )}
          <Button variant="ghost" icon={<XIcon className="size-4" />} onClick={onClose}>{t('common.cancel')}</Button>
          <Button icon={<ShieldCheck className="size-4" />} loading={save.isPending} onClick={() => save.mutate()} data-testid="ad-connection-save">{t('adConnections.saveAndTest')}</Button>
        </>
      }
    >
      {data && (
        <div className="space-y-4">
          {fields.map((key) => (
            <Input
              key={key}
              label={t(`adConnections.field.${key}`, { defaultValue: key })}
              type="password"
              autoComplete="off"
              dir="ltr"
              placeholder={data.filled.includes(key) ? '••••••••' : ''}
              value={values[key] ?? ''}
              onChange={(e) => setValues((v) => ({ ...v, [key]: e.target.value }))}
              data-testid={`field-${key}`}
            />
          ))}
          <Input
            label={t('adConnections.field.account_ids')}
            hint={t('adConnections.accountsHint')}
            dir="ltr"
            value={accounts || ((data.config.account_ids as string[] | undefined) ?? []).join(', ')}
            onChange={(e) => setAccounts(e.target.value)}
            data-testid="field-account_ids"
          />
          <div className="flex gap-2" role="radiogroup" aria-label={t('adConnections.mode')}>
            {(['live', 'sandbox'] as const).map((m) => (
              <button
                key={m}
                type="button"
                role="radio"
                aria-checked={(mode ?? data.mode) === m}
                onClick={() => setMode(m)}
                className={cn('flex-1 rounded-[var(--radius-control)] border px-3 py-2 text-[0.9375rem]', (mode ?? data.mode) === m ? 'border-[var(--brand)] bg-[var(--brand-soft)] text-[var(--brand)]' : 'border-[var(--color-line)]')}
              >
                {t(`adConnections.${m}`)}
              </button>
            ))}
          </div>
        </div>
      )}
    </Drawer>
  )
}
