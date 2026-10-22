import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Bot, CheckCircle2, CircleDashed, MessageSquareText, Timer, XCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, Input, Select, Skeleton } from '@/components/ui'
import { fetchProjectScoring, fetchRoutines, updateProjectScoring, type RoutineRow } from '@/features/automation'
import { useAgents } from '@/features/agents'
import { fetchProjects } from '@/features/leads'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatRelative } from '@/lib/format'
import { cn } from '@/lib/cn'

/** A cron line in words, for the few shapes the routines use. */
function cadenceWords(cadence: string, t: (k: string, o?: Record<string, unknown>) => string): string {
  if (!cadence.includes(' ')) return t(`automation.cadence.${cadence}`, { defaultValue: cadence })

  const [minute, hour, , , weekday] = cadence.split(' ')

  if (minute.startsWith('*/')) return t('automation.cadence.everyMinutes', { count: Number(minute.slice(2)) })
  if (hour === '*') return t('automation.cadence.hourly')
  if (hour.startsWith('*/')) return t('automation.cadence.everyHours', { count: Number(hour.slice(2)) })
  if (weekday !== '*') return t('automation.cadence.weekly', { time: `${hour.padStart(2, '0')}:${minute.padStart(2, '0')}` })

  return t('automation.cadence.dailyAt', { time: `${hour.padStart(2, '0')}:${minute.padStart(2, '0')}` })
}

/**
 * What runs on its own, and whether it has. A routine that has never run on
 * this server says so in plain words — a schedule on paper is not a routine.
 */
export function RoutinesPanel() {
  const { t, i18n } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['automation-routines'], queryFn: fetchRoutines })

  if (isLoading || !data) return <Skeleton className="h-64" />

  const groups: Array<{ kind: RoutineRow['kind']; rows: RoutineRow[] }> = [
    { kind: 'system', rows: data.routines.filter((r) => r.kind === 'system') },
    { kind: 'agent', rows: data.routines.filter((r) => r.kind === 'agent') },
  ]

  return (
    <div className="grid gap-3 lg:grid-cols-2" data-testid="routines-panel">
      {groups.map(({ kind, rows }) => (
        <Card key={kind}>
          <CardBody>
            <h2 className="t-card-title mb-3">{t(`automation.routines.${kind}`)}</h2>
            <ul className="space-y-2">
              {rows.map((row) => {
                const Icon = row.status === 'ok' ? CheckCircle2 : row.status === 'failed' ? XCircle : CircleDashed

                return (
                  <li key={row.key} className="flex items-center gap-3 rounded-xl bg-[var(--color-surface-muted)] px-3 py-2.5" data-testid={`routine-${row.key}`}>
                    <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--color-surface)] text-[var(--brand)]" aria-hidden>
                      {kind === 'agent' ? <Bot className="size-4" /> : <Timer className="size-4" />}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-[1rem] font-semibold">{t(`automation.routine.${row.key}`, { defaultValue: row.key })}</p>
                      <p className="t-caption truncate">
                        {cadenceWords(row.cadence, t)}
                        {row.agent ? ` · ${t(`agents.name.${row.agent}`, { defaultValue: row.agent })}` : ''}
                      </p>
                    </div>
                    <span className={cn('flex shrink-0 items-center gap-1 text-[0.875rem]', row.status === 'ok' ? 'text-[var(--brand)]' : row.status === 'failed' ? 'text-[var(--color-danger)]' : 'text-[var(--color-ink-subtle)]')}>
                      <Icon className="size-3.5" aria-hidden />
                      {row.last_run_at ? formatRelative(row.last_run_at, i18n.language) : t('automation.routines.notRun')}
                    </span>
                  </li>
                )
              })}
            </ul>
          </CardBody>
        </Card>
      ))}
    </div>
  )
}

/**
 * The agents, as the thinking behind the rules rather than chatbots: who they
 * are and what they look after. Asking one a question is a secondary door.
 */
