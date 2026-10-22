import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowUpLeft, Building, Flame, Plus, Trophy, Users2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import {
  COMPANIES_KEY, createCompany, deleteCompany, fetchCompanies, setCompanyMembers, updateCompany,
  type Company, type CompanyMetrics,
} from '@/features/companies'
import { fetchAssignableUsers } from '@/features/leads'
import { ApiError } from '@/lib/api'
import { useAuth, can } from '@/stores/auth'
import { useCompany } from '@/stores/company'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatNumber } from '@/lib/format'
import {
  Button, Card, Drawer, EmptyState, ErrorState, Input, PageHeader, Skeleton, Textarea,
} from '@/components/ui'
import { cn } from '@/lib/cn'

/** The colours a company in a holding can be marked with: the identity's own, so a holding reads as one family. Emerald first. */
const SWATCHES = ['#1D5E52', '#2E7D6E', '#3A6F95', '#B38A55', '#84612F', '#9E3B30', '#133B34', '#5B6662']

/**
 * The holding, as the businesses inside it.
 *
 * Each card answers "how is this company doing" without opening it — open
 * leads, hot, won, conversion — and is itself the way in: choosing it scopes
 * every screen in the product to that company, which is what a holding's
 * manager actually does with this page.
 */
export function CompaniesPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const select = useCompany((s) => s.select)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const admin = can.manageTenant(user)

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: [...COMPANIES_KEY, 'all'],
    queryFn: () => fetchCompanies(true),
  })
  const { data: people = [] } = useQuery({ queryKey: ['assignable-users'], queryFn: fetchAssignableUsers, enabled: admin })

  const [editing, setEditing] = useState<Company | null>(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ name: '', description: '', color: SWATCHES[0], is_active: true })
  const [members, setMembers] = useState<string[]>([])
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: COMPANIES_KEY })
    void queryClient.invalidateQueries({ queryKey: ['projects'] })
  }

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        name: form.name,
        description: form.description || null,
        color: form.color,
        is_active: form.is_active,
      }
      const { company } = editing ? await updateCompany(editing.id, payload) : await createCompany(payload)
      await setCompanyMembers(company.id, members)
    },
    onSuccess: () => {
      refresh()
      toast(t('common.saved'), 'success')
      close()
    },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.status === 422 && caught.errors) {
        setFieldErrors(Object.fromEntries(Object.entries(caught.errors).map(([k, v]) => [k, v[0] ?? ''])))
      } else {
        toast(translateError(caught), 'error')
      }
    },
  })

  const remove = useMutation({
    mutationFn: () => deleteCompany(editing!.id),
    onSuccess: () => {
      refresh()
      toast(t('company.deleted'), 'success')
      close()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  function openCreate() {
    setEditing(null)
    setForm({ name: '', description: '', color: SWATCHES[0], is_active: true })
    setMembers([])
    setFieldErrors({})
    setOpen(true)
  }

  function openEdit(company: Company) {
    setEditing(company)
    setForm({
      name: company.name,
      description: company.description ?? '',
      color: company.color ?? SWATCHES[0],
      is_active: company.is_active,
    })
    setMembers((company.members ?? []).map((m) => m.id))
    setFieldErrors({})
    setOpen(true)
  }

  function close() {
    setOpen(false)
    setEditing(null)
  }

  function enter(company: Company) {
    select(company.id)
    void queryClient.invalidateQueries({ predicate: (query) => query.queryKey[0] !== 'companies' })
    navigate('/')
  }

  const companies = data?.companies ?? []
  const metrics = new Map((data?.metrics ?? []).map((row) => [row.id, row]))

  return (
    <>
      <PageHeader
        title={t('company.title')}
        subtitle={t('company.subtitle')}
        actions={
          admin ? (
            <Button icon={<Plus className="size-4" />} onClick={openCreate} data-testid="add-company">
              {t('company.add')}
            </Button>
          ) : undefined
        }
      />

      {isError ? (
        <Card><ErrorState message={translateError(error)} onRetry={() => void refetch()} /></Card>
      ) : isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {[0, 1, 2].map((i) => <Skeleton key={i} className="h-44" />)}
        </div>
      ) : companies.length === 0 ? (
        <Card>
          <EmptyState
            icon={<Building className="size-6" />}
            title={t('company.emptyTitle')}
            body={admin ? t('company.emptyAdmin') : t('company.emptyHint')}
            actions={admin ? <Button onClick={openCreate}>{t('company.add')}</Button> : undefined}
          />
        </Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="company-grid">
          {companies.map((company) => (
            <CompanyCard
              key={company.id}
              company={company}
              metrics={metrics.get(company.id)}
              admin={admin}
              onEnter={() => enter(company)}
              onEdit={() => openEdit(company)}
            />
          ))}
        </div>
      )}

      <Drawer
        open={open}
        onClose={close}
        testId="company-drawer"
        title={editing ? t('common.edit') : t('company.add')}
        footer={
          <>
            {editing && (
              <Button
                variant="danger"
                className="me-auto"
                loading={remove.isPending}
                onClick={() => remove.mutate()}
                data-testid="delete-company"
              >
                {t('common.delete')}
              </Button>
            )}
            <Button variant="ghost" onClick={close}>{t('common.cancel')}</Button>
            <Button type="submit" form="company-form" loading={save.isPending} data-testid="save-company">
              {t('common.save')}
            </Button>
          </>
        }
      >
        <form
          id="company-form"
          className="space-y-4"
          noValidate
          onSubmit={(event: FormEvent) => {
            event.preventDefault()
            setFieldErrors({})
            save.mutate()
          }}
        >
          <Input
            required
            label={t('company.name')}
            value={form.name}
            error={fieldErrors.name}
            data-testid="company-name"
            onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
          />
          <Textarea
            rows={2}
            label={t('company.description')}
            hint={t('common.optional')}
            value={form.description}
            onChange={(e) => setForm((p) => ({ ...p, description: e.target.value }))}
          />

          <fieldset className="space-y-2">
            <legend className="t-small mb-1 font-medium">{t('company.color')}</legend>
            <div className="flex flex-wrap gap-2">
              {SWATCHES.map((swatch) => (
                <button
                  key={swatch}
                  type="button"
                  aria-label={swatch}
                  aria-pressed={form.color === swatch}
                  onClick={() => setForm((p) => ({ ...p, color: swatch }))}
                  className={cn(
                    'pressable size-7 rounded-full ring-offset-2 ring-offset-[var(--color-surface)]',
                    form.color === swatch && 'ring-2 ring-[var(--color-ink)]',
                  )}
                  style={{ background: swatch }}
                />
              ))}
            </div>
          </fieldset>

          <label className="flex items-center gap-2 text-[0.9375rem]">
            <input
              type="checkbox"
              checked={form.is_active}
              onChange={(e) => setForm((p) => ({ ...p, is_active: e.target.checked }))}
            />
            {t('company.active')}
          </label>

          {/* Who works here. Empty means nobody is held to it, which is how
              every workspace starts: people see what their role always let
              them see. */}
          <fieldset className="space-y-2">
            <legend className="t-small mb-1 font-medium">{t('company.members')}</legend>
            <p className="t-caption">{t('company.membersHint')}</p>
            <div className="flex flex-wrap gap-2" data-testid="company-members">
              {people.map((person) => {
                const chosen = members.includes(person.id)
                return (
                  <button
                    key={person.id}
                    type="button"
                    aria-pressed={chosen}
                    onClick={() =>
                      setMembers((previous) =>
                        chosen ? previous.filter((id) => id !== person.id) : [...previous, person.id],
                      )
                    }
                    className={cn(
                      'pressable rounded-full border px-3 py-1 text-[0.9375rem]',
                      chosen
                        ? 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-ink)]'
                        : 'border-[var(--color-border)] text-[var(--color-ink-muted)]',
                    )}
                  >
                    {person.name}
                  </button>
                )
              })}
            </div>
          </fieldset>
        </form>
      </Drawer>
    </>
  )
}

