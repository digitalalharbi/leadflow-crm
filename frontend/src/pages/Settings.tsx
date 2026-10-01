import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet, apiPatch, apiPost } from '@/lib/api'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatDate } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, CardHeader, ErrorState, Input, Modal,
  PageHeader, Select, Skeleton, Textarea,
} from '@/components/ui'
import type { Branding, CustomField, LostReason, TenantSettings } from '@/types/api'
import { cn } from '@/lib/cn'

interface SettingsResponse {
  branding: Branding
  settings: TenantSettings
  subscription: { plan: string; status: string; trial_ends_at: string | null; subscription_ends_at: string | null }
  lost_reasons: LostReason[]
  custom_fields: CustomField[]
}

type Tab = 'branding' | 'general' | 'lostReasons' | 'customFields' | 'subscription'

export function SettingsPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const setBranding = useAuth((s) => s.setBranding)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [tab, setTab] = useState<Tab>('branding')

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['settings'],
    queryFn: () => apiGet<SettingsResponse>('/settings'),
  })

  const canManage = can.manageTenant(user)
  const tabs: Tab[] = canManage
    ? ['branding', 'general', 'lostReasons', 'customFields', 'subscription']
    : ['subscription']

  if (isError) {
    return (
      <>
        <PageHeader title={t('settings.title')} />
        <Card><ErrorState message={translateError(error)} onRetry={() => void refetch()} /></Card>
      </>
    )
  }

  return (
    <>
      <PageHeader title={t('settings.title')} subtitle={t('settings.subtitle')} />

      <div className="scroll-x mb-4 flex gap-1">
        {tabs.map((key) => (
          <button
            key={key}
            type="button"
            onClick={() => setTab(key)}
            className={cn(
              'rounded-lg px-3 py-1.5 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
              tab === key
                ? 'bg-[var(--brand-soft)] text-[var(--brand)]'
                : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)]',
            )}
          >
            {t(`settings.${key}`)}
          </button>
        ))}
      </div>

      {isLoading || !data ? (
        <Skeleton className="h-96" />
      ) : (
        <>
          {tab === 'branding' && canManage && (
            <BrandingForm
              branding={data.branding}
              onSaved={(updated) => {
                setBranding(updated)
                void queryClient.invalidateQueries({ queryKey: ['settings'] })
                toast(t('settings.saved'), 'success')
              }}
            />
          )}

          {tab === 'general' && canManage && (
            <GeneralForm
              settings={data.settings}
              onSaved={() => {
                void queryClient.invalidateQueries({ queryKey: ['settings'] })
                toast(t('settings.saved'), 'success')
              }}
            />
          )}

          {tab === 'lostReasons' && canManage && <LostReasonsPanel reasons={data.lost_reasons} />}
          {tab === 'customFields' && canManage && <CustomFieldsPanel fields={data.custom_fields} />}

          {tab === 'subscription' && (
            <Card>
              <CardHeader title={t('settings.subscription')} />
              <CardBody className="space-y-3">
                <Row label={t('settings.plan')} value={data.subscription.plan} />
                <Row
                  label={t('common.status')}
                  value={
                    <Badge tone={data.subscription.status === 'active' ? 'success' : 'info'}>
                      {data.subscription.status}
                    </Badge>
                  }
                />
                {data.subscription.trial_ends_at && (
                  <Row
                    label={t('settings.trialEnds')}
                    value={formatDate(data.subscription.trial_ends_at, i18n.language)}
                  />
                )}
              </CardBody>
            </Card>
          )}
        </>
      )}
    </>
  )
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 text-[0.875rem]">
      <span className="text-[var(--color-ink-muted)]">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  )
}

