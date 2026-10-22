import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FolderLock, Pencil, Plus, ShieldCheck, Users2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { ApiError, apiGet, apiPatch, apiPost } from '@/lib/api'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatNumber, formatLastSeen } from '@/lib/format'
import { cn } from '@/lib/cn'
import { fetchSalesTeam } from '@/features/conversations'
import {
  Badge, Button, ButtonLink, Card, EmptyState, ErrorState, Input, Modal, PageHeader,
  Select, SkeletonTable, Table, TableWrap, Td, Th, Tr,
} from '@/components/ui'
import { RolesMatrix } from '@/components/users/RolesMatrix'
import { fetchRoles } from '@/features/roles'
import { fetchPartners } from '@/features/partners'
import type { User } from '@/types/api'

export function UsersPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const currentUser = useAuth((s) => s.user)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<User | null>(null)
  const [form, setForm] = useState({ name: '', email: '', password: '', role: 'sales', phone: '' })
  const [cities, setCities] = useState('')
  const [weight, setWeight] = useState('1')
  const [speaks, setSpeaks] = useState<string[]>([])
  const [handles, setHandles] = useState<('buy' | 'rent')[]>([])
  const [projectIds, setProjectIds] = useState<string[]>([])
  const [partnerId, setPartnerId] = useState('')
  const [matrixOpen, setMatrixOpen] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})


  const canManage = can.manageUsers(currentUser)

  /*
   * A manager may say how their team's leads are shared out without being able
   * to edit the accounts themselves — the same split the API makes, so the
   * screen offers a manager exactly the fields their request would be allowed
   * to carry rather than a form that fails on save.
   */
  const canRoute = (row: User) => canManage || can.routeWork(currentUser, row)

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

  const { data: directory, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['users'],
    queryFn: () => apiGet<{ users: User[]; languages: string[] }>('/users'),
  })

  const users = directory?.users ?? []

  // Every role the workspace offers, from the server — a role added there appears here without a release.
  const roles = useQuery({ queryKey: ['roles'], queryFn: fetchRoles, enabled: canManage, staleTime: 5 * 60_000 })
  const projects = useQuery({ queryKey: ['users-projects'], queryFn: () => apiGet<{ projects: { id: string; name: string }[] }>('/projects'), enabled: canManage })
  const roleValue = (role: { role: string; id?: string }) => (role.id ? `custom:${role.id}` : role.role)
  const chosen = roles.data?.roles.find((role) => roleValue(role) === form.role)
  // A role the workspace built behaves like a built-in one: that decides what the form asks next.
  const baseRole = chosen?.base_role ?? form.role
  const scoped = chosen?.scoped ?? false
  const partners = useQuery({ queryKey: ['partners-simple'], queryFn: () => fetchPartners(30), enabled: canManage && baseRole === 'broker_viewer', select: (d) => d.partners })

  /*
   * Served rather than listed here: a rep can only be matched on a language the
   * system can read off a phone number, so the screen offers exactly that list
   * and grows with it instead of drifting behind it.
   */
  const languages = directory?.languages ?? []

  /** A manager editing somebody: the routing fields and nothing else. */
  const routingOnly = editing !== null && !canManage

  const routingPayload = () => ({
    covers_cities: cities
      .split(/[,،\n]/)
      .map((city) => city.trim())
      .filter(Boolean),
    assignment_weight: Number(weight) || 1,
    speaks,
    handles,
  })

  const mutation = useMutation({
    mutationFn: () => {
      if (editing && routingOnly) {
        return apiPatch(`/users/${editing.id}`, routingPayload())
      }

      const payload: Record<string, unknown> = {
        name: form.name,
        email: form.email,
        // A role the workspace built travels as its id; the server brings the built-in role it behaves like.
        ...(form.role.startsWith('custom:') ? { access_role_id: form.role.slice(7) } : { role: form.role }),
        phone: form.phone || null,
        // The projects a scoped role is limited to; sent for everybody so that moving someone out of a scoped role clears them.
        project_ids: scoped ? projectIds : [],
        partner_id: baseRole === 'broker_viewer' ? partnerId || null : null,
      }

      if (form.password) payload.password = form.password

      /*
       * Only when editing: these are the workspace's policy about an existing
       * rep, and the create endpoint does not take them.
       */
      if (editing) {
        Object.assign(payload, routingPayload())
      }

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
    setCities('')
    setWeight('1')
    setSpeaks([])
    setProjectIds([])
    setPartnerId('')
    setFieldErrors({})
    setOpen(true)
  }

  function openEdit(user: User) {
    setEditing(user)
    setForm({ name: user.name, email: user.email, password: '', role: user.access_role ? `custom:${user.access_role}` : user.role, phone: user.phone ?? '' })
    setCities((user.covers_cities ?? []).join('، '))
    setWeight(String(user.assignment_weight ?? 1))
    setSpeaks(user.speaks ?? [])
    setHandles(user.handles ?? [])
    setProjectIds(user.project_ids ?? [])
    setPartnerId(user.partner?.id ?? '')
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
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="outline" icon={<ShieldCheck className="size-4" />} onClick={() => setMatrixOpen(true)} data-testid="open-roles-matrix">{t('roles.matrix')}</Button>
            {can.has(currentUser, 'users.edit') && <ButtonLink variant="outline" href="/access" data-testid="open-role-builder" onClick={(event) => { event.preventDefault(); navigate('/access') }}>{t('access.manage')}</ButtonLink>}
            {canManage && <Button icon={<Plus className="size-4" />} onClick={openCreate}>{t('users.addUser')}</Button>}
          </div>
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
                  {(canManage || currentUser?.role === 'manager') && (
                    <Th className="text-end">{t('common.actions')}</Th>
                  )}
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
                      <span className="t-caption block truncate text-[0.875rem]">
                        <bdi dir="ltr">{user.email}</bdi>
                      </span>
                    </Td>
                    <Td>{roles.data?.roles.find((role) => role.id && role.id === user.access_role)?.label ?? t(`roles.${user.role}`)}</Td>
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
                    {(canManage || currentUser?.role === 'manager') && (
                      <Td>
                        <div className="flex items-center justify-end gap-1">
                          {canRoute(user) && (
                            <Button variant="ghost" size="icon" aria-label={t('common.edit')} onClick={() => openEdit(user)}>
                              <Pencil className="size-4" />
                            </Button>
                          )}
                          {canManage && user.id !== currentUser?.id && (
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

      <RolesMatrix open={matrixOpen} onClose={() => setMatrixOpen(false)} />

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
          {/*
            The account itself is an admin's to change. A manager opening this
            is here to say how their team's leads are shared out, so they get
            that part and are not shown fields their own request would be
            refused for carrying.
          */}
          {routingOnly ? (
            <p className="t-small">
              <span className="font-medium">{editing?.name}</span>
              <span className="t-caption ms-2"><bdi dir="ltr">{editing?.email}</bdi></span>
            </p>
          ) : (
          <>
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
              {(roles.data?.roles ?? [{ role: 'sales' }, { role: 'manager' }, { role: 'admin' }] as { role: string; id?: string; custom?: boolean; label?: string }[]).map((role) => (
                <option key={roleValue(role)} value={roleValue(role)}>{role.custom ? role.label : t(`roles.${role.role}`)}</option>
              ))}
            </Select>
            <Input
              dir="ltr"
              label={t('leads.phone')}
              hint={t('common.optional')}
              value={form.phone}
              onChange={(e) => setForm((prev) => ({ ...prev, phone: e.target.value }))}
            />
          </div>

          {/* A broker login belongs to one partner: it sees their leads and their links, and nobody else's. */}
          {baseRole === 'broker_viewer' && (
            <Select label={t('roles.partner')} hint={t('roles.partnerHint')} value={partnerId} data-testid="user-partner" onChange={(e) => setPartnerId(e.target.value)}>
              <option value="">—</option>
              {(partners.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </Select>
          )}

          {/* A project manager, a client or a broker sees only these — and nothing at all until they are given one. */}
          {scoped && (
            <fieldset className="space-y-2" data-testid="user-projects">
              <legend className="t-small mb-1 flex items-center gap-1.5 font-medium"><FolderLock className="size-4" aria-hidden />{t('roles.projectsGiven')}</legend>
              <p className="t-caption">{t('roles.projectsHint')}</p>
              <div className="flex flex-wrap gap-2">
                {(projects.data?.projects ?? []).map((project) => {
                  const chosen = projectIds.includes(project.id)

                  return (
                    <button
                      key={project.id}
                      type="button"
                      aria-pressed={chosen}
                      data-testid={`user-project-${project.id}`}
                      onClick={() => setProjectIds((previous) => (chosen ? previous.filter((id) => id !== project.id) : [...previous, project.id]))}
                      className={cn('pressable rounded-full border px-3 py-1 text-[0.9375rem]', chosen ? 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-ink)]' : 'border-[var(--color-border)] text-[var(--color-ink-muted)]')}
                    >
                      {project.name}
                    </button>
                  )
                })}
              </div>
              {projectIds.length === 0 && <p className="t-caption text-[var(--color-warning)]" data-testid="user-projects-none">{t('roles.noProjectsWarning')}</p>}
            </fieldset>
          )}
          </>
          )}

          {/*
            How this rep's share of the leads is decided.

            Only when editing somebody who exists: these are the workspace's
            policy about a rep rather than part of creating an account, and a
            form that asks for them up front implies they are required when the
            honest answer for almost everyone is "leave both alone".
          */}
          {editing && (
            <div className="grid gap-4 sm:grid-cols-2">
              <Input
                label={t('users.coversCities')}
                hint={t('users.coversCitiesHint')}
                value={cities}
                data-testid="covers-cities"
                error={fieldErrors.covers_cities}
                onChange={(e) => setCities(e.target.value)}
              />
              <Input
                type="number"
                min="1"
                max="10"
                dir="ltr"
                label={t('users.weight')}
                hint={t('users.weightHint')}
                value={weight}
                data-testid="assignment-weight"
                error={fieldErrors.assignment_weight}
                onChange={(e) => setWeight(e.target.value)}
              />
            </div>
          )}

          {/*
            Which languages this rep can sell in.

            The other half of reading a lead's language off their number: that
            says who is calling, this says who can take the call. Empty means
            every language, which is what every rep starts as, and nothing about
            assignment changes until somebody here says otherwise.
          */}
          {/* Which kinds of deal this rep takes — one of the reasons a lead lands on them. Empty means every kind. */}
          {editing && (
            <fieldset className="space-y-2">
              <legend className="t-small mb-1 font-medium">{t('users.handles')}</legend>
              <p className="t-caption">{t('users.handlesHint')}</p>
              <div className="flex flex-wrap gap-2" data-testid="handles">
                {(['buy', 'rent'] as const).map((kind) => {
                  const chosen = handles.includes(kind)

                  return (
                    <button
                      key={kind}
                      type="button"
                      aria-pressed={chosen}
                      data-testid={`handles-${kind}`}
                      onClick={() => setHandles((previous) => (chosen ? previous.filter((it) => it !== kind) : [...previous, kind]))}
                      className={cn(
                        'pressable rounded-full border px-3 py-1 text-[0.9375rem]',
                        chosen
                          ? 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-ink)]'
                          : 'border-[var(--color-border)] text-[var(--color-ink-muted)]',
                      )}
                    >
                      {t(`users.handle.${kind}`)}
                    </button>
                  )
                })}
              </div>
            </fieldset>
          )}

          {editing && languages.length > 0 && (
            <fieldset className="space-y-2">
              <legend className="t-small mb-1 font-medium">{t('users.speaks')}</legend>
              <p className="t-caption">{t('users.speaksHint')}</p>

              <div className="flex flex-wrap gap-2" data-testid="speaks">
                {languages.map((code) => {
                  const chosen = speaks.includes(code)

                  return (
                    <button
                      key={code}
                      type="button"
                      aria-pressed={chosen}
                      data-testid={`speaks-${code}`}
                      onClick={() =>
                        setSpeaks((previous) =>
                          chosen ? previous.filter((it) => it !== code) : [...previous, code],
                        )
                      }
                      className={cn(
                        'pressable rounded-full border px-3 py-1 text-[0.9375rem]',
                        chosen
                          ? 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-ink)]'
                          : 'border-[var(--color-border)] text-[var(--color-ink-muted)]',
                      )}
                    >
                      {t(`languages.${code}`, { defaultValue: code })}
                    </button>
                  )
                })}
              </div>

              {fieldErrors.speaks && (
                <p className="t-caption text-[var(--color-danger)]">{fieldErrors.speaks}</p>
              )}
            </fieldset>
          )}
        </form>
      </Modal>
    </>
  )
}
