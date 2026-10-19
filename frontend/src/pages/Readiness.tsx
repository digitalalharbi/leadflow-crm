import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Archive, ArrowDown, Check, CircleDashed, Database, FileText, Layers, Minus, ServerCog, ShieldCheck, Siren, X, Zap,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatRelative } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, CardHeader, ErrorState, PageHeader, Skeleton,
} from '@/components/ui'
import { ProgressBar, RingProgress } from '@/components/visual'
import {
  commission, fetchReadiness, type InfraItem, type InfraState, type ReadinessItem, type ReadinessReport, type ReadinessStatus,
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
          <ReadinessHero report={data} />

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
            <Card className="divide-y divide-[var(--color-line)] overflow-hidden">
              {data.infrastructure.map((item) => (
                <InfraRow key={item.key} item={item} />
              ))}
            </Card>
          </section>
        </>
      )}
    </>
  )
}

const LADDER: Array<{ key: ReadinessStatus; color: string }> = [
  { key: 'live_verified', color: 'var(--brand)' },
  { key: 'ready', color: 'var(--color-warning)' },
  { key: 'external_verification_required', color: 'var(--color-ink-subtle)' },
]

const INFRA_LADDER: Array<{ key: InfraState; color: string }> = [
  { key: 'live_verified', color: 'var(--brand)' },
  { key: 'ready', color: 'var(--color-warning)' },
  { key: 'not_configured', color: 'var(--color-ink-subtle)' },
]

/**
 * The answer before the evidence: how much of this deployment is proven, how
 * the rest splits between "configured, unproven" and "waiting on someone
 * outside", and the few rows standing between it and going live — each with
 * whose move it is. The cards below are the evidence for every one of these.
 */
