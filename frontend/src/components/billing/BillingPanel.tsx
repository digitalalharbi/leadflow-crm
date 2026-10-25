import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Badge, Card, CardBody, CardHeader, ErrorState, Skeleton, Table, TableWrap, Td, Th, Tr } from '@/components/ui'
import { apiGet } from '@/lib/api'
import { formatCurrency, formatDate, formatNumber } from '@/lib/format'
import { useAuth } from '@/stores/auth'
import type { Feature, Subscription, UsageMeter } from '@/types/api'

interface Invoice {
  id: string
  number: string
  amount: string
  currency: string
  period_start: string | null
  period_end: string | null
  status: 'draft' | 'issued' | 'paid' | 'void'
  issued_at: string | null
  paid_at: string | null
}

interface CataloguePlan {
  id: string
  key: string
  name: string
  description: string | null
  price_monthly: string | null
  price_yearly: string | null
  currency: string
  included_seats: number
  features: Feature[]
  is_priced: boolean
}

interface BillingPayload {
  subscription: Subscription & { billing_cycle: string; extra_seats: number; addons: Feature[] }
  invoices: Invoice[]
  plans: CataloguePlan[]
}

const STATUS_TONE: Record<string, 'success' | 'info' | 'warning' | 'danger' | 'neutral'> = {
  active: 'success', trialing: 'info', past_due: 'warning', suspended: 'danger', cancelled: 'danger',
  trial_expired: 'danger', expired: 'danger', disabled: 'danger',
}

/**
 * The company's subscription, read from the server: the plan, the lifecycle, usage against every limit, what the plan
 * includes, and the invoices. Read-only — the plan is changed by the platform (or a payment provider, once wired).
 * Everyone but the admin sees the plan line and nothing about money.
 */
