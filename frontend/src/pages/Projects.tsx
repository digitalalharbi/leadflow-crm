import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Pencil, Plus } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiPatch, apiPost, ApiError } from '@/lib/api'
import { fetchProjectsWithMetrics } from '@/features/leads'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Link } from 'react-router-dom'
import { formatCurrency, formatNumber } from '@/lib/format'
import {
  Badge, Button, Card, EmptyState, ErrorState, Input, Modal, PageHeader,
  Select, SkeletonTable,
} from '@/components/ui'
import type { Project, ProjectMetrics } from '@/types/api'

const EMPTY = { name: '', developer_name: '', location: '', starting_price: '', status: 'active' }

export function ProjectsPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const branding = useAuth((s) => s.branding)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [editing, setEditing] = useState<Project | null>(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(EMPTY)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['projects'],
    queryFn: fetchProjectsWithMetrics,
  })

  const mutation = useMutation({
    mutationFn: () => {
      const payload = {
        name: form.name,
        developer_name: form.developer_name || null,
        location: form.location || null,
        starting_price: form.starting_price ? Number(form.starting_price) : null,
        status: form.status,
      }

      return editing ? apiPatch(`/projects/${editing.id}`, payload) : apiPost('/projects', payload)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['projects'] })
      toast(t('settings.saved'), 'success')
      close()
    },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.status === 422 && caught.errors) {
        const next: Record<string, string> = {}
        for (const [field, messages] of Object.entries(caught.errors)) {
          if (messages[0]) next[field] = messages[0]
        }
        setFieldErrors(next)
      } else {
        toast(translateError(caught), 'error')
      }
    },
  })

  function openCreate() {
    setEditing(null)
    setForm(EMPTY)
    setFieldErrors({})
    setOpen(true)
  }

  function openEdit(project: Project) {
    setEditing(project)
    setForm({
      name: project.name,
      developer_name: project.developer_name ?? '',
      location: project.location ?? '',
      starting_price: project.starting_price?.toString() ?? '',
      status: project.status,
    })
    setFieldErrors({})
    setOpen(true)
  }

  function close() {
    setOpen(false)
    setEditing(null)
    setForm(EMPTY)
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setFieldErrors({})
    mutation.mutate()
  }

  const canManage = can.manageProjects(user)
  const currency = branding?.currency ?? 'SAR'

  const projects = data?.projects ?? []
  const metricsById = new Map((data?.metrics ?? []).map((row) => [row.id, row]))

  return (
    <>
      <PageHeader
        title={t('projects.title')}
        subtitle={t('projects.subtitle')}
        actions={
          canManage ? (
            <Button icon={<Plus className="size-4" />} onClick={openCreate}>
              {t('projects.addProject')}
            </Button>
          ) : undefined
        }
      />

      <Card className="overflow-hidden">
        {isError ? (
          <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
        ) : isLoading ? (
          <SkeletonTable rows={5} cols={5} />
        ) : projects.length === 0 ? (
          <EmptyState
            icon={<Building2 className="size-6" />}
            title={t('projects.emptyTitle')}
            body={t('projects.emptyBody')}
            actions={
              canManage ? (
                <Button icon={<Plus className="size-4" />} onClick={openCreate}>
                  {t('projects.addProject')}
                </Button>
              ) : undefined
            }
          />
        ) : (
          /*
            Cards rather than a table. A project is a business unit, and the
            question a developer has is which of theirs is selling — a row of
            one-line facts answers "what is it called", which nobody is asking.
          */
          <div className="grid gap-3 p-3 md:grid-cols-2 xl:grid-cols-3">
            {projects.map((project) => (
              <ProjectCard
                key={project.id}
                project={project}
                metrics={metricsById.get(project.id)}
                currency={currency}
                canManage={canManage}
                onEdit={() => openEdit(project)}
              />
            ))}
          </div>
        )}
      </Card>

      <Modal
        open={open}
        onClose={close}
        title={editing ? t('common.edit') : t('projects.addProject')}
        footer={
          <>
            <Button variant="ghost" onClick={close}>{t('common.cancel')}</Button>
            <Button type="submit" form="project-form" loading={mutation.isPending}>{t('common.save')}</Button>
          </>
        }
      >
        <form id="project-form" onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Input
            required
            label={t('projects.name')}
            value={form.name}
            error={fieldErrors.name}
            onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value }))}
          />
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              label={t('projects.developer')}
              hint={t('common.optional')}
              value={form.developer_name}
              onChange={(e) => setForm((prev) => ({ ...prev, developer_name: e.target.value }))}
            />
            <Input
              label={t('projects.location')}
              hint={t('common.optional')}
              value={form.location}
              onChange={(e) => setForm((prev) => ({ ...prev, location: e.target.value }))}
            />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              dir="ltr"
              inputMode="numeric"
              label={t('projects.startingPrice')}
              hint={t('common.optional')}
              value={form.starting_price}
              error={fieldErrors.starting_price}
              onChange={(e) => setForm((prev) => ({ ...prev, starting_price: e.target.value }))}
            />
            <Select
              label={t('common.status')}
              value={form.status}
              onChange={(e) => setForm((prev) => ({ ...prev, status: e.target.value }))}
            >
              <option value="active">{t('projects.statusActive')}</option>
              <option value="paused">{t('projects.statusPaused')}</option>
              <option value="sold_out">{t('projects.statusSoldOut')}</option>
            </Select>
          </div>
        </form>
      </Modal>
    </>
  )
}

