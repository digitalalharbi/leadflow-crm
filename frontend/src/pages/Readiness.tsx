import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Archive, Check, CircleDashed, Database, FileText, Layers, Minus, ServerCog, ShieldCheck, Siren, X, Zap,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatRelative } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, CardHeader, ErrorState, PageHeader, Skeleton, StatCard,
} from '@/components/ui'
import {
  commission, fetchReadiness, type InfraItem, type InfraState, type ReadinessItem, type ReadinessStatus,
} from '@/features/readiness'
import { cn } from '@/lib/cn'

/**
 * One screen for "is this live, and if not, what is it waiting for".
 *
 * Six rows and three words. The words are deliberately not the usual
 * red/amber/green of a health dashboard: this is not about whether something is
 * broken but about how much of it has been *proven*, and a row can be perfectly
 * well configured and entirely unproven. Colour carries the same ordering, but
 * the sentence under each row is the part to read — it names whose move it is.
 */
const TONE: Record<ReadinessStatus, 'success' | 'warning' | 'neutral'> = {
  live_verified: 'success',
  ready: 'warning',
  external_verification_required: 'neutral',
}

export function ReadinessPage() {
  const { t } = useTranslation()
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['readiness', isSuperAdmin],
    queryFn: () => fetchReadiness(isSuperAdmin),
  })

  return (
    <>
      <PageHeader title={t('readiness.title')} subtitle={t('readiness.hint')} />

      {isError ? (
        <Card><ErrorState message={t('errors.unknown')} onRetry={() => void refetch()} /></Card>
      ) : isLoading || !data ? (
        <Skeleton className="h-64" />
      ) : (
        <>
          <div className="mb-3 grid gap-3 sm:grid-cols-3" data-testid="readiness-summary">
            <StatCard
              label={t('readiness.status.live_verified')}
              value={data.summary.live_verified}
              tone="success"
            />
            <StatCard
              label={t('readiness.status.ready')}
              value={data.summary.ready}
              tone="warning"
            />
            <StatCard
              label={t('readiness.status.external_verification_required')}
              value={data.summary.external_verification_required}
            />
          </div>

          <div className="grid gap-3 lg:grid-cols-2" data-testid="readiness-items">
            {data.items.map((item) => (
              <ItemCard key={item.key} item={item} />
            ))}
          </div>

          {/*
            What the platform itself stands on. Apart from the integrations above:
            those are connected by a customer, these are either there or not, and
            the people to ask are different.
          */}
          <section className="mt-6" data-testid="infrastructure">
            <h2 className="mb-1 text-[1rem] font-semibold">{t('readiness.infra.title')}</h2>
            <p className="t-caption mb-3">{t('readiness.infra.hint')}</p>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {data.infrastructure.map((item) => (
                <InfraCard key={item.key} item={item} />
              ))}
            </div>
          </section>
        </>
      )}
    </>
  )
}

