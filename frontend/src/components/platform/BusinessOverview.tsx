import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, CardHeader, ErrorState, SkeletonCards } from '@/components/ui'
import { apiGet } from '@/lib/api'
import { formatCurrency, formatNumber } from '@/lib/format'

interface Dashboard {
  companies: Record<'total' | 'trialing' | 'active' | 'past_due' | 'suspended' | 'cancelled' | 'expired' | 'disabled' | 'new_last_30_days' | 'trials_ending_7_days', number>
  seats: { active: number }
  usage: { leads_last_30_days: number; leads_total: number }
  plans: Array<{ key: string; name: string; count: number; is_priced: boolean }>
  revenue: {
    mrr: Record<string, number> | null
    paying_companies: number
    paying_unpriced: number
    invoices_paid_30_days: Record<string, number> | null
  }
  usage_alerts: Array<{ tenant: string; name: string; meter: string; used: number; limit: number | null; over: boolean }>
}

/**
 * The platform owner's business at a glance. Every figure is counted; money is computed from prices the owner set or
 * summed from invoices they recorded, and reads "not configured" — never a zero — when neither exists.
 */
export function BusinessOverview() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['platform-dashboard'],
    queryFn: () => apiGet<Dashboard>('/super-admin/dashboard'),
  })

  if (isLoading) return <SkeletonCards count={4} />
  if (isError || !data) return <ErrorState onRetry={() => void refetch()} />

  const money = (values: Record<string, number> | null) =>
    values ? Object.entries(values).map(([currency, amount]) => formatCurrency(amount, currency, lang)).join(' + ') : t('platformAdmin.notConfigured')

  const tiles: Array<[string, string, string]> = [
    ['total', t('platformAdmin.kpi.companies'), formatNumber(data.companies.total, lang)],
    ['active', t('billing.state.active'), formatNumber(data.companies.active, lang)],
    ['trialing', t('billing.state.trialing'), formatNumber(data.companies.trialing, lang)],
    ['past_due', t('billing.state.past_due'), formatNumber(data.companies.past_due, lang)],
    ['suspended', t('billing.state.suspended'), formatNumber(data.companies.suspended, lang)],
    ['cancelled', t('billing.state.cancelled'), formatNumber(data.companies.cancelled, lang)],
    ['seats', t('platformAdmin.kpi.activeSeats'), formatNumber(data.seats.active, lang)],
    ['new', t('platformAdmin.kpi.new30'), formatNumber(data.companies.new_last_30_days, lang)],
  ]

  return (
    <div className="space-y-4" data-testid="business-overview">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {tiles.map(([key, label, value]) => (
          <Card key={key}>
            <CardBody>
              <p className="t-caption">{label}</p>
              <p className="mt-1 text-[1.5rem] font-semibold tabular-nums" data-testid={`biz-${key}`}>{value}</p>
            </CardBody>
          </Card>
        ))}
      </div>

      <div className="grid gap-3 md:grid-cols-2">
        <Card>
          <CardHeader title={t('platformAdmin.mrr')} description={t('platformAdmin.mrrHint')} />
          <CardBody className="space-y-1">
            <p className="text-[1.5rem] font-semibold" data-testid="biz-mrr">{money(data.revenue.mrr)}</p>
            <p className="t-caption">{t('platformAdmin.payingCompanies', { count: data.revenue.paying_companies })}</p>
            {data.revenue.paying_unpriced > 0 && <p className="t-caption" data-testid="biz-unpriced">{t('platformAdmin.unpricedWarning', { count: data.revenue.paying_unpriced })}</p>}
          </CardBody>
        </Card>
        <Card>
          <CardHeader title={t('platformAdmin.paid30')} description={t('platformAdmin.paid30Hint')} />
          <CardBody>
            <p className="text-[1.5rem] font-semibold" data-testid="biz-paid">{money(data.revenue.invoices_paid_30_days)}</p>
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-3 md:grid-cols-2">
        <Card>
          <CardHeader title={t('platformAdmin.planDistribution')} />
          <CardBody>
            <ul className="space-y-1.5 text-[0.9375rem]" data-testid="biz-plans">
              {data.plans.map((plan) => (
                <li key={plan.key} className="flex justify-between gap-2">
                  <span>{plan.name}{!plan.is_priced && <span className="t-caption"> · {t('platformAdmin.notPriced')}</span>}</span>
                  <span className="tabular-nums">{formatNumber(plan.count, lang)}</span>
                </li>
              ))}
            </ul>
          </CardBody>
        </Card>
        <Card>
          <CardHeader title={t('platformAdmin.usageAlerts')} description={t('platformAdmin.leads30', { count: data.usage.leads_last_30_days })} />
          <CardBody>
            {data.usage_alerts.length === 0 ? (
              <p className="t-caption" data-testid="biz-no-alerts">{t('platformAdmin.noAlerts')}</p>
            ) : (
              <ul className="space-y-1.5 text-[0.9375rem]" data-testid="biz-alerts">
                {data.usage_alerts.map((alert) => (
                  <li key={`${alert.tenant}-${alert.meter}`} className="flex justify-between gap-2">
                    <span>{alert.name} · {t(`billing.meters.${alert.meter}`)}</span>
                    <span className="tabular-nums">{formatNumber(alert.used, lang)} / {alert.limit === null ? '∞' : formatNumber(alert.limit, lang)}</span>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>
    </div>
  )
}