/**
 * One project, as the numbers that say whether it is working.
 *
 * Every metric is a link into the leads it counts, so the card is a way in
 * rather than a summary to read and leave.
 */
function ProjectCard({
  project,
  metrics,
  currency,
  canManage,
  onEdit,
}: {
  project: Project
  metrics?: ProjectMetrics
  currency: string
  canManage: boolean
  onEdit: () => void
}) {
  const { t, i18n } = useTranslation()

  const leads = metrics?.leads ?? 0
  const qualified = metrics?.qualified ?? 0
  const appointments = metrics?.appointments ?? 0
  const won = metrics?.won ?? 0
  const rate = metrics?.qualification_rate ?? 0

  const tone =
    project.status === 'active' ? 'success' : project.status === 'paused' ? 'warning' : 'neutral'

  const statusLabel = t(
    project.status === 'active'
      ? 'projects.statusActive'
      : project.status === 'paused'
        ? 'projects.statusPaused'
        : 'projects.statusSoldOut',
  )

  return (
    <article
      className="flex flex-col rounded-[var(--radius-card)] border border-[var(--color-line)] bg-[var(--color-surface)] shadow-[var(--shadow-card)]"
      data-testid="project-card"
    >
      <header className="flex items-start justify-between gap-3 border-b border-[var(--color-line)] px-4 py-3">
        <div className="min-w-0">
          <h2 className="t-card-title truncate">{project.name}</h2>
          <p className="t-caption mt-0.5 truncate">
            {[project.location, project.developer_name].filter(Boolean).join(' · ') || '—'}
          </p>
        </div>

        <div className="flex shrink-0 items-center gap-1">
          <Badge tone={tone}>{statusLabel}</Badge>
          {canManage && (
            <Button variant="ghost" size="icon" aria-label={t('common.edit')} onClick={onEdit}>
              <Pencil className="size-4" />
            </Button>
          )}
        </div>
      </header>

      <div className="flex items-baseline justify-between gap-3 px-4 pt-3">
        <Link
          to={`/leads?project_id=${project.id}`}
          className="group min-w-0"
          data-testid="project-leads"
        >
          <span className="t-metric block group-hover:text-[var(--brand)]">
            {formatNumber(leads, i18n.language)}
          </span>
          <span className="t-caption">{t('projects.leadsCount')}</span>
        </Link>

        {project.starting_price != null && (
          <span className="text-end">
            <span className="tabular block text-[0.9375rem] font-semibold" dir="ltr">
              {formatCurrency(project.starting_price, currency, i18n.language)}
            </span>
            <span className="t-caption">{t('projects.startingPrice')}</span>
          </span>
        )}
      </div>

      {/* The conversion in one bar: how much of this project's interest is
          real, and how much of that closed. */}
      <div className="px-4 pt-3">
        <div className="flex h-2 w-full overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
          <span
            className="h-full transition-[width] duration-[var(--duration-slow)]"
            style={{
              width: `${leads > 0 ? (qualified / leads) * 100 : 0}%`,
              background: 'var(--chart-1)',
            }}
          />
          <span
            className="h-full transition-[width] duration-[var(--duration-slow)]"
            style={{
              width: `${leads > 0 ? (won / leads) * 100 : 0}%`,
              background: 'var(--color-success)',
            }}
          />
        </div>
      </div>

      <dl className="grid grid-cols-3 gap-2 px-4 py-3">
        <ProjectMetric
          label={t('campaignQuality.qualified')}
          value={qualified}
          hint={`${rate}%`}
          to={`/leads?project_id=${project.id}&qualification_status=qualified`}
          testId="project-qualified"
        />
        <ProjectMetric
          label={t('campaignQuality.appointments')}
          value={appointments}
          to={`/leads?project_id=${project.id}&status[]=appointment`}
        />
        <ProjectMetric
          label={t('campaignQuality.won')}
          value={won}
          to={`/leads?project_id=${project.id}&status[]=won`}
        />
      </dl>
    </article>
  )
}

function ProjectMetric({
  label,
  value,
  hint,
  to,
  testId,
}: {
  label: string
  value: number
  hint?: string
  to: string
  testId?: string
}) {
  const { i18n } = useTranslation()

  return (
    <Link
      to={to}
      data-testid={testId}
      className="rounded-[var(--radius-control)] px-2 py-1.5 transition-colors hover:bg-[var(--color-surface-sunken)]"
    >
      <dt className="t-caption truncate text-[0.6875rem]">{label}</dt>
      <dd className="mt-0.5 flex items-baseline gap-1.5">
        <span className="t-metric-sm">{formatNumber(value, i18n.language)}</span>
        {hint && (
          <span className="tabular text-[0.6875rem] text-[var(--color-ink-subtle)]" dir="ltr">
            {hint}
          </span>
        )}
      </dd>
    </Link>
  )
}
