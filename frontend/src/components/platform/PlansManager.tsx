import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { Badge, Button, Card, CardBody, CardHeader, ErrorState, Input, Modal, SkeletonCards } from '@/components/ui'
import { apiGet, apiPatch, apiPost } from '@/lib/api'
import { formatCurrency, formatNumber } from '@/lib/format'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import type { Feature } from '@/types/api'
import { ALL_FEATURES, LIMIT_KEYS, type PlatformPlan } from './types'

const BLANK: Partial<PlatformPlan> = { key: '', name: '', included_seats: 3, currency: 'SAR', features: [], limits: { projects: null, leads_per_month: null, storage_mb: null }, is_active: true }

/**
 * The catalogue. Everything about a plan — price, seats, limits, features — is edited here, never in code; a plan in
 * use is retired (inactive), not deleted. A plan without a price is shown as such, and revenue built on it reads N/A.
 */
export function PlansManager() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const [editing, setEditing] = useState<Partial<PlatformPlan> | null>(null)
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['platform-plans'],
    queryFn: () => apiGet<{ plans: PlatformPlan[] }>('/super-admin/plans'),
  })

  if (isLoading) return <SkeletonCards count={4} />
  if (isError || !data) return <ErrorState onRetry={() => void refetch()} />

  return (
    <div className="space-y-3" data-testid="plans-manager">
      <div className="flex justify-end">
        <Button icon={<Plus className="size-4" />} onClick={() => setEditing({ ...BLANK })} data-testid="plan-new">{t('platformAdmin.newPlan')}</Button>
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {data.plans.map((plan) => (
          <Card key={plan.id} data-testid={`plan-${plan.key}`}>
            <CardHeader
              title={<span className="flex items-center gap-2">{plan.name}{!plan.is_active && <Badge tone="neutral">{t('platformAdmin.retired')}</Badge>}</span>}
              description={plan.price_monthly !== null ? t('billing.perMonth', { price: formatCurrency(Number(plan.price_monthly), plan.currency, lang) }) : t('platformAdmin.notPriced')}
              action={<Button size="sm" variant="ghost" onClick={() => setEditing(plan)} data-testid={`plan-edit-${plan.key}`}>{t('common.edit')}</Button>}
            />
            <CardBody className="space-y-2 text-[0.9375rem]">
              <p>{t('billing.seatsIncluded', { count: plan.included_seats })}{plan.max_seats ? ` · ${t('platformAdmin.maxSeats', { count: plan.max_seats })}` : ''}</p>
              <p className="t-caption">
                {LIMIT_KEYS.map((key) => `${t(`billing.meters.${key}`)}: ${plan.limits[key] === null ? t('billing.unlimited') : formatNumber(plan.limits[key] as number, lang)}`).join(' · ')}
              </p>
              <p className="t-caption">{plan.features.map((f) => t(`billing.features.${f}`)).join('، ') || t('platformAdmin.coreOnly')}</p>
              <p className="t-caption">{t('platformAdmin.companiesOnPlan', { count: plan.tenants_count ?? 0 })}</p>
            </CardBody>
          </Card>
        ))}
      </div>
      {editing && <PlanEditor plan={editing} onClose={() => setEditing(null)} />}
    </div>
  )
}