export function AgentsPanel() {
  const { t } = useTranslation()
  const { data, isLoading } = useAgents()

  if (isLoading || !data) return <Skeleton className="h-48" />

  return (
    <div className="space-y-3" data-testid="agents-panel">
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {data.agents.map((agent) => (
          <Card key={agent.key}>
            <CardBody className="flex items-start gap-3">
              <span className="grid size-10 shrink-0 place-items-center rounded-full bg-[color-mix(in_srgb,var(--brand)_12%,transparent)] text-[var(--brand)]" aria-hidden>
                <Bot className="size-5" />
              </span>
              <div className="min-w-0">
                <p className="text-[1.0625rem] font-semibold">{agent.name}</p>
                <p className="t-caption line-clamp-2">{agent.role}</p>
                <p className={cn('mt-1 text-[0.8125rem] font-medium', agent.is_active ? 'text-[var(--brand)]' : 'text-[var(--color-ink-subtle)]')}>
                  {t(agent.is_active ? 'automation.agents.active' : 'automation.agents.paused')}
                </p>
              </div>
            </CardBody>
          </Card>
        ))}
      </div>
      <Link to="/agents" className="inline-flex items-center gap-1.5 text-[0.9375rem] font-medium text-[var(--brand)] hover:underline" data-testid="ask-agent">
        <MessageSquareText className="size-4" aria-hidden />
        {t('automation.agents.ask')}
      </Link>
    </div>
  )
}

const SCORING_KEYS = [
  'whatsapp_reply', 'email_open', 'email_click', 'brochure_open', 'qualification_completed',
  'appointment_request', 'no_reply_24h', 'priority_from', 'hot_from', 'warm_from',
] as const

/**
 * What each signal is worth for one project, and where its lines fall. Empty
 * keeps the workspace's value; each field says what that is.
 */
export function ScoringCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const { data: projects = [] } = useQuery({ queryKey: ['projects-list'], queryFn: fetchProjects })
  const [projectId, setProjectId] = useState('')
  const [draft, setDraft] = useState<Record<string, string>>({})
  const current = projectId || projects[0]?.id || ''

  const { data } = useQuery({
    queryKey: ['project-scoring', current],
    queryFn: () => fetchProjectScoring(current),
    enabled: Boolean(current),
  })

  const save = useMutation({
    mutationFn: () => updateProjectScoring(current, Object.fromEntries(Object.entries(draft).map(([k, v]) => [k, v === '' ? null : Number(v)]))),
    onSuccess: () => {
      setDraft({})
      void queryClient.invalidateQueries({ queryKey: ['project-scoring', current] })
      toast(t('common.saved'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (projects.length === 0) return null

  return (
    <Card data-testid="scoring-card">
      <CardBody className="space-y-3">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 className="t-card-title">{t('automation.scoring.title')}</h2>
            <p className="t-caption">{t('automation.scoring.hint')}</p>
          </div>
          <Select className="min-w-48" label={t('leads.project')} value={current} onChange={(e) => { setProjectId(e.target.value); setDraft({}) }} data-testid="scoring-project">
            {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
          </Select>
        </div>
        {data && (
          <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
            {SCORING_KEYS.map((key) => {
              const item = data.scoring[key]

              return (
                <Input
                  key={key}
                  type="number"
                  dir="ltr"
                  label={t(`automation.scoring.${key}`)}
                  hint={item?.custom ? t('automation.scoring.custom') : t('automation.scoring.default', { value: item?.default ?? '' })}
                  value={draft[key] ?? String(item?.value ?? '')}
                  onChange={(e) => setDraft((prev) => ({ ...prev, [key]: e.target.value }))}
                  data-testid={`scoring-${key}`}
                />
              )
            })}
          </div>
        )}
        <div className="flex justify-end">
          <Button size="sm" disabled={Object.keys(draft).length === 0} loading={save.isPending} onClick={() => save.mutate()} data-testid="save-scoring">
            {t('common.save')}
          </Button>
        </div>
      </CardBody>
    </Card>
  )
}