function ReadinessHero({ report }: { report: ReadinessReport }) {
  const { t } = useTranslation()

  const total = report.items.length + report.infrastructure.length
  const proven = report.summary.live_verified + (report.infrastructure_summary.live_verified ?? 0)
  const percent = total > 0 ? Math.round((proven / total) * 100) : 0
  const blocking = report.items.filter((item) => item.status !== 'live_verified')

  return (
    <Card className="mb-3" data-testid="readiness-summary">
      <div className="grid gap-5 p-5 lg:grid-cols-12 lg:items-center">
        <div className="flex items-center gap-4 lg:col-span-3">
          <RingProgress value={percent} size={104} thickness={10} tone={percent === 100 ? 'good' : 'warn'} label={t('readiness.hero.proven')} testId="readiness-ring">
            <span className="text-[1.5rem] font-extrabold tabular-nums" dir="ltr">{percent}%</span>
          </RingProgress>
          <div>
            <p className="text-[0.9375rem] font-semibold">{t('readiness.hero.proven')}</p>
            <p className="t-caption tabular-nums">{t('readiness.hero.ofTotal', { proven, total })}</p>
          </div>
        </div>

        <div className="space-y-4 lg:col-span-4">
          <LadderBar
            title={t('readiness.hero.integrations')}
            testId="readiness-ladder"
            steps={LADDER.map(({ key, color }) => ({ key, color, label: t(`readiness.status.${key}`), count: report.summary[key] }))}
          />
          <LadderBar
            title={t('readiness.infra.title')}
            testId="infra-ladder"
            steps={INFRA_LADDER.map(({ key, color }) => ({ key, color, label: t(`readiness.infra.state.${key}`), count: report.infrastructure_summary[key] ?? 0 }))}
          />
        </div>

        <div className="lg:col-span-5" data-testid="readiness-blocking">
          <p className="mb-2 text-[0.8125rem] font-semibold">{t('readiness.hero.blocking')}</p>
          {blocking.length === 0 ? (
            <p className="t-caption">{t('readiness.hero.nothingBlocking')}</p>
          ) : (
            <ul className="space-y-1.5">
              {blocking.slice(0, 4).map((item) => (
                <li key={item.key}>
                  <a href={`#readiness-${item.key}`} className="flex items-center gap-2.5 rounded-xl bg-[var(--color-surface-muted)] px-3 py-2 hover:bg-[var(--color-chrome-fill)]">
                    <span aria-hidden className="size-2 shrink-0 rounded-full" style={{ background: LADDER.find((l) => l.key === item.status)?.color }} />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[0.8125rem] font-medium">{t(`readiness.item.${item.key}`)}</span>
                      {item.blocker && (
                        <span className="block truncate text-[0.6875rem] text-[var(--color-ink-muted)]">
                          {t(`readiness.blocker.${item.blocker}`, { defaultValue: item.blocker })}
                        </span>
                      )}
                    </span>
                    <span className="shrink-0 rounded-full bg-[var(--color-surface)] px-2 py-0.5 text-[0.6875rem] text-[var(--color-ink-muted)]">
                      {item.commissionable ? t('readiness.hero.yourMove') : t('readiness.hero.theirMove')}
                    </span>
                    <ArrowDown className="size-3.5 shrink-0 text-[var(--color-ink-subtle)]" aria-hidden />
                  </a>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </Card>
  )
}

/** One stacked bar of the proof ladder, with the counts written beside it. */
function LadderBar({ title, steps, testId }: { title: string; steps: Array<{ key: string; label: string; count: number; color: string }>; testId: string }) {
  const sum = steps.reduce((total, step) => total + step.count, 0)

  return (
    <div data-testid={testId}>
      <p className="mb-1.5 text-[0.8125rem] font-semibold">{title}</p>
      <div className="flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-[var(--color-chrome-fill)]" dir="ltr">
        {sum > 0 &&
          steps.filter((step) => step.count > 0).map((step) => (
            <span key={step.key} className="h-full first:rounded-s-full last:rounded-e-full" data-chart-mark style={{ width: `${(step.count / sum) * 100}%`, background: step.color }} />
          ))}
      </div>
      <ul className="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5">
        {steps.map((step) => (
          <li key={step.key} className="flex items-center gap-1.5 text-[0.6875rem] text-[var(--color-ink-muted)]" data-testid={`${testId}-${step.key}`}>
            <span aria-hidden className="size-2 rounded-full" style={{ background: step.color }} />
            {step.label}
            <span className="font-semibold tabular-nums text-[var(--color-ink)]">{step.count}</span>
          </li>
        ))}
      </ul>
    </div>
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
    <Card data-testid={`readiness-${item.key}`} id={`readiness-${item.key}`} className="scroll-mt-24">
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
          <ProgressBar
            value={(item.checks.filter((check) => check.ok).length / item.checks.length) * 100}
            tone={item.checks.every((check) => check.ok) ? 'good' : 'warn'}
            label={t('readiness.hero.checksPassed', { done: item.checks.filter((check) => check.ok).length, total: item.checks.length })}
          />
        )}
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
 * One piece of infrastructure on one line: where it stands, when it was last
 * seen, the last thing that went wrong, and what to do next. The last column is
 * the one to read — a status without a next step is a dashboard, and this is a
 * to-do list that happens to have status on it.
 */
function InfraRow({ item }: { item: InfraItem }) {
  const { t, i18n } = useTranslation()
  const Icon = INFRA_ICON[item.key]

  return (
    <div className="grid gap-x-4 gap-y-1.5 px-4 py-3 md:grid-cols-12 md:items-center" data-testid={`infra-${item.key}`}>
      <h3 className="flex items-center gap-2 text-[0.875rem] font-semibold md:col-span-3">
        <Icon className="size-4 shrink-0 text-[var(--color-ink-muted)]" aria-hidden />
        {t(`readiness.infra.item.${item.key}`)}
        <Badge tone={INFRA_TONE[item.state]} testId={`infra-${item.key}-state`}>
          {t(`readiness.infra.state.${item.state}`)}
        </Badge>
      </h3>

      <dl className="flex min-w-0 gap-4 whitespace-nowrap text-[0.75rem] md:col-span-4">
        <div className="flex shrink-0 gap-1.5">
          <dt className="text-[var(--color-ink-muted)]">{t('readiness.infra.lastCheck')}</dt>
          <dd className="tabular-nums" data-testid={`infra-${item.key}-checked`}>{formatRelative(item.last_check, i18n.language)}</dd>
        </div>
        <div className="flex min-w-0 gap-1.5">
          <dt className="shrink-0 text-[var(--color-ink-muted)]">{t('readiness.infra.lastError')}</dt>
          <dd
            className={cn('min-w-0 truncate', item.last_error && 'font-mono text-[0.6875rem] text-[var(--color-danger)]')}
            title={item.last_error ?? undefined}
            data-testid={`infra-${item.key}-error`}
          >
            {item.last_error ?? t('readiness.infra.none')}
          </dd>
        </div>
      </dl>

      <p
        className={cn(
          'rounded-[var(--radius-control)] px-2.5 py-1.5 text-[0.75rem] md:col-span-5',
          item.required_action
            ? 'bg-[color-mix(in_srgb,var(--color-warning)_10%,transparent)]'
            : 'text-[var(--color-ink-muted)]',
        )}
        data-testid={`infra-${item.key}-action`}
      >
        {item.required_action
          ? t(`readiness.infra.action.${item.required_action}`, { defaultValue: item.required_action })
          : t('readiness.infra.nothingToDo')}
      </p>
    </div>
  )
}
