import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Pencil, Plus } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiPatch, apiPost, ApiError } from '@/lib/api'
import { fetchProjects } from '@/features/leads'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatCurrency, formatNumber } from '@/lib/format'
import {
  Badge, Button, Card, EmptyState, ErrorState, Input, Modal, PageHeader,
  Select, SkeletonTable, Table, TableWrap, Td, Th, Tr,
} from '@/components/ui'
import type { Project } from '@/types/api'

const EMPTY = { name: '', developer_name: '', location: '', starting_price: '', status: 'active' }

export function ProjectsPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const branding = useAuth((s) => s.branding)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [editing, setEditing] = useState<Project | null>(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(EMPTY)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const { data: projects = [], isLoading, isError, error, refetch } = useQuery({
    queryKey: ['projects'],
    queryFn: fetchProjects,
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
          <TableWrap>
            <Table>
              <thead>
                <tr>
                  <Th>{t('projects.name')}</Th>
                  <Th className="hidden sm:table-cell">{t('projects.developer')}</Th>
                  <Th className="hidden md:table-cell">{t('projects.location')}</Th>
                  <Th className="hidden lg:table-cell">{t('projects.startingPrice')}</Th>
                  <Th>{t('projects.leadsCount')}</Th>
                  <Th>{t('common.status')}</Th>
                  {canManage && <Th className="text-end">{t('common.actions')}</Th>}
                </tr>
              </thead>
              <tbody>
                {projects.map((project) => (
                  <Tr key={project.id}>
                    <Td className="font-medium">{project.name}</Td>
                    <Td className="hidden sm:table-cell">{project.developer_name ?? '—'}</Td>
                    <Td className="hidden md:table-cell">{project.location ?? '—'}</Td>
                    <Td className="tabular hidden whitespace-nowrap lg:table-cell">
                      {formatCurrency(project.starting_price, currency, i18n.language)}
                    </Td>
                    <Td className="tabular">{formatNumber(project.leads_count ?? 0, i18n.language)}</Td>
                    <Td>
                      <Badge
                        tone={
                          project.status === 'active' ? 'success' : project.status === 'paused' ? 'warning' : 'neutral'
                        }
                      >
                        {t(
                          project.status === 'active'
                            ? 'projects.statusActive'
                            : project.status === 'paused'
                              ? 'projects.statusPaused'
                              : 'projects.statusSoldOut',
                        )}
                      </Badge>
                    </Td>
                    {canManage && (
                      <Td className="text-end">
                        <Button variant="ghost" size="icon" aria-label={t('common.edit')} onClick={() => openEdit(project)}>
                          <Pencil className="size-4" />
                        </Button>
                      </Td>
                    )}
                  </Tr>
                ))}
              </tbody>
            </Table>
          </TableWrap>
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
