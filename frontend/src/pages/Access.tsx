import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardBody, ErrorState, Input, PageHeader, Select, Skeleton, Tabs } from '@/components/ui'
import { useApiError } from '@/hooks/useApiError'
import {
  createRole, deleteRole, fetchAccess, fetchEffective, putRules, updateRole,
  type AccessRoleRow, type AccessRule, type AccessState, type ElementState,
} from '@/features/access'
import { fetchAssignableUsers, fetchProjects } from '@/features/leads'
import { cn } from '@/lib/cn'
import { useUi } from '@/stores/ui'
import type { UserRole } from '@/types/api'

type Tab = 'roles' | 'interface' | 'overrides'

const BASES: UserRole[] = ['sales_manager', 'marketing_manager', 'project_manager', 'finance', 'client_viewer', 'broker_viewer']

/**
 * The Role Builder and the Permission Matrix: who may do what, and who sees which piece of the
 * interface. Roles are built here, any role can be changed per permission and per element, and
 * three layers of rules (workspace → project → person) sit on top. The server enforces all of it;
 * this screen only edits it.
 */
export function AccessPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<Tab>('roles')
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['access'], queryFn: fetchAccess })

  return (
    <>
      <PageHeader title={t('access.title')} subtitle={t('access.subtitle')} />
      <Tabs
        className="mb-3"
        testId="access-tabs"
        value={tab}
        onChange={setTab}
        items={[
          { key: 'roles', label: t('access.tabRoles') },
          { key: 'interface', label: t('access.tabInterface') },
          { key: 'overrides', label: t('access.tabOverrides') },
        ]}
      />
      {isLoading && <Skeleton className="h-72" />}
      {isError && <ErrorState onRetry={() => void refetch()} />}
      {data && tab === 'roles' && <RolesTab state={data} />}
      {data && tab === 'interface' && <InterfaceTab state={data} />}
      {data && tab === 'overrides' && <OverridesTab state={data} />}
    </>
  )
}

function useSave(done?: () => void) {
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const { t } = useTranslation()
  const translateError = useApiError()

  return {
    onSuccess: () => {
      // The session's own access may have changed too.
      void queryClient.invalidateQueries({ queryKey: ['access'] })
      void queryClient.invalidateQueries({ queryKey: ['roles'] })
      toast(t('settings.saved'), 'success')
      done?.()
    },
    onError: (caught: unknown) => toast(translateError(caught), 'error'),
  }
}

function roleName(role: AccessRoleRow, t: (key: string) => string) {
  return role.system ? t(`roles.${role.key}`) : role.name
}

/* ───────────────────────────── Roles ───────────────────────────── */

function RolesTab({ state }: { state: AccessState }) {
  const { t } = useTranslation()
  const [selected, setSelected] = useState<string>(state.roles.find((r) => !r.locked)?.key ?? state.roles[0].key)
  const [creating, setCreating] = useState(false)
  const role = state.roles.find((r) => r.key === selected) ?? state.roles[0]

  return (
    <div className="grid grid-cols-[minmax(0,1fr)] gap-3 lg:grid-cols-[16rem_minmax(0,1fr)]">
      <Card>
        <CardBody className="space-y-1" data-testid="access-role-list">
          {state.roles.map((r) => (
            <button
              key={r.key}
              type="button"
              data-testid={`access-role-${r.key}`}
              onClick={() => { setSelected(r.key); setCreating(false) }}
              className={cn(
                'flex w-full items-center justify-between gap-2 rounded-[var(--radius-control)] px-2.5 py-1.5 text-start text-sm',
                !creating && r.key === selected ? 'bg-[var(--brand-soft)] font-semibold' : 'hover:bg-[var(--color-chrome-fill)]',
              )}
            >
              <span className="truncate">{roleName(r, t)}</span>
              <span className="flex items-center gap-1">
                {!r.system && <Badge tone="info">{t('access.built')}</Badge>}
                {r.locked && <Lock className="size-3.5 text-[var(--color-ink-subtle)]" aria-label={t('access.locked')} />}
                <span className="t-caption">{r.users_count}</span>
              </span>
            </button>
          ))}
          <Button variant="outline" size="sm" className="mt-2 w-full" icon={<Plus className="size-4" />} onClick={() => setCreating(true)} data-testid="access-new-role">
            {t('access.newRole')}
          </Button>
        </CardBody>
      </Card>

      {creating
        ? <RoleEditor key="new" state={state} role={null} onDone={(key) => { setCreating(false); if (key) setSelected(key) }} />
        : <RoleEditor key={role.key} state={state} role={role} onDone={(key) => key && setSelected(key)} />}
    </div>
  )
}

