import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Users2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { ApiError, apiGet, apiPatch, apiPost } from '@/lib/api'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatNumber, formatLastSeen } from '@/lib/format'
import { fetchSalesTeam } from '@/features/conversations'
import {
  Badge, Button, Card, EmptyState, ErrorState, Input, Modal, PageHeader,
  Select, SkeletonTable, Table, TableWrap, Td, Th, Tr,
} from '@/components/ui'
import type { User } from '@/types/api'

export function UsersPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const currentUser = useAuth((s) => s.user)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<User | null>(null)
  const [form, setForm] = useState({ name: '', email: '', password: '', role: 'sales', phone: '' })
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})


  const canManage = can.manageUsers(currentUser)

  /*
   * The outcome columns come from the sales-team report rather than a second
   * per-user endpoint — it already computes exactly these figures under the
   * same visibility rules, and two sources would eventually disagree.
   */
  const { data: team } = useQuery({
    queryKey: ['users-metrics'],
    queryFn: () => fetchSalesTeam(),
    enabled: canManage,
  })

  const metricsByName = new Map((team?.rows ?? []).map((row) => [row.name, row]))

  const { data: users = [], isLoading, isError, error, refetch } = useQuery({
    queryKey: ['users'],
    queryFn: () => apiGet<{ users: User[] }>('/users').then((r) => r.users),
  })

  const mutation = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = {
        name: form.name,
        email: form.email,
        role: form.role,
        phone: form.phone || null,
      }

      if (form.password) payload.password = form.password

      return editing ? apiPatch(`/users/${editing.id}`, payload) : apiPost('/users', payload)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      void queryClient.invalidateQueries({ queryKey: ['assignable-users'] })
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

  const toggleActive = useMutation({
    mutationFn: (user: User) => apiPatch(`/users/${user.id}`, { is_active: !user.is_active }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      toast(t('settings.saved'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  function openCreate() {
    setEditing(null)
    setForm({ name: '', email: '', password: '', role: 'sales', phone: '' })
    setFieldErrors({})
    setOpen(true)
  }

  function openEdit(user: User) {
    setEditing(user)
    setForm({ name: user.name, email: user.email, password: '', role: user.role, phone: user.phone ?? '' })
    setFieldErrors({})
    setOpen(true)
  }

  function close() {
    setOpen(false)
    setEditing(null)
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setFieldErrors({})
    mutation.mutate()
  }


  return (
    <>
      <PageHeader
        title={t('users.title')}
        subtitle={t('users.subtitle')}
        actions={
          canManage ? (
            <Button icon={<Plus className="size-4" />} onClick={openCreate}>{t('users.addUser')}</Button>
          ) : undefined
        }
      />

      <Card className="overflow-hidden">
        {isError ? (
          <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
        ) : isLoading ? (
          <SkeletonTable rows={5} cols={5} />
        ) : users.length === 0 ? (
          <EmptyState
            icon={<Users2 className="size-6" />}
            title={t('users.emptyTitle')}
            body={t('users.emptyBody')}
            actions={canManage ? <Button onClick={openCreate}>{t('users.addUser')}</Button> : undefined}
          />
        ) : (
          <TableWrap>
            <Table>
              <thead>
                <tr>
                  <Th>{t('users.name')}</Th>
                  <Th>{t('users.role')}</Th>
                  <Th className="hidden md:table-cell">{t('users.leadsAssigned')}</Th>
                  <Th className="hidden md:table-cell">{t('campaignQuality.qualified')}</Th>
                  <Th className="hidden lg:table-cell">{t('campaignQuality.won')}</Th>
                  <Th className="hidden xl:table-cell">{t('users.lastLogin')}</Th>
                  <Th>{t('common.status')}</Th>
                  {canManage && <Th className="text-end">{t('common.actions')}</Th>}
                </tr>
              </thead>
              <tbody>
                {users.map((user) => (
                  <Tr key={user.id}>
                    {/* Name and email together: two columns of one-line facts
                        made the eye cross the table to identify one person. */}
                    <Td>
                      <span className="block font-medium">
                        {user.name}
                        {user.id === currentUser?.id && (
                          <span className="t-caption ms-2 font-normal">({t('nav.profile')})</span>
                        )}
                      </span>
                      {/* The email keeps Latin order but not Latin
                          alignment — `dir` on the block itself would pull it to
                          the far side and leave a ragged gap under the name. */}
                      <span className="t-caption block truncate text-[0.75rem]">
                        <bdi dir="ltr">{user.email}</bdi>
                      </span>
                    </Td>
                    <Td>{t(`roles.${user.role}`)}</Td>
                    <Td className="tabular hidden md:table-cell">
                      {formatNumber(user.leads_count ?? 0, i18n.language)}
                    </Td>
                    <Td className="tabular hidden md:table-cell">
                      {formatNumber(metricsByName.get(user.name)?.qualified ?? 0, i18n.language)}
                    </Td>
                    <Td className="tabular hidden font-medium text-[var(--color-status-won)] lg:table-cell">
                      {formatNumber(metricsByName.get(user.name)?.won ?? 0, i18n.language)}
                    </Td>
                    <Td className="hidden xl:table-cell">
                      {user.last_login_at ? formatLastSeen(user.last_login_at, i18n.language) : '—'}
                    </Td>
                    <Td>
                      <Badge tone={user.is_active ? 'success' : 'neutral'}>
                        {user.is_active ? t('common.active') : t('common.inactive')}
                      </Badge>
                    </Td>
                    {canManage && (
                      <Td>
                        <div className="flex items-center justify-end gap-1">
                          <Button variant="ghost" size="icon" aria-label={t('common.edit')} onClick={() => openEdit(user)}>
                            <Pencil className="size-4" />
                          </Button>
                          {user.id !== currentUser?.id && (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => toggleActive.mutate(user)}
                              disabled={toggleActive.isPending}
                            >
                              {user.is_active ? t('users.deactivate') : t('users.activate')}
                            </Button>
                          )}
                        </div>
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
        title={editing ? t('common.edit') : t('users.addUser')}
        footer={
          <>
            <Button variant="ghost" onClick={close}>{t('common.cancel')}</Button>
            <Button type="submit" form="user-form" loading={mutation.isPending}>{t('common.save')}</Button>
          </>
        }
      >
        <form id="user-form" onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Input
            required
            label={t('users.name')}
            value={form.name}
            error={fieldErrors.name}
            onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value }))}
          />
          <Input
            required
            dir="ltr"
            type="email"
            label={t('auth.email')}
            value={form.email}
            error={fieldErrors.email}
            onChange={(e) => setForm((prev) => ({ ...prev, email: e.target.value }))}
          />
          <Input
            dir="ltr"
            type="password"
            autoComplete="new-password"
            label={t('auth.password')}
            hint={editing ? t('common.optional') : undefined}
            required={!editing}
            value={form.password}
            error={fieldErrors.password}
            onChange={(e) => setForm((prev) => ({ ...prev, password: e.target.value }))}
          />
          <div className="grid gap-4 sm:grid-cols-2">
            <Select
              label={t('users.role')}
              value={form.role}
              onChange={(e) => setForm((prev) => ({ ...prev, role: e.target.value }))}
              disabled={editing?.id === currentUser?.id}
            >
              <option value="sales">{t('roles.sales')}</option>
              <option value="manager">{t('roles.manager')}</option>
              <option value="admin">{t('roles.admin')}</option>
            </Select>
            <Input
              dir="ltr"
              label={t('leads.phone')}
              hint={t('common.optional')}
              value={form.phone}
              onChange={(e) => setForm((prev) => ({ ...prev, phone: e.target.value }))}
            />
          </div>
        </form>
      </Modal>
    </>
  )
}
