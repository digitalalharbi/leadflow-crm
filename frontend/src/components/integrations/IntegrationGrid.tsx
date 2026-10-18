import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  GitBranch, Mail, Megaphone, MessageCircle, Music2, Phone, RefreshCw, Search, Sheet, Webhook,
  Ghost, Briefcase,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet, apiPost } from '@/lib/api'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatLastSeen } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Badge, Button, Card, CardBody, Skeleton } from '@/components/ui'
import { INTEGRATION_STATES, STATE_TONE, isProven, type IntegrationState } from '@/features/integrationState'

/** One glyph per source: enough to find it in a grid without reading. */
const ICON: Record<string, LucideIcon> = {
  meta: Megaphone,
  tiktok: Music2,
  google_ads: Search,
  snapchat: Ghost,
  linkedin: Briefcase,
  generic_webhook: Webhook,
  google_sheets: Sheet,
  whatsapp: MessageCircle,
  email: Mail,
  calls: Phone,
  github: GitBranch,
}

/**
 * The ladder's own colours, weakest to strongest. Grey until something real
 * has happened, then warming to green: the ribbon reads left to right as how
 * far the workspace has got, which is the question it is opened to answer.
 */
const RUNG_COLOR: Record<IntegrationState, string> = {
  not_configured: 'var(--color-border-strong)',
  code_verified: 'color-mix(in srgb, var(--color-ink-subtle) 70%, var(--color-surface))',
  sandbox_verified: 'var(--color-warning)',
  ready: 'var(--chart-1)',
  live_verified: 'var(--color-success)',
  production_verified: 'color-mix(in srgb, var(--color-success) 70%, black)',
}

/**
 * Every source on one screen.
 *
 * The old page made you scroll past a hero, a chart and a list of cards to find
 * out whether Meta was still posting. This is the whole answer in one grid: per
 * platform, what state it is in, when something last arrived, when we last
 * looked, and the one button that does anything about it.
 *
 * The tile is deliberately small and the same shape for every platform. A grid
 * where each cell is laid out differently is read cell by cell; a grid where
 * they match is read at a glance, which is the only way ten of them fit above
 * the fold.
 */
interface HealthRow {
  key: string
  kind: string
  connected: boolean
  configured: boolean
  last_event_at: string | null
  last_sync_at: string | null
  last_error: string | null
  events: number
  flow: 'webhook' | 'pull' | 'manual'
  verification: 'implemented' | 'externally_verified' | 'manual_only'
  /** The six-word ladder, computed on the server. */
  state: IntegrationState
  /**
   * Why this is not verified yet, or null once it is.
   *
   * "Unproven" is true and tells nobody what to do. This says whose move it is:
   * ours (the platform has no OAuth client), theirs (no credentials), or
   * nobody's (connected, waiting for the first real event).
   */
  blocker?:
    | 'credentials_missing'
    | 'platform_not_configured'
    | 'not_connected'
    | 'awaiting_first_event'
    | 'no_provider_selected'
    | null
}