export function BillingPanel() {
  const { t, i18n } = useTranslation()
  const user = useAuth((s) => s.user)
  const session = useAuth((s) => s.subscription)
  const isAdmin = user?.role === 'admin'

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['billing'],
    queryFn: () => apiGet<BillingPayload>('/billing'),
    enabled: isAdmin,
  })

  if (!isAdmin) {
    return (
      <Card data-testid="billing-panel">
        <CardHeader title={t('billing.title')} />
        <CardBody className="space-y-3">
          <Line label={t('billing.plan')} value={session?.plan?.name ?? t('billing.noPlan')} />
          {session && <Line label={t('common.status')} value={<Badge tone={STATUS_TONE[session.state] ?? 'neutral'}>{t(`billing.state.${session.state}`)}</Badge>} />}
          <p className="text-[0.875rem] text-[var(--color-ink-muted)]">{t('billing.adminOnly')}</p>
        </CardBody>
      </Card>
    )
  }

  if (isLoading) return <Skeleton className="h-64" />
  if (isError || !data) return <ErrorState onRetry={() => void refetch()} />

  const sub = data.subscription
  const lang = i18n.language

  return (
    <div className="space-y-4" data-testid="billing-panel">
      <Card>
        <CardHeader title={t('billing.title')} description={t('billing.readOnly')} />
        <CardBody className="space-y-3">
          <Line label={t('billing.plan')} value={<span data-testid="billing-plan">{sub.plan?.name ?? t('billing.noPlan')}</span>} />
          <Line label={t('common.status')} value={<Badge testId="billing-state" tone={STATUS_TONE[sub.state] ?? 'neutral'}>{t(`billing.state.${sub.state}`)}</Badge>} />
          <Line label={t('billing.cycle')} value={t(`billing.cycles.${sub.billing_cycle}`, { defaultValue: sub.billing_cycle })} />
          {sub.trial_ends_at && sub.status === 'trialing' && <Line label={t('billing.trialEnds')} value={formatDate(sub.trial_ends_at, lang)} />}
          {sub.subscription_ends_at && sub.status !== 'trialing' && <Line label={t('billing.renews')} value={formatDate(sub.subscription_ends_at, lang)} />}
        </CardBody>
      </Card>

      {sub.usage && (
        <Card>
          <CardHeader title={t('billing.usage')} />
          <CardBody className="grid gap-3 sm:grid-cols-2">
            {(Object.entries(sub.usage) as [keyof NonNullable<Subscription['usage']>, UsageMeter][]).map(([key, meter]) => (
              <Meter key={key} testId={`usage-${key}`} label={t(`billing.meters.${key}`)} meter={meter} lang={lang} unlimited={t('billing.unlimited')} />
            ))}
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader title={t('billing.included')} />
        <CardBody>
          <ul className="grid gap-2 sm:grid-cols-2" data-testid="billing-features">
            {(Object.keys(FEATURE_ORDER) as Feature[]).map((feature) => {
              const on = sub.features.includes(feature)

              return (
                <li key={feature} data-feature={feature} data-on={on} className={on ? 'text-[var(--color-ink)]' : 'text-[var(--color-ink-subtle)] line-through decoration-1'}>
                  {on ? '✓ ' : '— '}{t(`billing.features.${feature}`)}
                </li>
              )
            })}
          </ul>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title={t('billing.invoices')} />
        <CardBody>
          {data.invoices.length === 0 ? (
            <p className="text-[0.9375rem] text-[var(--color-ink-muted)]" data-testid="billing-no-invoices">{t('billing.noInvoices')}</p>
          ) : (
            <TableWrap>
              <Table>
                <thead>
                  <tr><Th>{t('billing.number')}</Th><Th>{t('billing.amount')}</Th><Th>{t('billing.period')}</Th><Th>{t('common.status')}</Th></tr>
                </thead>
                <tbody>
                  {data.invoices.map((invoice) => (
                    <Tr key={invoice.id}>
                      <Td>{invoice.number}</Td>
                      <Td>{formatCurrency(Number(invoice.amount), invoice.currency, lang)}</Td>
                      <Td>{invoice.period_start ? `${formatDate(invoice.period_start, lang)} – ${formatDate(invoice.period_end, lang)}` : '—'}</Td>
                      <Td><Badge tone={invoice.status === 'paid' ? 'success' : invoice.status === 'void' ? 'neutral' : 'info'}>{t(`billing.invoiceStatus.${invoice.status}`)}</Badge></Td>
                    </Tr>
                  ))}
                </tbody>
              </Table>
            </TableWrap>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title={t('billing.plans')} description={t('billing.plansHint')} />
        <CardBody className="grid gap-3 md:grid-cols-2">
          {data.plans.map((plan) => (
            <div key={plan.id} className="rounded-[var(--radius-md)] border border-[var(--color-line)] p-3" data-testid={`catalogue-${plan.key}`}>
              <div className="flex items-center justify-between gap-2">
                <span className="font-semibold">{plan.name}</span>
                {sub.plan?.key === plan.key && <Badge tone="success">{t('billing.current')}</Badge>}
              </div>
              <p className="mt-1 text-[0.9375rem]">
                {plan.is_priced && plan.price_monthly !== null
                  ? t('billing.perMonth', { price: formatCurrency(Number(plan.price_monthly), plan.currency, lang) })
                  : t('billing.priceOnRequest')}
              </p>
              <p className="text-[0.875rem] text-[var(--color-ink-muted)]">{t('billing.seatsIncluded', { count: plan.included_seats })}</p>
            </div>
          ))}
        </CardBody>
      </Card>
    </div>
  )
}

const FEATURE_ORDER: Record<Feature, true> = {
  tracking_hub: true, advanced_forms: true, automations: true, attribution: true, exports: true,
  api: true, advanced_reports: true, whatsapp: true, custom_branding: true, multiple_projects: true,
  report_pdf: true, report_excel: true, advanced_templates: true, advanced_attribution: true, white_label_reports: true, client_otp: true,
}

function Line({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 text-[1rem]">
      <span className="text-[var(--color-ink-muted)]">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  )
}

function Meter({ label, meter, lang, unlimited, testId }: { label: string; meter: UsageMeter; lang: string; unlimited: string; testId: string }) {
  const share = meter.limit ? Math.min(100, Math.round((meter.used / meter.limit) * 100)) : 0

  return (
    <div data-testid={testId} data-alert={meter.alert} data-over={meter.over}>
      <div className="flex items-baseline justify-between gap-2 text-[0.9375rem]">
        <span>{label}</span>
        <span className="tabular-nums font-medium">
          {formatNumber(meter.used, lang)} / {meter.limit === null ? unlimited : formatNumber(meter.limit, lang)}
        </span>
      </div>
      {meter.limit !== null && (
        <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]" aria-hidden>
          <div className="h-full rounded-full" style={{ width: `${share}%`, background: meter.over ? 'var(--color-danger)' : meter.alert ? 'var(--color-warning)' : 'var(--brand)' }} />
        </div>
      )}
    </div>
  )
}