function PlanEditor({ plan, onClose }: { plan: Partial<PlatformPlan>; onClose: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [form, setForm] = useState({
    key: plan.key ?? '', name: plan.name ?? '', description: plan.description ?? '',
    price_monthly: plan.price_monthly ?? '', price_yearly: plan.price_yearly ?? '', extra_seat_price: plan.extra_seat_price ?? '',
    currency: plan.currency ?? 'SAR', included_seats: String(plan.included_seats ?? 3), max_seats: plan.max_seats ? String(plan.max_seats) : '',
    is_active: plan.is_active ?? true,
  })
  const [limits, setLimits] = useState<Record<string, string>>(Object.fromEntries(LIMIT_KEYS.map((k) => [k, plan.limits?.[k] === null || plan.limits?.[k] === undefined ? '' : String(plan.limits[k])])))
  const [features, setFeatures] = useState<Feature[]>(plan.features ?? [])

  const save = useMutation({
    mutationFn: () => {
      const num = (v: string) => (v === '' ? null : Number(v))
      const body = {
        ...form,
        description: form.description || null,
        price_monthly: num(String(form.price_monthly)), price_yearly: num(String(form.price_yearly)), extra_seat_price: num(String(form.extra_seat_price)),
        included_seats: Number(form.included_seats), max_seats: num(form.max_seats),
        limits: Object.fromEntries(LIMIT_KEYS.map((k) => [k, num(limits[k])])),
        features,
      }
      return plan.id ? apiPatch(`/super-admin/plans/${plan.id}`, body) : apiPost('/super-admin/plans', body)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['platform-plans'] })
      void queryClient.invalidateQueries({ queryKey: ['platform-dashboard'] })
      toast(t('settings.saved'), 'success')
      onClose()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const field = (key: keyof typeof form, label: string, extra: Record<string, unknown> = {}) => (
    <Input label={label} value={String(form[key])} onChange={(e) => setForm((c) => ({ ...c, [key]: e.target.value }))} {...extra} />
  )

  return (
    <Modal
      open
      onClose={onClose}
      title={plan.id ? t('platformAdmin.editPlan', { name: plan.name }) : t('platformAdmin.newPlan')}
      footer={<><Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button><Button loading={save.isPending} onClick={() => save.mutate()} data-testid="plan-save">{t('common.save')}</Button></>}
    >
      <div className="space-y-3">
        <div className="grid gap-3 sm:grid-cols-2">
          {field('name', t('platformAdmin.planName'), { 'data-testid': 'plan-name' })}
          {field('key', t('platformAdmin.planKey'), { dir: 'ltr', disabled: Boolean(plan.id), 'data-testid': 'plan-key' })}
        </div>
        <div className="grid gap-3 sm:grid-cols-4">
          {field('price_monthly', t('platformAdmin.priceMonthly'), { type: 'number', min: 0, 'data-testid': 'plan-price' })}
          {field('price_yearly', t('platformAdmin.priceYearly'), { type: 'number', min: 0 })}
          {field('extra_seat_price', t('platformAdmin.seatPrice'), { type: 'number', min: 0 })}
          {field('currency', t('settings.currency'), { dir: 'ltr', maxLength: 3 })}
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          {field('included_seats', t('platformAdmin.includedSeats'), { type: 'number', min: 1 })}
          {field('max_seats', t('platformAdmin.maxSeatsLabel'), { type: 'number', min: 1, hint: t('platformAdmin.blankUnlimited') })}
        </div>
        <div className="grid gap-3 sm:grid-cols-3">
          {LIMIT_KEYS.map((key) => (
            <Input key={key} type="number" min={0} label={t(`billing.meters.${key}`)} hint={t('platformAdmin.blankUnlimited')} value={limits[key]} onChange={(e) => setLimits((c) => ({ ...c, [key]: e.target.value }))} />
          ))}
        </div>
        <fieldset>
          <legend className="mb-1 text-[0.9375rem] font-medium">{t('billing.included')}</legend>
          <div className="grid gap-1 sm:grid-cols-2">
            {ALL_FEATURES.map((feature) => (
              <label key={feature} className="flex items-center gap-2 text-[0.9375rem]">
                <input type="checkbox" checked={features.includes(feature)} onChange={() => setFeatures((c) => (c.includes(feature) ? c.filter((f) => f !== feature) : [...c, feature]))} data-testid={`plan-feature-${feature}`} />
                {t(`billing.features.${feature}`)}
              </label>
            ))}
          </div>
        </fieldset>
        <label className="flex items-center gap-2 text-[0.9375rem]">
          <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((c) => ({ ...c, is_active: e.target.checked }))} />
          {t('platformAdmin.offered')}
        </label>
      </div>
    </Modal>
  )
}