function CompanyCard({
  company, metrics, admin, onEnter, onEdit,
}: {
  company: Company
  metrics?: CompanyMetrics
  admin: boolean
  onEnter: () => void
  onEdit: () => void
}) {
  const { t } = useTranslation()
  const color = company.color ?? 'var(--brand)'

  return (
    <Card className="relative overflow-hidden" data-testid={`company-card-${company.id}`}>
      <div
        aria-hidden
        className="absolute inset-x-0 top-0 h-1.5"
        style={{ background: color }}
      />
      <div
        aria-hidden
        className="pointer-events-none absolute inset-x-0 top-0 h-20 opacity-[0.08]"
        style={{ background: `linear-gradient(to bottom, ${color}, transparent)` }}
      />

      <div className="relative space-y-3 p-4 pt-5">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <h3 className="truncate text-[1rem] font-semibold">{company.name}</h3>
            <p className="t-caption mt-0.5">
              {t('company.projectsCount', { count: company.projects_count ?? 0 })}
              {!company.is_active && <span className="ms-2 text-[var(--color-warning)]">{t('company.inactive')}</span>}
            </p>
          </div>
          {admin && (
            <Button size="sm" variant="ghost" onClick={onEdit} data-testid={`edit-company-${company.id}`}>
              {t('common.edit')}
            </Button>
          )}
        </div>

        <dl className="grid grid-cols-3 gap-2 text-center">
          <Figure icon={<Users2 className="size-3.5" />} value={metrics?.open ?? 0} label={t('company.open')} />
          <Figure icon={<Flame className="size-3.5" />} value={metrics?.hot ?? 0} label={t('company.hot')} tone="warning" />
          <Figure icon={<Trophy className="size-3.5" />} value={metrics?.won ?? 0} label={t('company.won')} tone="success" />
        </dl>

        {/* Conversion as a bar, because it is the one number here whose size
            is the point — and a bar is read before a figure is. */}
        <div>
          <div className="mb-1 flex items-center justify-between text-[0.8125rem] text-[var(--color-ink-muted)]">
            <span>{t('company.conversion')}</span>
            <span className="tabular-nums">{formatNumber(metrics?.conversion_rate ?? 0)}%</span>
          </div>
          <div className="h-1.5 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
            <div
              className="h-full rounded-full"
              style={{ width: `${Math.min(100, metrics?.conversion_rate ?? 0)}%`, background: color }}
            />
          </div>
        </div>

        {company.members && company.members.length > 0 && (
          <p className="t-caption truncate">{company.members.map((m) => m.name).join('، ')}</p>
        )}

        <Button variant="secondary" size="sm" className="w-full" onClick={onEnter} data-testid={`enter-company-${company.id}`}>
          {t('company.enter')}
          <ArrowUpLeft className="size-3.5 rtl:rotate-0 ltr:-scale-x-100" aria-hidden />
        </Button>
      </div>
    </Card>
  )
}

function Figure({
  icon, value, label, tone,
}: {
  icon: React.ReactNode
  value: number
  label: string
  tone?: 'warning' | 'success'
}) {
  return (
    <div className="rounded-[var(--radius-control)] bg-[var(--color-surface-muted)] px-2 py-1.5">
      <dt className="flex items-center justify-center gap-1 text-[0.8125rem] text-[var(--color-ink-muted)]">
        {icon}
        {label}
      </dt>
      <dd
        className={cn(
          'text-[1.125rem] font-semibold leading-tight tabular-nums',
          tone === 'warning' && value > 0 && 'text-[var(--color-warning)]',
          tone === 'success' && value > 0 && 'text-[var(--color-success)]',
        )}
      >
        {formatNumber(value)}
      </dd>
    </div>
  )
}
