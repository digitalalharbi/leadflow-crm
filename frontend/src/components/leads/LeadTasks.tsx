import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Plus } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Badge, Button, Card, CardBody, CardHeader, Input, Select } from '@/components/ui'
import { completeTask, createTask, fetchTasks, type TaskPriority } from '@/features/tasks'
import { formatDateTime } from '@/lib/format'

const PRIORITY_TONE = { urgent: 'danger', high: 'warning', normal: 'neutral', low: 'neutral' } as const

/** The lead's open tasks — work with a due time that is not a call back — and a one-line way to add one. */
export function LeadTasks({ leadId }: { leadId: string }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [title, setTitle] = useState('')
  const [due, setDue] = useState('')
  const [priority, setPriority] = useState<TaskPriority>('normal')

  const key = ['tasks', 'lead', leadId]
  const { data } = useQuery({ queryKey: key, queryFn: () => fetchTasks({ lead: leadId, bucket: 'open' }) })
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['tasks'] })

  const add = useMutation({
    mutationFn: () => createTask({ title: title.trim(), lead: leadId, due_at: due ? new Date(due).toISOString() : null, priority }),
    onSuccess: () => {
      setTitle('')
      setDue('')
      setPriority('normal')
      refresh()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })
  const done = useMutation({ mutationFn: completeTask, onSuccess: refresh, onError: (caught) => toast(translateError(caught), 'error') })

  return (
    <Card data-testid="lead-tasks">
      <CardHeader title={t('tasks.title')} />
      <CardBody>
        <form
          className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_12rem_8rem_auto] sm:items-end"
          onSubmit={(e) => {
            e.preventDefault()
            if (title.trim()) add.mutate()
          }}
        >
          <Input label={t('tasks.new')} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={200} data-testid="task-title" />
          <Input label={t('tasks.due')} type="datetime-local" dir="ltr" value={due} onChange={(e) => setDue(e.target.value)} data-testid="task-due" />
          <Select label={t('tasks.priority')} value={priority} onChange={(e) => setPriority(e.target.value as TaskPriority)}>
            {(['urgent', 'high', 'normal', 'low'] as const).map((p) => <option key={p} value={p}>{t(`tasks.priorities.${p}`)}</option>)}
          </Select>
          <Button type="submit" icon={<Plus className="size-4" />} loading={add.isPending} disabled={!title.trim()} data-testid="task-add">{t('tasks.add')}</Button>
        </form>

        {data && data.length > 0 ? (
          <ul className="mt-4 divide-y divide-[var(--color-line)]" data-testid="task-list">
            {data.map((task) => (
              <li key={task.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                <div className="min-w-0">
                  <p className="font-medium">{task.title}</p>
                  <p className="t-caption">
                    {task.assignee?.name ?? t('tasks.unassigned')}
                    {task.due_at && <> · <span className={task.overdue ? 'text-[var(--color-danger)]' : undefined}>{formatDateTime(task.due_at, i18n.language)}</span></>}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  {task.priority !== 'normal' && <Badge tone={PRIORITY_TONE[task.priority]}>{t(`tasks.priorities.${task.priority}`)}</Badge>}
                  {task.overdue && <Badge tone="danger">{t('tasks.overdue')}</Badge>}
                  <Button size="sm" variant="ghost" icon={<Check className="size-4" />} loading={done.isPending && done.variables === task.id} onClick={() => done.mutate(task.id)} data-testid={`task-done-${task.id}`}>
                    {t('tasks.done')}
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <p className="t-caption mt-3">{t('tasks.empty')}</p>
        )}
      </CardBody>
    </Card>
  )
}
