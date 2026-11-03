import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Input, Select, Skeleton } from '@/components/ui'
import { apiGet, apiPatch, apiPost } from '@/lib/api'
import { formatCurrency, formatDate, formatNumber } from '@/lib/format'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import type { Feature } from '@/types/api'
import { ALL_FEATURES, LIMIT_KEYS, type PlatformInvoice, type PlatformPlan, type PlatformSubscription } from './types'

type Action = 'activate' | 'extend' | 'past_due' | 'suspend' | 'reactivate' | 'cancel'

/**
 * One company's subscription, run from the console: its plan, the lifecycle moves, seats bought, add-ons, the limits
 * set for it alone, and its invoices. Every change is a server call the platform trail records.
 */
export function SubscriptionManager({ tenantId }: { tenantId: string }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const lang = i18n.language

  const { data, isLoading } = useQuery({
    queryKey: ['platform-subscription', tenantId],
    queryFn: () => apiGet<{ subscription: PlatformSubscription; invoices: PlatformInvoice[] }>(`/super-admin/tenants/${tenantId}/subscription`),
  })
  const { data: catalogue } = useQuery({
    queryKey: ['platform-plans'],
    queryFn: () => apiGet<{ plans: PlatformPlan[] }>('/super-admin/plans'),
  })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['platform-subscription', tenantId] })
    void queryClient.invalidateQueries({ queryKey: ['platform-tenants'] })
    void queryClient.invalidateQueries({ queryKey: ['platform-dashboard'] })
    void queryClient.invalidateQueries({ queryKey: ['platform-tenant', tenantId] })
  }

  const move = useMutation({
    mutationFn: ({ action, body }: { action: Action; body?: Record<string, unknown> }) => apiPost(`/super-admin/tenants/${tenantId}/subscription/${action}`, body ?? {}),
    onSuccess: () => { refresh(); toast(t('settings.saved'), 'success') },
    onError: (caught) => toast(translateError(caught), 'error'),
  })
  const update = useMutation({
    mutationFn: (body: Record<string, unknown>) => apiPatch(`/super-admin/tenants/${tenantId}/subscription`, body),
    onSuccess: () => { refresh(); toast(t('settings.saved'), 'success') },
    onError: (caught) => toast(translateError(caught), 'error'),
  })
  const invoice = useMutation({
    mutationFn: (body: Record<string, unknown>) => apiPost(`/super-admin/tenants/${tenantId}/invoices`, body),
    onSuccess: () => { refresh(); toast(t('platformAdmin.invoiceRecorded'), 'success') },
    onError: (caught) => toast(translateError(caught), 'error'),
  })
  const invoiceMove = useMutation({
    mutationFn: ({ id, action }: { id: string; action: 'paid' | 'void' }) => apiPost(`/super-admin/invoices/${id}/${action}`),
    onSuccess: refresh,
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (isLoading || !data) return <Skeleton className="h-48" />

  const sub = data.subscription
  const plans = (catalogue?.plans ?? []).filter((plan) => plan.is_active)

  return (
    <div className="space-y-5" data-testid="subscription-manager">
      <section className="space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <Badge testId="sub-state" tone={sub.state === 'active' ? 'success' : sub.state === 'trialing' ? 'info' : sub.state === 'past_due' ? 'warning' : 'danger'}>
            {t(`billing.state.${sub.state}`)}
          </Badge>
          <span className="font-medium" data-testid="sub-plan">{sub.plan?.name ?? t('billing.noPlan')}</span>
          <span className="t-caption">
            {sub.monthly_value ? t('platformAdmin.worth', { value: formatCurrency(sub.monthly_value.amount, sub.monthly_value.currency, lang) }) : t('platformAdmin.notPriced')}
          </span>
        </div>
        <p className="t-caption">
          {sub.status === 'trialing' && sub.trial_ends_at ? t('platformAdmin.trialUntil', { date: formatDate(sub.trial_ends_at, lang) }) : null}
          {sub.status !== 'trialing' && sub.subscription_ends_at ? t('platformAdmin.paidUntil', { date: formatDate(sub.subscription_ends_at, lang) }) : null}
          {sub.status_reason ? ` · ${sub.status_reason}` : null}
        </p>
        <Lifecycle sub={sub} plans={plans} pending={move.isPending} onMove={(action, body) => move.mutate({ action, body })} />
      </section>

      {sub.usage && (
        <section>
          <h4 className="label-caps mb-2">{t('billing.usage')}</h4>
          <dl className="grid grid-cols-2 gap-2 text-[0.9375rem]">
            {Object.entries(sub.usage).map(([key, meter]) => (
              <div key={key} className="flex justify-between gap-2" data-testid={`platform-usage-${key}`} data-over={meter.over}>
                <dt className="text-[var(--color-ink-muted)]">{t(`billing.meters.${key}`)}</dt>
                <dd className="tabular-nums">{formatNumber(meter.used, lang)} / {meter.limit === null ? t('billing.unlimited') : formatNumber(meter.limit, lang)}</dd>
              </div>
            ))}
          </dl>
        </section>
      )}

      <Entitlements sub={sub} pending={update.isPending} onSave={(body) => update.mutate(body)} />

      <section>
        <h4 className="label-caps mb-2">{t('billing.invoices')}</h4>
        <ul className="divide-y divide-[var(--color-line)] rounded-[var(--radius-card)] border border-[var(--color-line)]" data-testid="platform-invoices">
          {data.invoices.length === 0 && <li className="px-3 py-2 t-caption">{t('billing.noInvoices')}</li>}
          {data.invoices.map((row) => (
            <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2 text-[0.9375rem]">
              <span className="font-medium">{row.number}</span>
              <span className="tabular-nums">{formatCurrency(Number(row.amount), row.currency, lang)}</span>
              <Badge tone={row.status === 'paid' ? 'success' : row.status === 'void' ? 'neutral' : 'info'}>{t(`billing.invoiceStatus.${row.status}`)}</Badge>
              <span className="ms-auto flex gap-1">
                {row.status !== 'paid' && row.status !== 'void' && <Button size="sm" variant="ghost" onClick={() => invoiceMove.mutate({ id: row.id, action: 'paid' })}>{t('platformAdmin.markPaid')}</Button>}
                {row.status !== 'void' && <Button size="sm" variant="ghost" onClick={() => invoiceMove.mutate({ id: row.id, action: 'void' })}>{t('platformAdmin.void')}</Button>}
              </span>
            </li>
          ))}
        </ul>
        <InvoiceForm currency={sub.monthly_value?.currency ?? 'SAR'} pending={invoice.isPending} onSubmit={(body) => invoice.mutate(body)} />
      </section>
    </div>
  )
}

function Lifecycle({ sub, plans, pending, onMove }: { sub: PlatformSubscription; plans: PlatformPlan[]; pending: boolean; onMove: (action: Action, body?: Record<string, unknown>) => void }) {
  const { t } = useTranslation()
  const [planId, setPlanId] = useState(sub.plan_id ?? plans[0]?.id ?? '')
  const [cycle, setCycle] = useState(sub.billing_cycle)
  const [days, setDays] = useState('30')
  const [reason, setReason] = useState('')
  const closed = sub.status === 'suspended' || sub.status === 'cancelled'

  return (
    <div className="space-y-3 rounded-[var(--radius-card)] bg-[var(--color-surface-sunken)] p-3">
      <div className="grid gap-2 sm:grid-cols-3">
        <Select label={t('billing.plan')} value={planId} onChange={(e) => setPlanId(e.target.value)} data-testid="activate-plan">
          {plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name}</option>)}
        </Select>
        <Select label={t('billing.cycle')} value={cycle} onChange={(e) => setCycle(e.target.value as 'monthly' | 'yearly')}>
          <option value="monthly">{t('billing.cycles.monthly')}</option>
          <option value="yearly">{t('billing.cycles.yearly')}</option>
        </Select>
        <div className="flex items-end">
          <Button size="sm" disabled={pending || !planId} data-testid="sub-activate" onClick={() => onMove('activate', { plan_id: planId, billing_cycle: cycle })}>{t('platformAdmin.activate')}</Button>
        </div>
      </div>
      <div className="flex flex-wrap items-end gap-2">
        <Input className="w-24" type="number" min={1} label={t('platformAdmin.days')} value={days} onChange={(e) => setDays(e.target.value)} />
        <Button size="sm" variant="secondary" disabled={pending} data-testid="sub-extend" onClick={() => onMove('extend', { days: Number(days) })}>{t('platformAdmin.extend')}</Button>
        {sub.status === 'active' && <Button size="sm" variant="secondary" disabled={pending} onClick={() => onMove('past_due')}>{t('platformAdmin.markPastDue')}</Button>}
      </div>
      <div className="flex flex-wrap items-end gap-2">
        <Input className="min-w-48 flex-1" label={t('platformAdmin.reason')} value={reason} onChange={(e) => setReason(e.target.value)} />
        {closed || sub.status === 'past_due' ? (
          <Button size="sm" variant="secondary" disabled={pending} data-testid="sub-reactivate" onClick={() => onMove('reactivate')}>{t('platformAdmin.reactivate')}</Button>
        ) : null}
        {!closed && <Button size="sm" variant="danger" disabled={pending} data-testid="sub-suspend" onClick={() => onMove('suspend', { reason: reason || null })}>{t('platformAdmin.suspendSub')}</Button>}
        {sub.status !== 'cancelled' && <Button size="sm" variant="danger" disabled={pending} data-testid="sub-cancel" onClick={() => onMove('cancel', { reason: reason || null })}>{t('platformAdmin.cancelSub')}</Button>}
      </div>
    </div>
  )
}

