import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  DndContext, DragOverlay, PointerSensor, useDraggable, useDroppable,
  useSensor, useSensors, type DragEndEvent, type DragStartEvent,
} from '@dnd-kit/core'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { changeLeadStatus, fetchLostReasons } from '@/features/leads'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import {
  Button, Card, ErrorState, Modal, PageHeader, Select, Skeleton, Textarea, TemperatureBadge,
} from '@/components/ui'
import type { LeadListItem, LeadStatus } from '@/types/api'
import { cn } from '@/lib/cn'

interface Column {
  status: LeadStatus
  position: number
  total: number
  leads: LeadListItem[]
}

const COLUMN_ACCENT: Record<LeadStatus, string> = {
  new: 'bg-[var(--color-status-new)]',
  contacted: 'bg-[var(--color-status-contacted)]',
  qualified: 'bg-[var(--color-status-qualified)]',
  appointment: 'bg-[var(--color-status-appointment)]',
  won: 'bg-[var(--color-status-won)]',
  lost: 'bg-[var(--color-status-lost)]',
}

export function PipelinePage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [dragging, setDragging] = useState<LeadListItem | null>(null)
  const [lostTarget, setLostTarget] = useState<LeadListItem | null>(null)
  const [lostReasonId, setLostReasonId] = useState('')
  const [lostNote, setLostNote] = useState('')

  // A small activation distance keeps a tap on a card working as a link.
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }))

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['pipeline'],
    queryFn: () => apiGet<{ columns: Column[] }>('/pipeline'),
  })

  const { data: lostReasons = [] } = useQuery({
    queryKey: ['lost-reasons'],
    queryFn: fetchLostReasons,
    enabled: Boolean(lostTarget),
  })

  const moveMutation = useMutation({
    mutationFn: ({ id, status, extra }: { id: string; status: LeadStatus; extra?: Record<string, string> }) =>
      changeLeadStatus(id, status, extra),
    onMutate: async ({ id, status }) => {
      // Optimistic move: the card lands instantly, and is rolled back on failure.
      await queryClient.cancelQueries({ queryKey: ['pipeline'] })
      const previous = queryClient.getQueryData<{ columns: Column[] }>(['pipeline'])

      queryClient.setQueryData<{ columns: Column[] }>(['pipeline'], (current) => {
        if (!current) return current

        let moved: LeadListItem | undefined

        const stripped = current.columns.map((column) => {
          const found = column.leads.find((lead) => lead.id === id)
          if (!found) return column
          moved = found
          return { ...column, leads: column.leads.filter((lead) => lead.id !== id), total: column.total - 1 }
        })

        if (!moved) return current
        const card = { ...moved, status }

        return {
          columns: stripped.map((column) =>
            column.status === status
              ? { ...column, leads: [card, ...column.leads], total: column.total + 1 }
              : column,
          ),
        }
      })

      return { previous }
    },
    onError: (caught, _variables, context) => {
      if (context?.previous) queryClient.setQueryData(['pipeline'], context.previous)
      toast(translateError(caught) || t('pipeline.moveError'), 'error')
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ['pipeline'] })
      void queryClient.invalidateQueries({ queryKey: ['leads'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      setLostTarget(null)
      setLostReasonId('')
      setLostNote('')
    },
  })

  function handleDragStart(event: DragStartEvent) {
    setDragging((event.active.data.current as { lead: LeadListItem } | undefined)?.lead ?? null)
  }

  function handleDragEnd(event: DragEndEvent) {
    setDragging(null)

    const lead = (event.active.data.current as { lead: LeadListItem } | undefined)?.lead
    const target = event.over?.id as LeadStatus | undefined

    if (!lead || !target || lead.status === target) return

    // Lost needs a reason, so intercept the drop and ask first.
    if (target === 'lost') {
      setLostTarget(lead)
      return
    }

    moveMutation.mutate({ id: lead.id, status: target })
  }

  if (isError) {
    return (
      <>
        <PageHeader title={t('pipeline.title')} />
        <Card><ErrorState message={translateError(error)} onRetry={() => void refetch()} /></Card>
      </>
    )
  }

  return (
    <>
      <PageHeader title={t('pipeline.title')} subtitle={t('pipeline.subtitle')} />

      {isLoading || !data ? (
        <div className="scroll-x flex gap-3 pb-3" aria-busy="true">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-[28rem] w-[17rem] shrink-0" />
          ))}
        </div>
      ) : (
        <DndContext sensors={sensors} onDragStart={handleDragStart} onDragEnd={handleDragEnd}>
          <div className="scroll-x flex gap-3 pb-4">
            {data.columns.map((column) => (
              <PipelineColumn key={column.status} column={column} />
            ))}
          </div>

          <DragOverlay>{dragging ? <LeadCard lead={dragging} overlay /> : null}</DragOverlay>
        </DndContext>
      )}

      <Modal
        open={Boolean(lostTarget)}
        onClose={() => setLostTarget(null)}
        title={t('status.lost')}
        description={t('leads.lostReasonRequired')}
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setLostTarget(null)}>{t('common.cancel')}</Button>
            <Button
              variant="danger"
              disabled={!lostReasonId}
              loading={moveMutation.isPending}
              onClick={() =>
                lostTarget &&
                moveMutation.mutate({
                  id: lostTarget.id,
                  status: 'lost',
                  extra: { lost_reason_id: lostReasonId, ...(lostNote ? { lost_note: lostNote } : {}) },
                })
              }
            >
              {t('common.confirm')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <p className="text-[0.875rem] font-medium">{lostTarget?.full_name}</p>
          <Select
            label={t('leads.lostReason')}
            value={lostReasonId}
            onChange={(e) => setLostReasonId(e.target.value)}
          >
            <option value="">—</option>
            {lostReasons.map((reason) => (
              <option key={reason.id} value={reason.id}>{reason.label}</option>
            ))}
          </Select>
          <Textarea
            label={t('leads.lostNote')}
            hint={t('common.optional')}
            value={lostNote}
            onChange={(e) => setLostNote(e.target.value)}
          />
        </div>
      </Modal>
    </>
  )
}

