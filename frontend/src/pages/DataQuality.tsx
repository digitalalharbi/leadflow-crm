import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, Plug } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchDataQuality, type QualityCheck } from '@/features/dataQuality'
import { useApiError } from '@/hooks/useApiError'
import { formatDateTime } from '@/lib/format'
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
              <QualityCard key={check.key} check={check} />
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

function QualityCard({ check }: { check: QualityCheck }) {
  const { t } = useTranslation()
  const clean = check.count === 0
  const query = new URLSearchParams(check.filter).toString()

  const body = (
    <CardBody className="flex items-start justify-between gap-3">
      <div className="space-y-1">
        <p className="t-body font-medium">{t(`dataQuality.check.${check.key}`)}</p>
        <p className="t-caption">{t(`dataQuality.why.${check.key}`)}</p>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        <span className={cn('t-h3 tabular-nums', !clean && 'text-[var(--color-danger)]')}>
          {check.count}
        </span>
        {clean ? (
          <CheckCircle2 className="size-4 text-[var(--color-status-won)]" aria-hidden />
        ) : (
          <AlertTriangle className="size-4 text-[var(--color-danger)]" aria-hidden />
        )}
      </div>
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