function BrandingForm({ branding, onSaved }: { branding: Branding; onSaved: (b: Branding) => void }) {
  const { t } = useTranslation()
  const translateError = useApiError()
  const toast = useUi((s) => s.toast)

  const [form, setForm] = useState({
    company_name: branding.company_name,
    system_name: branding.system_name,
    logo_url: branding.logo_url ?? '',
    login_background_url: branding.login_background_url ?? '',
    primary_color: branding.primary_color,
    secondary_color: branding.secondary_color,
    support_email: branding.support_email ?? '',
    support_phone: branding.support_phone ?? '',
  })

  // Live preview: the colour picker updates the running app immediately.
  useEffect(() => {
    document.documentElement.style.setProperty('--brand', form.primary_color)
    document.documentElement.style.setProperty(
      '--brand-soft',
      `color-mix(in srgb, ${form.primary_color} 10%, white)`,
    )
  }, [form.primary_color])

  const mutation = useMutation({
    mutationFn: () =>
      apiPatch<{ branding: Branding }>('/settings/branding', {
        ...form,
        logo_url: form.logo_url || null,
        login_background_url: form.login_background_url || null,
        support_email: form.support_email || null,
        support_phone: form.support_phone || null,
      }),
    onSuccess: (result) => onSaved(result.branding),
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    mutation.mutate()
  }

  return (
    <Card>
      <CardHeader title={t('settings.branding')} />
      <CardBody>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              label={t('settings.companyName')}
              value={form.company_name}
              onChange={(e) => setForm({ ...form, company_name: e.target.value })}
            />
            <Input
              label={t('settings.systemName')}
              value={form.system_name}
              onChange={(e) => setForm({ ...form, system_name: e.target.value })}
            />
          </div>

          <Input
            dir="ltr"
            label={t('settings.logoUrl')}
            hint={t('common.optional')}
            value={form.logo_url}
            onChange={(e) => setForm({ ...form, logo_url: e.target.value })}
            placeholder="https://…"
          />

          <div className="grid gap-4 sm:grid-cols-2">
            <ColorInput
              label={t('settings.primaryColor')}
              value={form.primary_color}
              onChange={(value) => setForm({ ...form, primary_color: value })}
            />
            <ColorInput
              label={t('settings.secondaryColor')}
              value={form.secondary_color}
              onChange={(value) => setForm({ ...form, secondary_color: value })}
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              dir="ltr"
              type="email"
              label={t('settings.supportEmail')}
              hint={t('common.optional')}
              value={form.support_email}
              onChange={(e) => setForm({ ...form, support_email: e.target.value })}
            />
            <Input
              dir="ltr"
              label={t('settings.supportPhone')}
              hint={t('common.optional')}
              value={form.support_phone}
              onChange={(e) => setForm({ ...form, support_phone: e.target.value })}
            />
          </div>

          <div className="flex justify-end">
            <Button type="submit" loading={mutation.isPending}>{t('common.save')}</Button>
          </div>
        </form>
      </CardBody>
    </Card>
  )
}

function ColorInput({
  label,
  value,
  onChange,
}: {
  label: string
  value: string
  onChange: (value: string) => void
}) {
  return (
    <div>
      <p className="mb-1.5 text-[0.8125rem] font-medium">{label}</p>
      <div className="flex items-center gap-2">
        <input
          type="color"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          aria-label={label}
          className="size-10 shrink-0 cursor-pointer rounded-lg border border-[var(--color-border-strong)] bg-transparent p-1"
        />
        <Input
          dir="ltr"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          containerClassName="flex-1"
        />
      </div>
    </div>
  )
}

function GeneralForm({ settings, onSaved }: { settings: TenantSettings; onSaved: () => void }) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [form, setForm] = useState({
    locale: settings.locale,
    currency: settings.currency,
    timezone: settings.timezone,
    default_country_code: settings.default_country_code,
    assignment_mode: settings.assignment_mode,
    score_warm_threshold: settings.score_warm_threshold,
    score_hot_threshold: settings.score_hot_threshold,
    whatsapp_template: settings.whatsapp_template ?? '',
  })

  const mutation = useMutation({
    mutationFn: () => apiPatch('/settings', form),
    onSuccess: onSaved,
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Card>
      <CardHeader title={t('settings.general')} />
      <CardBody>
        <form
          onSubmit={(e) => {
            e.preventDefault()
            mutation.mutate()
          }}
          className="space-y-4"
        >
          <div className="grid gap-4 sm:grid-cols-3">
            <Select
              label={t('settings.language')}
              value={form.locale}
              onChange={(e) => setForm({ ...form, locale: e.target.value as 'ar' | 'en' })}
            >
              <option value="ar">العربية</option>
              <option value="en">English</option>
            </Select>
            <Input
              dir="ltr"
              label={t('settings.currency')}
              value={form.currency}
              maxLength={3}
              onChange={(e) => setForm({ ...form, currency: e.target.value.toUpperCase() })}
            />
            <Input
              dir="ltr"
              label={t('settings.countryCode')}
              value={form.default_country_code}
              onChange={(e) => setForm({ ...form, default_country_code: e.target.value })}
            />
          </div>

          <Select
            label={t('settings.assignmentMode')}
            hint={t('settings.assignmentHint')}
            value={form.assignment_mode}
            onChange={(e) => setForm({ ...form, assignment_mode: e.target.value as 'manual' | 'round_robin' })}
          >
            <option value="manual">{t('settings.manual')}</option>
            <option value="round_robin">{t('settings.roundRobin')}</option>
          </Select>

          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              type="number"
              min={1}
              max={99}
              label={t('settings.warmThreshold')}
              value={form.score_warm_threshold}
              onChange={(e) => setForm({ ...form, score_warm_threshold: Number(e.target.value) })}
            />
            <Input
              type="number"
              min={2}
              max={100}
              label={t('settings.hotThreshold')}
              value={form.score_hot_threshold}
              onChange={(e) => setForm({ ...form, score_hot_threshold: Number(e.target.value) })}
            />
          </div>

          <Textarea
            label={t('settings.whatsappTemplate')}
            hint={t('settings.whatsappHint')}
            value={form.whatsapp_template}
            onChange={(e) => setForm({ ...form, whatsapp_template: e.target.value })}
            rows={3}
          />

          <div className="flex justify-end">
            <Button type="submit" loading={mutation.isPending}>{t('common.save')}</Button>
          </div>
        </form>
      </CardBody>
    </Card>
  )
}

function LostReasonsPanel({ reasons }: { reasons: LostReason[] }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const [label, setLabel] = useState('')

  const create = useMutation({
    mutationFn: () => apiPost('/settings/lost-reasons', { label }),
    onSuccess: () => {
      setLabel('')
      void queryClient.invalidateQueries({ queryKey: ['settings'] })
      toast(t('settings.saved'), 'success')
    },
  })

  const toggle = useMutation({
    mutationFn: (reason: LostReason) =>
      apiPatch(`/settings/lost-reasons/${reason.id}`, { is_active: !reason.is_active }),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['settings'] }),
  })

  return (
    <Card>
      <CardHeader title={t('settings.lostReasons')} />
      <CardBody className="space-y-3">
        <ul className="divide-y divide-[var(--color-border)]">
          {reasons.map((reason) => (
            <li key={reason.id} className="flex items-center justify-between gap-3 py-2.5">
              <span className={cn('text-[0.875rem]', !reason.is_active && 'text-[var(--color-ink-subtle)] line-through')}>
                {reason.label}
              </span>
              <Button variant="ghost" size="sm" onClick={() => toggle.mutate(reason)}>
                {reason.is_active ? t('users.deactivate') : t('users.activate')}
              </Button>
            </li>
          ))}
        </ul>

        <form
          onSubmit={(e) => {
            e.preventDefault()
            if (label.trim()) create.mutate()
          }}
          className="flex gap-2 border-t border-[var(--color-border)] pt-3"
        >
          <Input
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            placeholder={t('settings.addLostReason')}
            containerClassName="flex-1"
          />
          <Button type="submit" loading={create.isPending} disabled={!label.trim()} icon={<Plus className="size-4" />}>
            {t('common.add')}
          </Button>
        </form>
      </CardBody>
    </Card>
  )
}