function ItemCard({ item }: { item: ReadinessItem }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const run = useMutation({
    mutationFn: () => commission(item.key),
    onSuccess: ({ result, readiness }) => {
      queryClient.setQueryData(['readiness', false], readiness)
      toast(result.ok ? t('readiness.commissionOk') : t('readiness.commissionFailed'), result.ok ? 'success' : 'error')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const last = item.last_commissioning

  return (
    <Card data-testid={`readiness-${item.key}`}>
      <CardHeader
        title={t(`readiness.item.${item.key}`)}
        description={item.blocker ? t(`readiness.blocker.${item.blocker}`, { defaultValue: item.blocker }) : t('readiness.nothingWaiting')}
        action={
          <Badge tone={TONE[item.status]} testId={`readiness-${item.key}-status`}>
            {t(`readiness.status.${item.status}`)}
          </Badge>
        }
      />
      <CardBody className="space-y-3">
        {item.checks.length > 0 && (
          <ul className="space-y-1" data-testid={`readiness-${item.key}-checks`}>
            {item.checks.map((check) => (
              <li key={check.key} className="t-caption flex items-center gap-2">
                {check.ok ? (
                  <Check className="size-3.5 text-[var(--color-success)]" aria-hidden />
                ) : (
                  <X className="size-3.5 text-[var(--color-ink-subtle)]" aria-hidden />
                )}
                <span className={cn(!check.ok && 'text-[var(--color-ink-muted)]')}>
                  {t(`readiness.check.${check.key}`, { defaultValue: check.key })}
                </span>
              </li>
            ))}
          </ul>
        )}

        {item.children && (
          <ul className="flex flex-wrap gap-1.5" data-testid="readiness-ads-children">
            {item.children.map((child) => (
              <li key={child.key}>
                <Badge tone={TONE[child.status]}>
                  {t(`health.name.${child.key}`, { defaultValue: child.key })}
                </Badge>
              </li>
            ))}
          </ul>
        )}

        {last && (
          <div className="rounded-[var(--radius-control)] bg-[var(--color-surface-muted)] p-2.5" data-testid={`readiness-${item.key}-last`}>
            <p className="t-caption mb-1 font-medium">
              {last.ok ? t('readiness.lastOk') : t('readiness.lastFailed')} · {formatRelative(last.at, i18n.language)}
            </p>
            <ul className="space-y-0.5">
              {last.steps.map((step) => (
                <li key={step.key} className="t-caption flex items-start gap-2">
                  {step.ok === true && <Check className="mt-0.5 size-3.5 shrink-0 text-[var(--color-success)]" aria-hidden />}
                  {step.ok === false && <X className="mt-0.5 size-3.5 shrink-0 text-[var(--color-danger)]" aria-hidden />}
                  {step.ok === null && <Minus className="mt-0.5 size-3.5 shrink-0 text-[var(--color-ink-subtle)]" aria-hidden />}
                  <span className="min-w-0 break-words">
                    {t(`readiness.step.${step.key}`, { defaultValue: step.key })}
                    {': '}
                    {t(`readiness.detail.${step.detail}`, { defaultValue: step.detail })}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )}

        {item.commissionable ? (
          <Button
            size="sm"
            variant={item.status === 'live_verified' ? 'outline' : 'primary'}
            icon={<ShieldCheck className="size-4" />}
            loading={run.isPending}
            onClick={() => run.mutate()}
            data-testid={`commission-${item.key}`}
          >
            {last ? t('readiness.recheck') : t('readiness.commission')}
          </Button>
        ) : (
          <p className="t-caption flex items-center gap-1.5 text-[var(--color-ink-muted)]">
            <CircleDashed className="size-3.5" aria-hidden />
            {t('readiness.noCommissioning')}
          </p>
        )}
      </CardBody>
    </Card>
  )
}

const INFRA_TONE: Record<InfraState, 'success' | 'warning' | 'neutral'> = {
  live_verified: 'success',
  ready: 'warning',
  not_configured: 'neutral',
}

const INFRA_ICON: Record<InfraItem['key'], LucideIcon> = {
  database: Database,
  redis: Zap,
  queues: Layers,
  horizon: ServerCog,
  backups: Archive,
  logs: FileText,
  error_tracking: Siren,
}

/**
 * One piece of infrastructure, said the same way every time: where it stands,
 * when that was last seen, the last thing that went wrong, and what to do next.
 * The last line is the one to read — a status without a next step is a
 * dashboard, and this is a to-do list that happens to have status on it.
 */
function InfraCard({ item }: { item: InfraItem }) {
  const { t, i18n } = useTranslation()
  const Icon = INFRA_ICON[item.key]

  return (
    <Card data-testid={`infra-${item.key}`}>
      <div className="space-y-2.5 p-4">
        <div className="flex items-start justify-between gap-2">
          <h3 className="flex items-center gap-2 text-[0.9375rem] font-semibold">
            <Icon className="size-4 text-[var(--color-ink-muted)]" aria-hidden />
            {t(`readiness.infra.item.${item.key}`)}
          </h3>
          <Badge tone={INFRA_TONE[item.state]} testId={`infra-${item.key}-state`}>
            {t(`readiness.infra.state.${item.state}`)}
          </Badge>
        </div>

        <dl className="space-y-1.5 text-[0.8125rem]">
          <div className="flex justify-between gap-3">
            <dt className="text-[var(--color-ink-muted)]">{t('readiness.infra.lastCheck')}</dt>
            <dd className="tabular-nums" data-testid={`infra-${item.key}-checked`}>
              {formatRelative(item.last_check, i18n.language)}
            </dd>
          </div>
          <div className="flex justify-between gap-3">
            <dt className="text-[var(--color-ink-muted)]">{t('readiness.infra.lastError')}</dt>
            <dd
              className={cn('min-w-0 break-words text-end', item.last_error && 'font-mono text-[0.75rem] text-[var(--color-danger)]')}
              data-testid={`infra-${item.key}-error`}
            >
              {item.last_error ?? t('readiness.infra.none')}
            </dd>
          </div>
        </dl>

        <p
          className={cn(
            'rounded-[var(--radius-control)] px-2.5 py-1.5 text-[0.8125rem]',
            item.required_action
              ? 'bg-[color-mix(in_srgb,var(--color-warning)_10%,transparent)]'
              : 'bg-[var(--color-surface-muted)] text-[var(--color-ink-muted)]',
          )}
          data-testid={`infra-${item.key}-action`}
        >
          {item.required_action
            ? t(`readiness.infra.action.${item.required_action}`, { defaultValue: item.required_action })
            : t('readiness.infra.nothingToDo')}
        </p>
      </div>
    </Card>
  )
}
