import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, Plug } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchDataQuality, type QualityCheck } from '@/features/dataQuality'
import { useApiError } from '@/hooks/useApiError'
import { formatDateTime, formatNumber } from '@/lib/format'
import { Badge, Card, CardBody, ErrorState, PageHeader, SkeletonCards } from '@/components/ui'
import { cn } from '@/lib/cn'

/**
 * What is quietly wrong with the data the workspace is paying to collect.
 *
 * Every number here cost money that has already been spent: a lead with an
 * unreachable phone is a click nobody can call, and a lead with no campaign is
 * a click whose source can never be credited — so the campaign worth scaling
 * cannot be identified and the one wasting budget cannot be stopped.
 *
 * Each card is a link. A dashboard number nobody can act on is a number people
 * learn to scroll past, and the whole value of this screen is that the count
 * and the work are one click apart.
 */
export function DataQualityPage() {
  const { t, i18n } = useTranslation()
  const translateError = useApiError()

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['data-quality'],
    queryFn: fetchDataQuality,
  })

  if (isError) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('dataQuality.title')} />
        <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('dataQuality.title')}
        subtitle={
          data
            ? data.total_issues === 0
              ? t('dataQuality.allClear')
              : t('dataQuality.issueCount', { count: data.total_issues })
            : t('dataQuality.subtitle')
        }
      />

      {isLoading ? (
        <SkeletonCards count={6} />
      ) : (
        <>
          <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="quality-checks">
            {data?.checks.map((check) => (
              <QualityCard key={check.key} check={check} total={data?.total_leads ?? 0} />
            ))}
          </section>

          <section className="space-y-3">
            <h2 className="t-h3 flex items-center gap-2">
              <Plug className="size-4" aria-hidden />
              {t('dataQuality.integrations')}
            </h2>
            {/*
              A broken connection does not announce itself: leads simply stop
              arriving, and a quiet morning looks exactly like a slow one.
            */}
            {data && data.integrations.length === 0 ? (
              <p className="t-caption">{t('dataQuality.noIntegrations')}</p>
            ) : (
              <div className="space-y-2" data-testid="integration-health">
                {data?.integrations.map((integration) => (
                  <Card key={integration.id}>
                    <CardBody className="flex flex-wrap items-center justify-between gap-3">
                      <div className="min-w-0 space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="t-body font-medium">{integration.name}</span>
                          <Badge tone={integration.healthy ? 'success' : 'danger'}>
                            {t(`dataQuality.status.${integration.status}`, {
                              defaultValue: integration.status,
                            })}
                          </Badge>
                        </div>
                        <p className="t-caption">
                          {integration.last_synced_at
                            ? t('dataQuality.lastSync', {
                                when: formatDateTime(integration.last_synced_at, i18n.language),
                              })
                            : t('dataQuality.neverSynced')}
                        </p>
                        {integration.last_error && (
                          <p className="t-caption text-[var(--color-danger)]">
                            {integration.last_error}
                          </p>
                        )}
                      </div>
                      <Link to="/integrations" className="t-caption hover:underline">
                        {t('dataQuality.openIntegration')}
                      </Link>
                    </CardBody>
                  </Card>
                ))}
              </div>
            )}
          </section>
        </>
      )}
    </div>
  )
}

function QualityCard({ check, total }: { check: QualityCheck; total: number }) {
  const { t, i18n } = useTranslation()
  const clean = check.count === 0
  const query = new URLSearchParams(check.filter).toString()

  /*
   * The share, not only the count.
   *
   * Ten leads with no campaign is a quarter of a small workspace and a
   * rounding error in a large one, and this card showed the same "10" either
   * way. The bar is the fastest read of "is this worth my afternoon"; the
   * figures beside it are there because a bar two pixels wide is not a number.
   */
  const share = total > 0 ? (check.count / total) * 100 : 0

  const body = (
    <CardBody className="space-y-2.5">
      <div className="flex items-start justify-between gap-3">
        <div className="space-y-1">
          <p className="t-body font-medium">{t(`dataQuality.check.${check.key}`)}</p>
          <p className="t-caption">{t(`dataQuality.why.${check.key}`)}</p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {/*
            The count is addressable on its own. The card now also carries the
            share, and a test reading the whole card for the first run of digits
            read "10" and "10 عملاء من 43" as 1010 — the same way the dashboard
            tile glued its figure to its change percentage.
          */}
          <span
            className={cn('t-h3 tabular-nums', !clean && 'text-[var(--color-danger)]')}
            data-testid={`check-${check.key}-count`}
          >
            {check.count}
          </span>
          {clean ? (
            <CheckCircle2 className="size-4 text-[var(--color-status-won)]" aria-hidden />
          ) : (
            <AlertTriangle className="size-4 text-[var(--color-danger)]" aria-hidden />
          )}
        </div>
      </div>

      {!clean && total > 0 && (
        <div className="space-y-1">
          <div className="h-1.5 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
            <div
              data-chart-mark
              className="h-full rounded-full bg-[var(--color-danger)]"
              // A sliver still has to be visible: a problem affecting one lead
              // in four hundred is small, not absent.
              style={{ width: `${Math.max(share, 1.5)}%` }}
            />
          </div>
          <p className="t-caption">
            {t('dataQuality.shareOfLeads', {
              count: check.count,
              total: formatNumber(total, i18n.language),
              percent: share >= 1 ? Math.round(share) : share.toFixed(1),
            })}
          </p>
        </div>
      )}
    </CardBody>
  )

  // Nothing wrong means nothing to open. A link to an empty list is a dead end
  // dressed up as an action.
  if (clean) {
    return <Card data-testid={`check-${check.key}`}>{body}</Card>
  }

  return (
    <Link to={`/leads?${query}`} data-testid={`check-${check.key}`} className="block">
      <Card className="h-full transition hover:border-[var(--color-border-strong)]">{body}</Card>
    </Link>
  )
}