function PipelineColumn({ column }: { column: Column }) {
  const { t } = useTranslation()
  const { setNodeRef, isOver } = useDroppable({ id: column.status })

  return (
    <section
      ref={setNodeRef}
      data-testid={`pipeline-column-${column.status}`}
      className={cn(
        'flex w-[17rem] shrink-0 flex-col rounded-[var(--radius-card)] border bg-[var(--color-surface-muted)]',
        isOver ? 'border-[var(--brand)] bg-[var(--brand-soft)]' : 'border-[var(--color-border)]',
      )}
    >
      <header className="flex items-center gap-2 border-b border-[var(--color-border)] px-3 py-2.5">
        <span className={cn('size-2 rounded-full', COLUMN_ACCENT[column.status])} aria-hidden />
        <h2 className="flex-1 text-[0.8125rem] font-semibold">{t(`status.${column.status}`)}</h2>
        <span
          data-testid="column-total"
          className="tabular rounded-md bg-[var(--color-surface)] px-1.5 py-0.5 text-[0.75rem] font-medium text-[var(--color-ink-muted)]"
        >
          {column.total}
        </span>
      </header>

      <div className="min-h-24 flex-1 space-y-2 overflow-y-auto p-2" style={{ maxHeight: 'calc(100dvh - 15rem)' }}>
        {column.leads.length === 0 ? (
          <p className="px-2 py-8 text-center text-[0.75rem] text-[var(--color-ink-subtle)]">
            {t('pipeline.empty')}
          </p>
        ) : (
          column.leads.map((lead) => <DraggableLead key={lead.id} lead={lead} />)
        )}

        {column.total > column.leads.length && (
          <p className="px-2 py-2 text-center text-[0.6875rem] text-[var(--color-ink-subtle)]">
            {t('pipeline.showing', { shown: column.leads.length, total: column.total })}
          </p>
        )}
      </div>
    </section>
  )
}

function DraggableLead({ lead }: { lead: LeadListItem }) {
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: lead.id,
    data: { lead },
  })

  return (
    <div ref={setNodeRef} {...listeners} {...attributes} className={cn(isDragging && 'opacity-40')}>
      <LeadCard lead={lead} />
    </div>
  )
}

function LeadCard({ lead, overlay }: { lead: LeadListItem; overlay?: boolean }) {
  const { t } = useTranslation()

  return (
    <article
      data-testid="pipeline-card"
      className={cn(
        'rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-2.5',
        overlay ? 'shadow-[var(--shadow-pop)] rotate-1' : 'shadow-[var(--shadow-card)]',
      )}
    >
      <Link
        to={`/leads/${lead.id}`}
        onPointerDown={(e) => e.stopPropagation()}
        className="block truncate text-[0.8125rem] font-medium hover:text-[var(--brand)]"
      >
        {lead.full_name}
      </Link>

      <p className="tabular mt-0.5 truncate text-[0.75rem] text-[var(--color-ink-muted)]" dir="ltr">
        {lead.phone ?? '—'}
      </p>

      {lead.project?.name && (
        <p className="mt-1 truncate text-[0.6875rem] text-[var(--color-ink-subtle)]">{lead.project.name}</p>
      )}

      <div className="mt-2 flex flex-wrap items-center gap-1.5">
        <TemperatureBadge temperature={lead.temperature} />
        {lead.is_overdue && (
          <span className="rounded-md bg-[color-mix(in_srgb,var(--color-status-lost)_12%,transparent)] px-1.5 py-0.5 text-[0.6875rem] font-medium text-[var(--color-status-lost)]">
            {t('leads.overdue')}
          </span>
        )}
      </div>

      {lead.assignee && (
        <p className="mt-1.5 truncate text-[0.6875rem] text-[var(--color-ink-subtle)]">{lead.assignee.name}</p>
      )}
    </article>
  )
}
