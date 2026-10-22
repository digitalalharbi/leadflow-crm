import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bath, BedDouble, Building2, Home, Landmark, Maximize2, Plus, Store, Trash2, TreePine, type LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import {
  createProperty, deleteProperty, fetchProjectProperties, PROPERTY_STATUSES, PROPERTY_TYPES, updateProperty,
  type Property, type PropertyInput, type PropertyStatus, type PropertyType,
} from '@/features/properties'
import { ApiError } from '@/lib/api'
import { formatMoneyCompact } from '@/lib/format'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Button, Card, CardBody, CardHeader, Drawer, EmptyState, Input, Select, Skeleton } from '@/components/ui'
import { Donut } from '@/components/visual'
import { cn } from '@/lib/cn'

const TYPE_ICON: Record<PropertyType, LucideIcon> = {
  apartment: Building2, villa: Home, townhouse: Home, duplex: Building2, land: TreePine, office: Landmark, shop: Store, other: Building2,
}

const STATUS_COLOR: Record<PropertyStatus, string> = {
  available: 'var(--color-success)', reserved: 'var(--color-warning)', sold: 'var(--brand)', rented: 'var(--color-info)', hidden: 'var(--color-ink-subtle)',
}

const BLANK: PropertyInput = { code: '', property_type: 'apartment', transaction_type: 'sale', status: 'available' }

/**
 * A project's units, as cards and a ring: what is left to sell is the first thing a sales manager
 * wants, so the donut says it and each card carries what a rep needs on a call — type, price,
 * rooms, size — and nothing else.
 */