function Entitlements({ sub, pending, onSave }: { sub: PlatformSubscription; pending: boolean; onSave: (body: Record<string, unknown>) => void }) {
  const { t } = useTranslation()
  const [extraSeats, setExtraSeats] = useState(String(sub.extra_seats))
  const [seatOverride, setSeatOverride] = useState(sub.seat_limit_override === null ? '' : String(sub.seat_limit_override))
  const [addons, setAddons] = useState<Feature[]>(sub.addons)
  const [disabledModules, setDisabledModules] = useState<string[]>(sub.disabled_modules ?? [])
  const [limits, setLimits] = useState<Record<string, string>>(
    Object.fromEntries(LIMIT_KEYS.map((key) => [key, sub.limit_overrides && key in sub.limit_overrides ? String(sub.limit_overrides[key] ?? '') : ''])),
  )

  const toggle = (feature: Feature) => setAddons((current) => (current.includes(feature) ? current.filter((f) => f !== feature) : [...current, feature]))

  return (
    <form
      className="space-y-3"
      data-testid="entitlements-form"
      onSubmit={(event) => {
        event.preventDefault()
        const overrides = Object.fromEntries(Object.entries(limits).filter(([, v]) => v !== '').map(([k, v]) => [k, Number(v)]))
        onSave({
          extra_seats: Number(extraSeats || 0),
          seat_limit_override: seatOverride === '' ? null : Number(seatOverride),
          addons,
          disabled_modules: disabledModules,
          limit_overrides: Object.keys(overrides).length ? overrides : null,
        })
      }}
    >
      <h4 className="label-caps">{t('platformAdmin.seatsAndAddons')}</h4>
      <div className="grid gap-2 sm:grid-cols-2">
        <Input type="number" min={0} label={t('platformAdmin.extraSeats')} value={extraSeats} onChange={(e) => setExtraSeats(e.target.value)} data-testid="extra-seats" />
        <Input type="number" min={1} label={t('platformAdmin.seatOverride')} hint={t('common.optional')} value={seatOverride} onChange={(e) => setSeatOverride(e.target.value)} />
      </div>
      <div className="grid gap-2 sm:grid-cols-3">
        {LIMIT_KEYS.map((key) => (
          <Input key={key} type="number" min={0} label={t(`billing.meters.${key}`)} hint={t('platformAdmin.overrideHint')} value={limits[key]} onChange={(e) => setLimits((c) => ({ ...c, [key]: e.target.value }))} />
        ))}
      </div>
      <fieldset>
        <legend className="mb-1 text-[0.9375rem] font-medium">{t('platformAdmin.addons')}</legend>
        <div className="grid gap-1 sm:grid-cols-2">
          {ALL_FEATURES.map((feature) => (
            <label key={feature} className="flex items-center gap-2 text-[0.9375rem]">
              <input type="checkbox" checked={addons.includes(feature)} disabled={sub.features.includes(feature) && !addons.includes(feature)} onChange={() => toggle(feature)} data-testid={`addon-${feature}`} />
              {t(`billing.features.${feature}`)}
              {sub.features.includes(feature) && !addons.includes(feature) && <span className="t-caption">({t('platformAdmin.inPlan')})</span>}
            </label>
          ))}
        </div>
      </fieldset>
      <fieldset data-testid="workspace-modules">
        <legend className="mb-1 text-[0.9375rem] font-medium">{t('platformAdmin.modules')}</legend>
        <p className="t-caption mb-1">{t('platformAdmin.modulesHint')}</p>
        <div className="grid gap-1 sm:grid-cols-2">
          {(sub.modules ?? []).map((module) => (
            <label key={module} className="flex min-h-9 items-center gap-2 text-[0.9375rem]">
              <input type="checkbox" className="size-4 accent-[var(--brand)]" checked={!disabledModules.includes(module)} data-testid={`module-${module}`} onChange={(e) => setDisabledModules((current) => (e.target.checked ? current.filter((m) => m !== module) : [...current, module]))} />
              {t(`platformAdmin.module.${module}`)}
            </label>
          ))}
        </div>
      </fieldset>
      <Button type="submit" size="sm" loading={pending} data-testid="entitlements-save">{t('common.save')}</Button>
    </form>
  )
}