function CustomFieldsPanel({ fields }: { fields: CustomField[] }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ key: '', label: '', type: 'text', options: '' })

  const create = useMutation({
    mutationFn: () =>
      apiPost('/settings/custom-fields', {
        key: form.key,
        label: form.label,
        type: form.type,
        options:
          form.type === 'select'
            ? form.options.split('\n').map((line) => line.trim()).filter(Boolean)
            : undefined,
      }),
    onSuccess: () => {
      setOpen(false)
      setForm({ key: '', label: '', type: 'text', options: '' })
      void queryClient.invalidateQueries({ queryKey: ['settings'] })
      toast(t('settings.saved'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <>
      <Card>
        <CardHeader
          title={t('settings.customFields')}
          action={
            <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>
              {t('common.add')}
            </Button>
          }
        />
        <CardBody>
          {fields.length === 0 ? (
            <p className="py-6 text-center text-[0.8125rem] text-[var(--color-ink-muted)]">
              {t('common.noResults')}
            </p>
          ) : (
            <ul className="divide-y divide-[var(--color-border)]">
              {fields.map((field) => (
                <li key={field.id} className="flex items-center justify-between gap-3 py-2.5">
                  <div className="min-w-0">
                    <p className="truncate text-[0.875rem] font-medium">{field.label}</p>
                    <p className="text-[0.75rem] text-[var(--color-ink-subtle)]" dir="ltr">{field.key}</p>
                  </div>
                  <Badge>{t(`settings.type${field.type[0].toUpperCase()}${field.type.slice(1)}`, { defaultValue: field.type })}</Badge>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={t('settings.addCustomField')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setOpen(false)}>{t('common.cancel')}</Button>
            <Button loading={create.isPending} onClick={() => create.mutate()}>{t('common.save')}</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Input
            dir="ltr"
            label={t('settings.fieldKey')}
            hint="a-z, 0-9, _"
            value={form.key}
            onChange={(e) => setForm({ ...form, key: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })}
          />
          <Input
            label={t('settings.fieldLabel')}
            value={form.label}
            onChange={(e) => setForm({ ...form, label: e.target.value })}
          />
          <Select
            label={t('settings.fieldType')}
            value={form.type}
            onChange={(e) => setForm({ ...form, type: e.target.value })}
          >
            <option value="text">{t('settings.typeText')}</option>
            <option value="number">{t('settings.typeNumber')}</option>
            <option value="select">{t('settings.typeSelect')}</option>
            <option value="date">{t('settings.typeDate')}</option>
            <option value="boolean">{t('settings.typeBoolean')}</option>
          </Select>
          {form.type === 'select' && (
            <Textarea
              label={t('settings.fieldOptions')}
              value={form.options}
              onChange={(e) => setForm({ ...form, options: e.target.value })}
              rows={4}
            />
          )}
        </div>
      </Modal>
    </>
  )
}