export function ProjectUnits({ projectId }: { projectId: string }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const canEdit = can.manageProjects(user)

  const [filter, setFilter] = useState<PropertyStatus | null>(null)
  const [editing, setEditing] = useState<Property | null>(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<PropertyInput>(BLANK)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['project-properties', projectId, filter],
    queryFn: () => fetchProjectProperties(projectId, filter ?? undefined),
    placeholderData: (previous) => previous,
  })

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['project-properties', projectId] })

  const save = useMutation({
    mutationFn: () => (editing ? updateProperty(editing.id, form) : createProperty(projectId, form)),
    onSuccess: () => {
      refresh()
      toast(t('common.saved'), 'success')
      close()
    },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.errors) setError(Object.values(caught.errors)[0]?.[0] ?? t('errors.validation'))
      else toast(translateError(caught), 'error')
    },
  })

  const remove = useMutation({
    mutationFn: () => deleteProperty(editing!.id),
    onSuccess: () => {
      refresh()
      close()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  function openCreate() {
    setEditing(null)
    setForm(BLANK)
    setError(null)
    setOpen(true)
  }

  function openEdit(property: Property) {
    setEditing(property)
    setForm({
      code: property.code, title: property.title, property_type: property.property_type, transaction_type: property.transaction_type,
      price: property.price, city: property.city, district: property.district, bedrooms: property.bedrooms, bathrooms: property.bathrooms,
      area_sqm: property.area_sqm, floor: property.floor, status: property.status, notes: property.notes,
    })
    setError(null)
    setOpen(true)
  }

  function close() {
    setOpen(false)
    setEditing(null)
  }

  const num = (key: keyof PropertyInput) => ({
    value: (form[key] ?? '') as number | string,
    onChange: (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value === '' ? null : Number(e.target.value) })),
  })
  const text = (key: keyof PropertyInput) => ({
    value: (form[key] ?? '') as string,
    onChange: (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value })),
  })

  const summary = data?.summary

  return (
    <Card data-testid="project-units">
      <CardHeader
        title={t('propertyUnits.title')}
        action={canEdit ? <Button size="sm" icon={<Plus className="size-4" />} onClick={openCreate} data-testid="add-unit">{t('propertyUnits.add')}</Button> : undefined}
      />
      <CardBody className="space-y-4">
        {isLoading && !data ? (
          <Skeleton className="h-32" />
        ) : (
          <>
            <div className="flex flex-wrap items-center gap-4" data-testid="units-summary">
              <Donut
                size={96}
                thickness={12}
                legend="none"
                testId="units-donut"
                segments={PROPERTY_STATUSES.map((status) => ({ key: status, label: t(`propertyUnits.status.${status}`), value: summary?.[status] ?? 0, color: STATUS_COLOR[status] }))}
              />
              <div role="group" aria-label={t('propertyUnits.filter')} className="flex flex-wrap gap-1.5">
                <button type="button" aria-pressed={filter === null} onClick={() => setFilter(null)} data-testid="units-filter-all"
                  className={cn('pressable rounded-full px-3 py-1 text-[0.9375rem] font-semibold', filter === null ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--color-surface-muted)]')}>
                  {t('propertyUnits.all')}
                </button>
                {PROPERTY_STATUSES.map((status) => (
                  <button key={status} type="button" aria-pressed={filter === status} onClick={() => setFilter(status)} data-testid={`units-filter-${status}`}
                    className={cn('pressable inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[0.9375rem] font-semibold', filter === status ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--color-surface-muted)]')}>
                    <span className="size-2 rounded-full" style={{ background: STATUS_COLOR[status] }} aria-hidden />
                    {t(`propertyUnits.status.${status}`)}
                    <span className="tabular-nums opacity-80" data-testid={`units-count-${status}`}>{summary?.[status] ?? 0}</span>
                  </button>
                ))}
              </div>
            </div>

            {(data?.properties.length ?? 0) === 0 ? (
              <EmptyState title={t('propertyUnits.emptyTitle')} body={t('propertyUnits.emptyBody')} />
            ) : (
              <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="unit-list">
                {data!.properties.map((property) => {
                  const Icon = TYPE_ICON[property.property_type]

                  return (
                    <li key={property.id} data-testid={`unit-${property.code}`} data-status={property.status}>
                      <button type="button" onClick={() => (canEdit ? openEdit(property) : undefined)} disabled={!canEdit}
                        className="pressable block w-full rounded-[var(--radius-card)] bg-[var(--color-surface-muted)] p-3 text-start disabled:cursor-default">
                        <div className="flex items-center justify-between gap-2">
                          <span className="flex items-center gap-2 text-[1.0625rem] font-extrabold"><Icon className="size-5 text-[var(--brand)]" aria-hidden />{property.code}</span>
                          <span className="inline-flex items-center gap-1.5 rounded-full bg-[var(--color-surface)] px-2 py-0.5 text-[0.875rem] font-semibold">
                            <span className="size-2 rounded-full" style={{ background: STATUS_COLOR[property.status] }} aria-hidden />{t(`propertyUnits.status.${property.status}`)}
                          </span>
                        </div>
                        <p className="mt-1 text-[1.125rem] font-extrabold tabular-nums" dir="ltr">{property.price === null ? '—' : formatMoneyCompact(property.price, 'SAR', i18n.language)}</p>
                        <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[0.9375rem] text-[var(--color-ink-muted)]">
                          <span>{property.type_label}</span>
                          {property.bedrooms !== null && <span className="inline-flex items-center gap-1"><BedDouble className="size-4" aria-hidden />{property.bedrooms}</span>}
                          {property.bathrooms !== null && <span className="inline-flex items-center gap-1"><Bath className="size-4" aria-hidden />{property.bathrooms}</span>}
                          {property.area_sqm !== null && <span className="inline-flex items-center gap-1" dir="ltr"><Maximize2 className="size-4" aria-hidden />{property.area_sqm} m²</span>}
                        </p>
                        {(property.district || property.city) && <p className="mt-0.5 text-[0.9375rem] text-[var(--color-ink-subtle)]">{[property.district, property.city].filter(Boolean).join(' · ')}</p>}
                      </button>
                    </li>
                  )
                })}
              </ul>
            )}
          </>
        )}
      </CardBody>

      <Drawer
        open={open}
        onClose={close}
        title={editing ? t('propertyUnits.edit') : t('propertyUnits.add')}
        testId="unit-drawer"
        footer={
          <>
            {editing && (
              <Button variant="ghost" icon={<Trash2 className="size-4" />} loading={remove.isPending} onClick={() => remove.mutate()} data-testid="delete-unit">{t('common.delete')}</Button>
            )}
            <Button onClick={() => save.mutate()} loading={save.isPending} disabled={!form.code} data-testid="save-unit">{t('common.save')}</Button>
          </>
        }
      >
        <div className="space-y-4" data-testid="unit-form">
          <div className="grid gap-4 sm:grid-cols-2">
            <Input label={t('propertyUnits.code')} data-testid="unit-code" {...text('code')} />
            <Select label={t('propertyUnits.type')} data-testid="unit-type" value={form.property_type} onChange={(e) => setForm((f) => ({ ...f, property_type: e.target.value as PropertyType }))}>
              {PROPERTY_TYPES.map((type) => <option key={type} value={type}>{t(`propertyUnits.types.${type}`)}</option>)}
            </Select>
            <Select label={t('propertyUnits.transaction')} data-testid="unit-transaction" value={form.transaction_type ?? 'sale'} onChange={(e) => setForm((f) => ({ ...f, transaction_type: e.target.value as 'sale' | 'rent' }))}>
              <option value="sale">{t('propertyUnits.sale')}</option>
              <option value="rent">{t('propertyUnits.rent')}</option>
            </Select>
            <Select label={t('propertyUnits.statusLabel')} data-testid="unit-status" value={form.status ?? 'available'} onChange={(e) => setForm((f) => ({ ...f, status: e.target.value as PropertyStatus }))}>
              {PROPERTY_STATUSES.map((status) => <option key={status} value={status}>{t(`propertyUnits.status.${status}`)}</option>)}
            </Select>
            <Input label={t('propertyUnits.price')} type="number" dir="ltr" data-testid="unit-price" {...num('price')} />
            <Input label={t('propertyUnits.area')} type="number" dir="ltr" data-testid="unit-area" {...num('area_sqm')} />
            <Input label={t('propertyUnits.bedrooms')} type="number" dir="ltr" data-testid="unit-bedrooms" {...num('bedrooms')} />
            <Input label={t('propertyUnits.bathrooms')} type="number" dir="ltr" {...num('bathrooms')} />
            <Input label={t('propertyUnits.city')} {...text('city')} />
            <Input label={t('propertyUnits.district')} data-testid="unit-district" {...text('district')} />
          </div>
          {error && <p role="alert" className="text-[0.9375rem] font-semibold text-[var(--color-danger)]" data-testid="unit-error">{error}</p>}
        </div>
      </Drawer>
    </Card>
  )
}