export function IntegrationGrid({ onConfigure }: { onConfigure?: (key: string) => void }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const { data, isLoading } = useQuery({
    queryKey: ['integration-health'],
    queryFn: () => apiGet<{ integrations: HealthRow[] }>('/integrations/health'),
  })

  /*
   * One button for the sources that are pulled rather than pushed. The ones
   * that arrive by webhook have nothing to sync — asking them to would be a
   * button that looks like it did something.
   */
  const syncAll = useMutation({
    mutationFn: () => apiPost<{ sheets: number; repositories: number }>('/integrations/sync', {}),
    onSuccess: () => {
      toast(t('health.syncQueued'), 'success')
      void queryClient.invalidateQueries({ queryKey: ['integration-health'] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (isLoading) return <Skeleton className="h-56" />

  const rows = data?.integrations ?? []
  const lastEvent = rows
    .map((row) => row.last_event_at)
    .filter(Boolean)
    .sort()
    .at(-1)

  return (
    <div className="space-y-2.5">
      {/* The sync centre: the two facts about the whole workspace, and the one
          action that applies to all of it. */}
      <Card>
        <CardBody className="space-y-2 py-2.5">
          <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-x-5 gap-y-1">
            {/* "Set up", not "connected": a row with credentials typed into it is
                configured, and nothing more has been said. */}
            <Figure
              label={t('health.configuredCount')}
              value={`${rows.filter((row) => row.state !== 'not_configured').length}/${rows.length}`}
            />
            <Figure
              label={t('health.verifiedCount')}
              value={String(rows.filter((row) => isProven(row.state)).length)}
            />
            <Figure
              label={t('health.lastEventAny')}
              value={lastEvent ? formatLastSeen(lastEvent, i18n.language) : t('health.never')}
            />
          </div>

          <ul className="flex flex-wrap gap-x-3 gap-y-0.5" data-testid="ladder-legend">
            {INTEGRATION_STATES.map((state) => {
              const count = rows.filter((row) => row.state === state).length
              return count === 0 ? null : (
                <li key={state} className="flex items-center gap-1.5 text-[0.75rem] text-[var(--color-ink-muted)]">
                  <span aria-hidden className="size-2 rounded-full" style={{ background: RUNG_COLOR[state] }} />
                  {t(`integrationState.${state}`)}
                  <span className="font-semibold tabular-nums text-[var(--color-ink)]">{count}</span>
                </li>
              )
            })}
          </ul>

          <Button
            size="sm"
            variant="outline"
            loading={syncAll.isPending}
            onClick={() => syncAll.mutate()}
            data-testid="sync-all"
            icon={<RefreshCw className="size-3.5" />}
          >
            {t('health.syncAll')}
          </Button>
          </div>

          {/*
            How far along the whole workspace is, as one bar: each segment is a rung
            of the ladder, sized by how many integrations stand on it. Empty rungs
            take no room, and the legend names only what is there.
          */}
          <div
            className="flex h-1.5 w-full gap-0.5 overflow-hidden rounded-full"
            role="img"
            aria-label={t('health.ribbon')}
            data-testid="ladder-ribbon"
          >
            {INTEGRATION_STATES.map((state) => {
              const count = rows.filter((row) => row.state === state).length
              return count === 0 ? null : (
                <span
                  key={state}
                  style={{ flex: count, background: RUNG_COLOR[state] }}
                  data-testid={`ribbon-${state}`}
                />
              )
            })}
          </div>
        </CardBody>
      </Card>

      <div
        /*
         * Six across from the laptop width up: eleven integrations in rows of
         * five is three rows, and the third is the one that pushed this page
         * past a 768px screen. Two full rows is the point of the grid — the
         * answer to "is anything wrong" without a scroll.
         */
        className="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6"
        data-testid="integration-grid"
      >
        {rows.map((row) => (
          <button
            key={row.key}
            type="button"
            onClick={() => onConfigure?.(row.key)}
            data-testid={`integration-tile-${row.key}`}
            className={cn(
              'pressable relative overflow-hidden rounded-[var(--radius-card)] border bg-[var(--color-surface)] p-2.5 ps-3 text-start',
              row.last_error
                ? 'border-[var(--color-danger)]/40'
                : isProven(row.state)
                  ? 'border-[var(--color-success)]/35'
                  : 'border-[var(--color-line)]',
            )}
          >
            {/* The rung's colour down the leading edge: readable before the word. */}
            <span
              aria-hidden
              className="absolute inset-y-0 start-0 w-1"
              style={{ background: RUNG_COLOR[row.state] }}
            />
            <div className="flex items-center justify-between gap-1.5">
              <span className="flex min-w-0 items-center gap-1.5">
                {(() => {
                  const Icon = ICON[row.key] ?? Webhook
                  return <Icon className="size-3.5 shrink-0 text-[var(--color-ink-muted)]" aria-hidden />
                })()}
                <span className="t-small truncate font-semibold">
                  {t(`health.name.${row.key}`, { defaultValue: row.key })}
                </span>
              </span>
              <span
                aria-hidden
                className={cn(
                  'size-2 shrink-0 rounded-full',
                  row.last_error
                    ? 'bg-[var(--color-danger)]'
                    : isProven(row.state)
                      ? 'bg-[var(--color-success)]'
                      : 'bg-[var(--color-ink-subtle)]/40',
                )}
              />
            </div>

            <Badge tone={STATE_TONE[row.state]} className="mt-1.5" testId={`state-${row.key}`}>
              {t(`integrationState.${row.state}`)}
            </Badge>

            {!isProven(row.state) && row.blocker && (
              <p className="t-caption mt-1" data-testid={`blocker-${row.key}`}>
                {t(`health.blocker.${row.blocker}`)}
              </p>
            )}

            <dl className="mt-1.5 space-y-0.5">
              <Line label={t('health.lastEvent')} value={row.last_event_at} />
              <Line label={t('health.lastSync')} value={row.last_sync_at} />
            </dl>

            <p className="t-caption mt-1 truncate">
              {t(`health.flow.${row.flow}`)}
              {row.events > 0 && ` · ${row.events}`}
            </p>

            {row.last_error && (
              <p className="t-caption mt-0.5 truncate text-[var(--color-danger)]">{row.last_error}</p>
            )}
          </button>
        ))}
      </div>
    </div>
  )

  function Line({ label, value }: { label: string; value: string | null }) {
    return (
      <div className="flex items-baseline justify-between gap-1.5">
        <dt className="t-caption truncate">{label}</dt>
        <dd className="t-caption shrink-0">
          {value ? formatLastSeen(value, i18n.language) : '—'}
        </dd>
      </div>
    )
  }
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline gap-1.5">
      <span className="t-caption">{label}</span>
      <span className="text-[0.9375rem] font-semibold tabular-nums">{value}</span>
    </div>
  )
}