function InvoiceForm({ currency, pending, onSubmit }: { currency: string; pending: boolean; onSubmit: (body: Record<string, unknown>) => void }) {
  const { t } = useTranslation()
  const [amount, setAmount] = useState('')
  const [ref, setRef] = useState('')
  const [paid, setPaid] = useState(false)

  return (
    <form
      className="mt-2 flex flex-wrap items-end gap-2"
      onSubmit={(event) => {
        event.preventDefault()
        if (!amount) return
        onSubmit({ amount: Number(amount), currency, provider_ref: ref || null, status: paid ? 'paid' : 'issued' })
        setAmount('')
        setRef('')
      }}
    >
      <Input className="w-32" type="number" min={0} step="0.01" label={t('billing.amount')} value={amount} onChange={(e) => setAmount(e.target.value)} data-testid="invoice-amount" />
      <Input className="w-40" label={t('platformAdmin.reference')} hint={t('common.optional')} value={ref} onChange={(e) => setRef(e.target.value)} />
      <label className="flex items-center gap-2 pb-2 text-[0.9375rem]"><input type="checkbox" checked={paid} onChange={(e) => setPaid(e.target.checked)} />{t('billing.invoiceStatus.paid')}</label>
      <Button type="submit" size="sm" variant="secondary" loading={pending} data-testid="invoice-add">{t('platformAdmin.recordInvoice')}</Button>
    </form>
  )
}