function RoleEditor({ state, role, onDone }: { state: AccessState; role: AccessRoleRow | null; onDone: (key?: string) => void }) {
  const { t } = useTranslation()
  const [name, setName] = useState(role?.name ?? '')
  const [description, setDescription] = useState(role?.description ?? '')
  const [base, setBase] = useState<UserRole>(role?.base_role ?? 'sales_manager')
  const [scoped, setScoped] = useState(role?.scoped ?? false)
  const [external, setExternal] = useState(role?.external ?? false)
  const [grants, setGrants] = useState<Record<string, string[]>>(role?.grants ?? {})

  const locked = role?.locked ?? false
  const custom = role === null || !role.system

  const toggle = (resource: string, action: string) => {
    if (locked) return
    setGrants((prev) => {
      const held = prev[resource] ?? []
      const next = held.includes(action) ? held.filter((a) => a !== action) : [...held, action]

      return { ...prev, [resource]: next }
    })
  }

  const save = useMutation({
    mutationFn: async () => {
      if (role === null) {
        return (await createRole({ name, description: description || null, base_role: base, project_scoped: scoped, external, grants })).role.key
      }
      await updateRole(role.key, custom ? { name, description: description || null, project_scoped: scoped, external, grants } : { grants })

      return role.key
    },
    ...useSave(),
  })
  const remove = useMutation({ mutationFn: () => deleteRole(role?.id as string), ...useSave(() => onDone()) })

  return (
    <Card>
      <CardBody className="space-y-3" data-testid="access-role-editor">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="t-h3">{role === null ? t('access.newRole') : custom ? role.name : t(`roles.${role.key}`)}</h2>
          {role && !role.enforced && <Badge tone="warning">{t('access.legacyNote')}</Badge>}
          {locked && <Badge tone="neutral">{t('access.adminLocked')}</Badge>}
        </div>

        {custom && (
          <div className="grid gap-3 sm:grid-cols-2">
            <Input label={t('access.roleName')} value={name} onChange={(e) => setName(e.target.value)} data-testid="access-role-name" />
            <Input label={t('access.roleDescription')} value={description} onChange={(e) => setDescription(e.target.value)} />
            {role === null && (
              <Select label={t('access.behavesLike')} hint={t('access.behavesLikeHint')} value={base} onChange={(e) => setBase(e.target.value as UserRole)} data-testid="access-role-base">
                {BASES.map((b) => <option key={b} value={b}>{t(`roles.${b}`)}</option>)}
              </Select>
            )}
            <div className="flex flex-wrap items-end gap-4 pb-1">
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={scoped} onChange={(e) => setScoped(e.target.checked)} />{t('roles.scoped')}</label>
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={external} onChange={(e) => setExternal(e.target.checked)} />{t('roles.external')}</label>
            </div>
          </div>
        )}

        <div className="scroll-x" data-testid="access-grid">
          <table className="w-full border-collapse text-[0.875rem]">
            <thead>
              <tr>
                <th className="p-1.5 text-start">{t('access.area')}</th>
                {state.actions.map((a) => <th key={a} className="p-1.5 text-center font-medium text-[var(--color-ink-muted)]">{t(`roles.action.${a}`)}</th>)}
              </tr>
            </thead>
            <tbody>
              {state.resources.map((resource) => (
                <tr key={resource} className="border-t border-[var(--color-line)]">
                  <th scope="row" className="p-1.5 text-start font-medium">{t(`roles.resource.${resource}`)}</th>
                  {state.actions.map((action) => {
                    const off = locked || (state.view_only.includes(resource) && action !== 'view')
                    const base = role?.base_grants[resource]?.includes(action) ?? false
                    const on = locked ? true : (grants[resource] ?? []).includes(action)

                    return (
                      <td key={action} className="p-1 text-center">
                        <input
                          type="checkbox"
                          checked={on}
                          disabled={off}
                          onChange={() => toggle(resource, action)}
                          aria-label={`${resource}.${action}`}
                          data-testid={`grant-${resource}-${action}`}
                          className={cn(on !== base && role?.system && 'outline outline-2 outline-[var(--brand)]')}
                        />
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="t-caption">{t('access.viewOnlyNote')}</p>

        {!locked && (
          <div className="flex flex-wrap gap-2">
            <Button
              loading={save.isPending}
              disabled={custom && name.trim().length < 2}
              data-testid="access-save-role"
              onClick={() => save.mutate(undefined, { onSuccess: (key) => onDone(key) })}
            >
              {t('common.save')}
            </Button>
            {role?.system && (
              <Button variant="outline" onClick={() => setGrants(role.base_grants)}>{t('access.resetDefaults')}</Button>
            )}
            {role && !role.system && (
              <Button variant="danger" icon={<Trash2 className="size-4" />} disabled={role.users_count > 0} loading={remove.isPending} onClick={() => remove.mutate()} data-testid="access-delete-role">
                {t('common.delete')}
              </Button>
            )}
          </div>
        )}
        {role && !role.system && role.users_count > 0 && <p className="t-caption">{t('access.inUse', { count: role.users_count })}</p>}
      </CardBody>
    </Card>
  )
}

/* ─────────────────────────── Interface ─────────────────────────── */

function InterfaceTab({ state }: { state: AccessState }) {
  const { t } = useTranslation()
  const editable = state.roles.filter((r) => !r.locked)
  const [roleKey, setRoleKey] = useState(editable[0]?.key ?? '')
  const role = state.roles.find((r) => r.key === roleKey)
  const [choices, setChoices] = useState<Record<string, ElementState>>({})

  useEffect(() => {
    setChoices(Object.fromEntries((role?.elements ?? []).map((e) => [e.key, e.state])))
  }, [role])

  const held = useMemo(() => new Set(Object.entries(role?.grants ?? {}).flatMap(([resource, actions]) => actions.map((a) => `${resource}.${a}`))), [role])
  const grouped = useMemo(() => state.kinds.map((kind) => ({ kind, items: state.elements.filter((e) => e.kind === kind) })).filter((g) => g.items.length > 0), [state])

  const save = useMutation({
    mutationFn: () => updateRole(roleKey, { elements: Object.entries(choices).map(([key, s]) => ({ key, state: s })) }),
    ...useSave(),
  })

  if (!role) return null

  return (
    <Card>
      <CardBody className="space-y-4">
        <div className="flex flex-wrap items-end gap-3">
          <Select label={t('access.forRole')} value={roleKey} onChange={(e) => setRoleKey(e.target.value)} data-testid="interface-role">
            {editable.map((r) => <option key={r.key} value={r.key}>{roleName(r, t)}</option>)}
          </Select>
          <Button loading={save.isPending} onClick={() => save.mutate()} data-testid="interface-save">{t('common.save')}</Button>
        </div>
        <p className="t-caption">{t('access.interfaceHint')}</p>

        {grouped.map(({ kind, items }) => (
          <section key={kind} aria-labelledby={`kind-${kind}`}>
            <h3 id={`kind-${kind}`} className="t-small mb-1.5 font-semibold">{t(`access.kind.${kind}`)}</h3>
            <ul className="divide-y divide-[var(--color-line)] rounded-[var(--radius-control)] border border-[var(--color-line)]">
              {items.map((el) => {
                const available = el.needs.every((p) => held.has(p))
                const choice = choices[el.key]

                return (
                  <li key={el.key} className="flex flex-wrap items-center justify-between gap-2 px-3 py-1.5" data-testid={`element-${el.key}`}>
                    <span className={cn('text-sm', !available && 'text-[var(--color-ink-subtle)]')}>{t(`access.element.${el.key}`)}</span>
                    {!available ? (
                      <Badge tone="neutral">{t('access.needsMissing')}</Badge>
                    ) : (
                      <span className="inline-flex overflow-hidden rounded-[var(--radius-control)] border border-[var(--color-line)]" role="group">
                        {([['default', undefined], ['show', 'show'], ['hide', 'hide']] as const).map(([label, value]) => (
                          <button
                            key={label}
                            type="button"
                            aria-pressed={choice === value}
                            data-testid={`element-${el.key}-${label}`}
                            onClick={() => setChoices((prev) => {
                              const next = { ...prev }
                              if (value) next[el.key] = value; else delete next[el.key]

                              return next
                            })}
                            className={cn('px-2.5 py-1 text-[0.8125rem]', choice === value ? 'bg-[var(--brand)] text-white' : 'hover:bg-[var(--color-chrome-fill)]')}
                          >
                            {t(`access.state.${label}`)}
                          </button>
                        ))}
                      </span>
                    )}
                  </li>
                )
              })}
            </ul>
          </section>
        ))}
      </CardBody>
    </Card>
  )
}

/* ─────────────────────────── Overrides ─────────────────────────── */

type Layer = 'workspace' | 'project' | 'user'

function OverridesTab({ state }: { state: AccessState }) {
  const { t } = useTranslation()
  const [layer, setLayer] = useState<Layer>('workspace')
  const [projectId, setProjectId] = useState('')
  const [userId, setUserId] = useState('')
  const [roleKey, setRoleKey] = useState('')
  const [rules, setRules] = useState<AccessRule[]>([])
  const projects = useQuery({ queryKey: ['projects'], queryFn: fetchProjects })
  const users = useQuery({ queryKey: ['assignable-users'], queryFn: fetchAssignableUsers })

  const stored = useMemo<AccessRule[]>(() => {
    if (layer === 'workspace') return state.workspace
    if (layer === 'project') return state.projects.find((p) => p.id === projectId)?.rules.filter((r) => (r.role_key ?? '') === roleKey) ?? []

    return state.users.find((u) => u.id === userId)?.rules ?? []
  }, [layer, projectId, userId, roleKey, state])

  useEffect(() => setRules(stored.map(({ permission, effect }) => ({ permission, effect }))), [stored])

  const options = useMemo(
    () => [
      ...state.resources.flatMap((r) => state.actions.map((a) => ({ value: `${r}.${a}`, label: `${t(`roles.resource.${r}`)} · ${t(`roles.action.${a}`)}` }))),
      ...state.elements.map((e) => ({ value: `ui:${e.key}`, label: `${t(`access.kind.${e.kind}`)} · ${t(`access.element.${e.key}`)}` })),
    ],
    [state, t],
  )

  const target = layer === 'project' ? projectId : layer === 'user' ? userId : 'workspace'
  const save = useMutation({
    mutationFn: () => putRules({
      layer, rules,
      ...(layer === 'project' ? { project_id: projectId, role_key: roleKey || null } : {}),
      ...(layer === 'user' ? { user_id: userId } : {}),
    }),
    ...useSave(),
  })

  const preview = useQuery({
    queryKey: ['access-effective', userId, state],
    queryFn: () => fetchEffective(userId),
    enabled: layer === 'user' && userId !== '',
  })

  return (
    <Card>
      <CardBody className="space-y-3" data-testid="access-overrides">
        <p className="t-caption">{t('access.layersHint')}</p>
        <div className="flex flex-wrap items-end gap-3">
          <Select label={t('access.layer')} value={layer} onChange={(e) => setLayer(e.target.value as Layer)} data-testid="override-layer">
            <option value="workspace">{t('access.layerWorkspace')}</option>
            <option value="project">{t('access.layerProject')}</option>
            <option value="user">{t('access.layerUser')}</option>
          </Select>
          {layer === 'project' && (
            <>
              <Select label={t('access.project')} value={projectId} onChange={(e) => setProjectId(e.target.value)} data-testid="override-project">
                <option value="">—</option>
                {(projects.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </Select>
              <Select label={t('access.onlyForRole')} value={roleKey} onChange={(e) => setRoleKey(e.target.value)}>
                <option value="">{t('access.allRoles')}</option>
                {state.roles.filter((r) => !r.locked).map((r) => <option key={r.key} value={r.key}>{roleName(r, t)}</option>)}
              </Select>
            </>
          )}
          {layer === 'user' && (
            <Select label={t('access.person')} value={userId} onChange={(e) => setUserId(e.target.value)} data-testid="override-user">
              <option value="">—</option>
              {(users.data ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </Select>
          )}
        </div>

        {target !== '' && (
          <>
            <ul className="space-y-1.5" data-testid="override-rules">
              {rules.map((rule, index) => (
                <li key={index} className="flex flex-wrap items-center gap-2">
                  <Select aria-label={t('access.permission')} value={rule.permission} onChange={(e) => setRules((prev) => prev.map((r, i) => (i === index ? { ...r, permission: e.target.value } : r)))} className="min-w-0 flex-1">
                    {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                  </Select>
                  <Select aria-label={t('access.effect')} value={rule.effect} onChange={(e) => setRules((prev) => prev.map((r, i) => (i === index ? { ...r, effect: e.target.value as 'allow' | 'deny' } : r)))} className="w-32">
                    <option value="allow">{t('access.allow')}</option>
                    <option value="deny">{t('access.deny')}</option>
                  </Select>
                  <Button variant="ghost" size="icon" aria-label={t('common.delete')} icon={<Trash2 className="size-4" />} onClick={() => setRules((prev) => prev.filter((_, i) => i !== index))} />
                </li>
              ))}
            </ul>
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" icon={<Plus className="size-4" />} onClick={() => setRules((prev) => [...prev, { permission: options[0].value, effect: 'deny' }])} data-testid="override-add">{t('access.addRule')}</Button>
              <Button loading={save.isPending} onClick={() => save.mutate()} data-testid="override-save">{t('common.save')}</Button>
            </div>
          </>
        )}

        {layer === 'user' && preview.data && (
          <p className="t-caption" data-testid="override-preview">
            {t('access.previewLine', { permissions: preview.data.permissions.length, hidden: preview.data.hidden_elements.length })}
          </p>
        )}
      </CardBody>
    </Card>
  )
}
