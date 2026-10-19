import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Activity, AlertTriangle, Bot, Gauge, Play, Rocket, ShieldAlert, Sparkles, TrendingDown, Waves } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody } from '@/components/ui'
import { TONE_COLOR, TONE_TINT, type Tone } from '@/components/visual/tones'
import { runRoutine, useAgents, type AgentFinding } from '@/features/agents'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { formatRelative } from '@/lib/format'
import { cn } from '@/lib/cn'

const FINDING_ICON: Record<string, LucideIcon> = {
  no_results: ShieldAlert,
  over_target: Gauge,
  cpl_over_target: Gauge,
  outperforming: Rocket,
  quality_drop: TrendingDown, low_qualified_rate: TrendingDown, many_invalid: TrendingDown,
  spend_spike: Activity,
  over_ceiling: AlertTriangle,
  fatigue: Waves,
}

const TONE: Record<AgentFinding['severity'], Tone> = { bad: 'bad', warn: 'warn', good: 'good' }

/**
 * The agents at work: who they are, when each last ran, and what the analyst
 * saw — as cards, each pointing at its campaign. "Analyse now" runs the daily
 * routine on demand; whatever it wants changed lands in the inbox above, never
 * on the ad account directly.
 */
export function AgentsPanel() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const { data } = useAgents()

  const run = useMutation({
    mutationFn: () => runRoutine('daily_analysis'),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['agents'] })
      void queryClient.invalidateQueries({ queryKey: ['proposals'] })
      toast(t('agentsPanel.done'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (!data) return null

  const routine = (key: string) => data.routines.find((r) => r.key === key)

  return (
    <section data-testid="agents-panel">
      <div className="mb-2 flex items-center justify-between gap-2 px-1">
        <h2 className="t-card-title flex items-center gap-1.5">
          <Bot className="size-4 text-[var(--brand)]" aria-hidden />
          {t('agentsPanel.title')}
        </h2>
        <Button size="sm" variant="outline" icon={<Play className="size-4" />} loading={run.isPending} onClick={() => run.mutate()} data-testid="run-analysis">
          {t('agentsPanel.analyse')}
        </Button>
      </div>

      <div className="grid gap-3 lg:grid-cols-12">
        <Card className="lg:col-span-4">
          <CardBody className="space-y-2.5">
            {data.agents.map((agent) => {
              const mine = data.routines.filter((r) => r.agent === agent.key)
              const last = mine.map((r) => r.last_run_at).filter(Boolean).sort().at(-1) ?? null
              const failed = mine.some((r) => r.last_status === 'failed')

              return (
                <div key={agent.key} className="flex items-center gap-2.5" data-testid={`agent-${agent.key}`}>
                  <span className={cn('grid size-9 shrink-0 place-items-center rounded-full', agent.key === 'orchestrator' ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--brand-soft)] text-[var(--brand)]')} aria-hidden>
                    {agent.key === 'analyst' ? <Sparkles className="size-4" /> : <Bot className="size-4" />}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[0.8125rem] font-semibold">{agent.name}</span>
                    <span className="block truncate text-[0.6875rem] text-[var(--color-ink-subtle)]">
                      {last ? formatRelative(last, i18n.language) : t('agentsPanel.notYet')}
                    </span>
                  </span>
                  <span className={cn('size-2 rounded-full', failed ? 'bg-[var(--color-danger)]' : last ? 'bg-[var(--color-status-won)]' : 'bg-[var(--color-chrome-fill)]')} aria-label={failed ? t('agentsPanel.failed') : ''} />
                </div>
              )
            })}
            {routine('daily_analysis')?.last_summary && (
              <p className="border-t border-[var(--color-line)] pt-2 text-[0.6875rem] text-[var(--color-ink-muted)]">{routine('daily_analysis')?.last_summary}</p>
            )}
          </CardBody>
        </Card>

        <div className="lg:col-span-8">
          {data.findings.length === 0 ? (
            <Card>
              <CardBody>
                <p className="py-6 text-center text-[0.8125rem] text-[var(--color-ink-subtle)]">
                  {data.analysed_at ? t('agentsPanel.allClear') : t('agentsPanel.neverRan')}
                </p>
              </CardBody>
            </Card>
          ) : (
            <div className="grid gap-3 sm:grid-cols-2" data-testid="agent-findings">
              {data.findings.slice(0, 6).map((f, i) => {
                const Icon = FINDING_ICON[f.key] ?? Sparkles
                const tone = TONE[f.severity]

                return (
                  <Link
                    key={`${f.key}-${f.campaign.id}-${i}`}
                    to={`/campaigns/${f.campaign.id}`}
                    className="flex items-center gap-3 rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-raised)]"
                    data-testid={`finding-${f.key}`}
                  >
                    <span className="grid size-10 shrink-0 place-items-center rounded-full" style={{ background: TONE_TINT[tone], color: TONE_COLOR[tone] }} aria-hidden>
                      <Icon className="size-5" />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[0.8125rem] font-semibold">{t(`agentsPanel.finding.${f.key}`, { defaultValue: f.key })}</span>
                      <span className="block truncate text-[0.75rem] text-[var(--color-ink-muted)]" dir="auto">{f.campaign.name}</span>
                    </span>
                  </Link>
                )
              })}
            </div>
          )}
        </div>
      </div>
    </section>
  )
}
