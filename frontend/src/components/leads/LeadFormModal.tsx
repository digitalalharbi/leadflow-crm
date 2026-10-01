import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ApiError } from '@/lib/api'
import { createLead, fetchAssignableUsers, fetchProjects } from '@/features/leads'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Button, Input, Modal, Select } from '@/components/ui'

export function LeadFormModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [form, setForm] = useState({
    full_name: '',
    phone: '',
    email: '',
    city: '',
    project_id: '',
    assigned_to: '',
    budget_max: '',
    preferred_property_type: '',
  })
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const { data: projects = [] } = useQuery({ queryKey: ['projects'], queryFn: fetchProjects, enabled: open })
  const { data: users = [] } = useQuery({
    queryKey: ['assignable-users'],
    queryFn: fetchAssignableUsers,
    enabled: open && can.assignLeads(user),
  })

  const mutation = useMutation({
    mutationFn: () =>
      createLead({
        full_name: form.full_name,
        phone: form.phone || undefined,
        email: form.email || undefined,
        city: form.city || undefined,
        project_id: form.project_id || undefined,
        assigned_to: form.assigned_to || undefined,
        budget_max: form.budget_max ? Number(form.budget_max) : undefined,
        preferred_property_type: form.preferred_property_type || undefined,
      }),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['leads'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })

      // A returning phone number is not an error — say plainly what happened.
      toast(result.duplicate ? t('leads.duplicateNotice') : t('leads.createdSuccess'), result.duplicate ? 'info' : 'success')
      reset()
      onClose()
    },
    onError: (error) => {
      if (error instanceof ApiError && error.status === 422 && error.errors) {
        const next: Record<string, string> = {}
        for (const [field, messages] of Object.entries(error.errors)) {
          if (messages[0]) next[field] = messages[0]
        }
        setFieldErrors(next)
      } else {
        toast(translateError(error), 'error')
      }
    },
  })

  function reset() {
    setForm({
      full_name: '', phone: '', email: '', city: '',
      project_id: '', assigned_to: '', budget_max: '', preferred_property_type: '',
    })
    setFieldErrors({})
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setFieldErrors({})
    mutation.mutate()
  }

  return (
    <Modal
      open={open}
      onClose={() => {
        reset()
        onClose()
      }}
      title={t('leads.addLead')}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" form="lead-form" loading={mutation.isPending}>{t('common.create')}</Button>
        </>
      }
    >
      <form id="lead-form" onSubmit={handleSubmit} className="space-y-4" noValidate>
        <Input
          required
          label={t('leads.name')}
          value={form.full_name}
          error={fieldErrors.full_name}
          onChange={(e) => setForm({ ...form, full_name: e.target.value })}
        />

        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            dir="ltr"
            inputMode="tel"
            label={t('leads.phone')}
            placeholder="05xxxxxxxx"
            value={form.phone}
            error={fieldErrors.phone}
            onChange={(e) => setForm({ ...form, phone: e.target.value })}
          />
          <Input
            dir="ltr"
            type="email"
            label={t('leads.email')}
            hint={t('common.optional')}
            value={form.email}
            error={fieldErrors.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
          />
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            label={t('leads.city')}
            hint={t('common.optional')}
            value={form.city}
            onChange={(e) => setForm({ ...form, city: e.target.value })}
          />
          <Select
            label={t('leads.project')}
            hint={t('common.optional')}
            value={form.project_id}
            onChange={(e) => setForm({ ...form, project_id: e.target.value })}
          >
            <option value="">{t('leads.selectProject')}</option>
            {projects.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </Select>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            dir="ltr"
            inputMode="numeric"
            label={t('leads.budget')}
            hint={t('common.optional')}
            value={form.budget_max}
            error={fieldErrors.budget_max}
            onChange={(e) => setForm({ ...form, budget_max: e.target.value })}
          />
          <Input
            label={t('leads.propertyType')}
            hint={t('common.optional')}
            value={form.preferred_property_type}
            onChange={(e) => setForm({ ...form, preferred_property_type: e.target.value })}
          />
        </div>

        {can.assignLeads(user) && (
          <Select
            label={t('leads.assignedTo')}
            hint={t('common.optional')}
            value={form.assigned_to}
            onChange={(e) => setForm({ ...form, assigned_to: e.target.value })}
          >
            <option value="">{t('leads.selectAssignee')}</option>
            {users.map((u) => (
              <option key={u.id} value={u.id}>{u.name}</option>
            ))}
          </Select>
        )}
      </form>
    </Modal>
  )
}
