import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Archive, ArrowDown, Check, CircleDashed, Database, FileText, Globe, Layers, Minus, ServerCog, ShieldCheck, Siren, X, Zap,
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
import { RUNG_COLOR } from '@/components/integrations/IntegrationGrid'
import { INTEGRATION_STATES, STATE_TONE } from '@/features/integrationState'
import {
  commission, fetchReadiness, type Evidence, type InfraItem, type ReadinessItem, type ReadinessReport, type ReadinessStatus,
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

/**
 * The four words the owner reads first, drawn from the ladder and never above it: nothing says LIVE until a real
 * counterpart has been observed, and something failing says so whatever else was once proven.
 */
const FAILING = ['commissioning_failed', 'failed_jobs_in_the_last_day', 'backup_failed']
type ReadinessWord = 'live' | 'partial' | 'awaiting_external' | 'error'
const WORD_TONE: Record<ReadinessWord, 'success' | 'warning' | 'neutral' | 'danger'> = { live: 'success', partial: 'warning', awaiting_external: 'neutral', error: 'danger' }

export function readinessWord(state: string, blocker: string | null | undefined): ReadinessWord {
  if (blocker && FAILING.includes(blocker)) return 'error'
  if (state === 'live_verified' || state === 'production_verified') return 'live'

  return state === 'ready' || state === 'sandbox_verified' ? 'partial' : 'awaiting_external'
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
          <DeploymentBanner deployment={data.deployment} />
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

/**
 * Local, Staging or Production — said first, because every rung below it is
 * only as meaningful as the place it was observed. Production needs a reachable
 * HTTPS address on a public name, not merely a setting.
 */
function DeploymentBanner({ deployment }: { deployment: ReadinessReport['deployment'] }) {
  const { t } = useTranslation()
  const production = deployment.kind === 'production'

  return (
    <Card className="mb-3" data-testid="deployment-banner">
      <div className="flex flex-wrap items-center gap-4 p-4">
        <span
          className={cn(
            'grid size-11 shrink-0 place-items-center rounded-full',
            production ? 'bg-[color-mix(in_srgb,var(--brand)_14%,transparent)] text-[var(--brand)]' : 'bg-[color-mix(in_srgb,var(--color-warning)_16%,transparent)] text-[color-mix(in_srgb,var(--color-warning)_80%,black)]',
          )}
          aria-hidden
        >
          <Globe className="size-5" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-[1.125rem] font-extrabold" data-testid="deployment-kind" data-kind={deployment.kind}>
            {t(`readiness.deployment.${deployment.kind}`)}
          </p>
          <p className="t-caption">{t(`readiness.deployment.hint.${deployment.kind}`)}</p>
        </div>
        <ul className="flex flex-wrap gap-1.5 text-[0.875rem]" dir="ltr">
          {[
            [deployment.environment, deployment.environment === 'production'],
            ['HTTPS', deployment.https],
            [t('readiness.deployment.publicName'), deployment.public_host],
          ].map(([label, ok]) => (
            <li key={String(label)} className={cn('flex items-center gap-1 rounded-full px-2.5 py-1 font-medium', ok ? 'bg-[color-mix(in_srgb,var(--brand)_12%,transparent)] text-[var(--brand)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')}>
              {ok ? <Check className="size-3" aria-hidden /> : <X className="size-3" aria-hidden />}
              {label}
            </li>
          ))}
        </ul>
      </div>
    </Card>
  )
}

/**
 * The answer before the evidence: how much of this deployment is proven, how
 * the rest splits between "configured, unproven" and "waiting on someone
 * outside", and the few rows standing between it and going live — each with
 * whose move it is. The cards below are the evidence for every one of these.
 */
function ReadinessHero({ report }: { report: ReadinessReport }) {
  const { t } = useTranslation()

  const total = report.items.length + report.infrastructure.length
  // Proven means seen working in production — the two rungs a deployment earns.
  const proven = (report.ladder.live_verified ?? 0) + (report.ladder.production_verified ?? 0)
  const percent = total > 0 ? Math.round((proven / total) * 100) : 0
  const blocking = report.items.filter((item) => item.state !== 'live_verified' && item.state !== 'production_verified')

  return (
    <Card className="mb-3" data-testid="readiness-summary">
      <div className="grid gap-5 p-5 lg:grid-cols-12 lg:items-center">
        <div className="flex items-center gap-4 lg:col-span-3">
          <RingProgress value={percent} size={104} thickness={10} tone={percent === 100 ? 'good' : 'warn'} label={t('readiness.hero.proven')} testId="readiness-ring">
            <span className="text-[1.5rem] font-extrabold tabular-nums" dir="ltr">{percent}%</span>
          </RingProgress>
          <div>
            <p className="text-[1.0625rem] font-semibold">{t('readiness.hero.proven')}</p>
            <p className="t-caption tabular-nums">{t('readiness.hero.ofTotal', { proven, total })}</p>
          </div>
        </div>

        <div className="lg:col-span-4">
          <LadderBar
            title={t('readiness.hero.ladder')}
            testId="readiness-ladder"
            steps={INTEGRATION_STATES.map((key) => ({ key, color: RUNG_COLOR[key], label: t(`integrationState.${key}`), count: report.ladder[key] ?? 0 }))}
          />
        </div>

        <div className="lg:col-span-5" data-testid="readiness-blocking">
          <p className="mb-2 text-[0.9375rem] font-semibold">{t('readiness.hero.blocking')}</p>
          {blocking.length === 0 ? (
            <p className="t-caption">{t('readiness.hero.nothingBlocking')}</p>
          ) : (
            <ul className="space-y-1.5">
              {blocking.slice(0, 4).map((item) => (
                <li key={item.key}>
                  <a href={`#readiness-${item.key}`} className="flex items-center gap-2.5 rounded-xl bg-[var(--color-surface-muted)] px-3 py-2 hover:bg-[var(--color-chrome-fill)]">
                    <span aria-hidden className="size-2 shrink-0 rounded-full" style={{ background: RUNG_COLOR[item.state] }} />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[0.9375rem] font-medium">{t(`readiness.item.${item.key}`)}</span>
                      {item.blocker && (
                        <span className="block truncate text-[0.8125rem] text-[var(--color-ink-muted)]">
                          {t(`readiness.blocker.${item.blocker}`, { defaultValue: item.blocker })}
                        </span>
                      )}
                    </span>
                    <span className="shrink-0 rounded-full bg-[var(--color-surface)] px-2 py-0.5 text-[0.8125rem] text-[var(--color-ink-muted)]">
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
      <p className="mb-1.5 text-[0.9375rem] font-semibold">{title}</p>
      <div className="flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-[var(--color-chrome-fill)]" dir="ltr">
        {sum > 0 &&
          steps.filter((step) => step.count > 0).map((step) => (
            <span key={step.key} className="h-full first:rounded-s-full last:rounded-e-full" data-chart-mark style={{ width: `${(step.count / sum) * 100}%`, background: step.color }} />
          ))}
      </div>
      <ul className="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5">
        {steps.map((step) => (
          <li key={step.key} className="flex items-center gap-1.5 text-[0.8125rem] text-[var(--color-ink-muted)]" data-testid={`${testId}-${step.key}`}>
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
          <span className="flex flex-wrap items-center justify-end gap-1.5">
            <Badge tone={WORD_TONE[readinessWord(item.state, item.blocker)]} testId={`readiness-${item.key}-word`}>{t(`readiness.word.${readinessWord(item.state, item.blocker)}`)}</Badge>
            <Badge tone={STATE_TONE[item.state]} testId={`readiness-${item.key}-status`}>
              {t(`integrationState.${item.state}`)}
            </Badge>
          </span>
        }
      />
      <CardBody className="space-y-3">
        <EvidenceList evidence={item.evidence} testId={`readiness-${item.key}-evidence`} />

        {item.checks.length > 0 && (
          <details className="group">
            <summary className="flex cursor-pointer list-none items-center gap-2 text-[0.875rem] font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">
              <span className="min-w-0 flex-1">
                <ProgressBar
                  value={(item.checks.filter((check) => check.ok).length / item.checks.length) * 100}
                  tone={item.checks.every((check) => check.ok) ? 'good' : 'warn'}
                  label={t('readiness.hero.checksPassed', { done: item.checks.filter((check) => check.ok).length, total: item.checks.length })}
                />
              </span>
              {t('readiness.hero.setupDetails')}
            </summary>
            <ul className="mt-2 space-y-1" data-testid={`readiness-${item.key}-checks`}>
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
          </details>
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
      <h3 className="flex items-center gap-2 text-[1rem] font-semibold md:col-span-3">
        <Icon className="size-4 shrink-0 text-[var(--color-ink-muted)]" aria-hidden />
        {t(`readiness.infra.item.${item.key}`)}
        <Badge tone={WORD_TONE[readinessWord(item.ladder, null)]} testId={`infra-${item.key}-word`}>{t(`readiness.word.${readinessWord(item.ladder, null)}`)}</Badge>
        <Badge tone={STATE_TONE[item.ladder]} testId={`infra-${item.key}-state`}>
          {t(`integrationState.${item.ladder}`)}
        </Badge>
      </h3>

      <dl className="flex min-w-0 gap-4 whitespace-nowrap text-[0.875rem] md:col-span-4">
        <div className="flex shrink-0 gap-1.5">
          <dt className="text-[var(--color-ink-muted)]">{t('readiness.infra.lastCheck')}</dt>
          <dd className="tabular-nums" data-testid={`infra-${item.key}-checked`}>{formatRelative(item.last_check, i18n.language)}</dd>
        </div>
        <div className="flex min-w-0 gap-1.5">
          <dt className="shrink-0 text-[var(--color-ink-muted)]">{t('readiness.infra.lastError')}</dt>
          <dd
            className={cn('min-w-0 truncate', item.last_error && 'font-mono text-[0.8125rem] text-[var(--color-danger)]')}
            title={item.last_error ?? undefined}
            data-testid={`infra-${item.key}-error`}
          >
            {item.last_error ?? t('readiness.infra.none')}
          </dd>
        </div>
      </dl>

      <p
        className={cn(
          'rounded-[var(--radius-control)] px-2.5 py-1.5 text-[0.875rem] md:col-span-5',
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

/**
 * What a rung stands on: each fact as a tick or a cross, and when it was seen.
 * A row's badge is computed from these, so a green rung with a red fact above
 * it cannot happen — and a reader can see exactly which fact is missing.
 */
function EvidenceList({ evidence, testId }: { evidence: Evidence[]; testId: string }) {
  const { t, i18n } = useTranslation()

  if (evidence.length === 0) return null

  return (
    <div data-testid={testId}>
      <p className="mb-1 text-[0.875rem] font-semibold text-[var(--color-ink-muted)]">{t('readiness.evidence.title')}</p>
      <ul className="space-y-1">
        {evidence.map((fact) => (
          <li key={fact.key} className="flex items-start gap-2 text-[0.9375rem]" data-evidence={fact.key} data-ok={fact.ok}>
            {fact.ok ? (
              <Check className="mt-0.5 size-3.5 shrink-0 text-[var(--color-success)]" aria-hidden />
            ) : (
              <X className="mt-0.5 size-3.5 shrink-0 text-[var(--color-ink-subtle)]" aria-hidden />
            )}
            <span className={cn('min-w-0 flex-1', !fact.ok && 'text-[var(--color-ink-muted)]')}>
              {t(`readiness.evidence.${fact.key}`, { defaultValue: fact.key })}
              {fact.detail && fact.key === 'recorded_in_production' && (
                <span className="ms-1.5 rounded-full bg-[var(--color-chrome-fill)] px-1.5 py-0.5 text-[0.8125rem]">{t(`readiness.deployment.${fact.detail}`, { defaultValue: fact.detail })}</span>
              )}
              {fact.detail && fact.key === 'no_current_error' && !fact.ok && (
                <span className="ms-1.5 font-mono text-[0.8125rem] text-[var(--color-danger)]" dir="ltr">{fact.detail}</span>
              )}
            </span>
            {fact.at && <span className="shrink-0 text-[0.8125rem] tabular-nums text-[var(--color-ink-subtle)]">{formatRelative(fact.at, i18n.language)}</span>}
          </li>
        ))}
      </ul>
    </div>
  )
}
