import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { BillingPanel } from '@/components/billing/BillingPanel'
import { apiGet, apiPatch, apiPost } from '@/lib/api'
import { can, useAuth, useEntitled } from '@/stores/auth'
import type { Thresholds } from '@/features/automation'
import { useUi } from '@/stores/ui'
import { SetupCheck } from '@/components/onboarding/SetupCheck'
import { AnomalySettingsPanel } from '@/components/settings/AnomalySettingsPanel'
import { EmailDeliveryPanel } from '@/components/settings/EmailDeliveryPanel'
import { SelfApprovalPanel } from '@/components/settings/SelfApprovalPanel'
import { PrivacyTab } from '@/components/settings/PrivacyTab'
import { SlaRulesCard } from '@/components/settings/SlaRulesCard'
import { useApiError } from '@/hooks/useApiError'
import { TIMEZONES } from '@/lib/locales'
import {
  Badge, Button, Card, CardBody, CardHeader, ErrorState, Input, Modal,
  PageHeader, Select, Skeleton, Textarea,
} from '@/components/ui'
import type { Branding, CustomField, LostReason, TenantSettings } from '@/types/api'
import { cn } from '@/lib/cn'

interface SettingsResponse {
  branding: Branding
  settings: TenantSettings
  thresholds?: Thresholds
  subscription: { plan: string; status: string; trial_ends_at: string | null; subscription_ends_at: string | null }
  lost_reasons: LostReason[]
  custom_fields: CustomField[]
}

type Tab = 'general' | 'branding' | 'leads' | 'scoring' | 'alerts' | 'approvals' | 'delivery' | 'privacy' | 'subscription'


/**
 * The week, in ISO numbers because `Carbon::dayOfWeekIso` is what reads them on
 * the other side. Sunday is 7 and starts the list, which is the week here and
 * the easiest thing to get wrong.
 */
const WEEK = [
  { iso: 7, key: 'sun' },
  { iso: 1, key: 'mon' },
  { iso: 2, key: 'tue' },
  { iso: 3, key: 'wed' },
  { iso: 4, key: 'thu' },
  { iso: 5, key: 'fri' },
  { iso: 6, key: 'sat' },
] as const

export function SettingsPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const setBranding = useAuth((s) => s.setBranding)
  const brandingOn = useEntitled('custom_branding')
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [tab, setTab] = useState<Tab>('general')

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['settings'],
    queryFn: () => apiGet<SettingsResponse>('/settings'),
  })

  const canManage = can.manageTenant(user)
  const tabs: Tab[] = canManage
    ? ['general', 'branding', 'leads', 'scoring', 'alerts', 'approvals', 'delivery', 'privacy', 'subscription']
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

      {/* A settings page is navigated, not scrolled: the sections are named
          and switched rather than stacked into one long column. */}
      <div className="scroll-x mb-4 flex gap-1 rounded-[var(--radius-control)] bg-[var(--color-surface-sunken)] p-1">
        {tabs.map((key) => (
          <button
            key={key}
            type="button"
            data-testid={`settings-tab-${key}`}
            aria-current={tab === key}
            onClick={() => setTab(key)}
            className={cn(
              'rounded-[calc(var(--radius-control)-0.125rem)] px-3 py-1.5',
              'text-[0.9375rem] font-medium whitespace-nowrap transition-colors',
              tab === key
                ? 'bg-[var(--color-surface)] text-[var(--color-ink)] shadow-[var(--shadow-card)]'
                : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]',
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
          {tab === 'branding' && canManage && !brandingOn && (
            <p className="mb-3 rounded-[var(--radius-md)] bg-[var(--color-surface-sunken)] p-3 text-[0.9375rem]" data-testid="branding-locked">{t('billing.brandingLocked')}</p>
          )}
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
            <div className="space-y-4">
              <GeneralForm
                settings={data.settings}
                section="general"
                onSaved={() => {
                  void queryClient.invalidateQueries({ queryKey: ['settings'] })
                  toast(t('settings.saved'), 'success')
                }}
              />
              {/*
                Under the general settings, because this is the thing to do
                after changing them: the only way to find out whether the
                workspace is actually set up is to send a lead through it.
              */}
              <SetupCheck />
            </div>
          )}

          {tab === 'scoring' && canManage && (
            <div className="space-y-4">
              <GeneralForm
                settings={data.settings}
                thresholds={data.thresholds}
                section="scoring"
                onSaved={() => {
                  void queryClient.invalidateQueries({ queryKey: ['settings'] })
                  toast(t('settings.saved'), 'success')
                }}
              />
              <SlaRulesCard rules={data.settings.sla_rules} base={data.settings.first_contact_target_minutes ?? 30} />
            </div>
          )}

          {/* Lead settings: the two lists that shape how leads are recorded. */}
          {tab === 'leads' && canManage && (
            <div className="space-y-4">
              <LostReasonsPanel reasons={data.lost_reasons} />
              <CustomFieldsPanel fields={data.custom_fields} />
            </div>
          )}

          {tab === 'alerts' && canManage && <AnomalySettingsPanel />}

          {tab === 'approvals' && canManage && <SelfApprovalPanel />}

          {tab === 'delivery' && canManage && <EmailDeliveryPanel />}

          {tab === 'privacy' && canManage && <PrivacyTab />}

          {tab === 'subscription' && <BillingPanel />}
        </>
      )}
    </>
  )
}

function BrandingForm({ branding, onSaved }: { branding: Branding; onSaved: (b: Branding) => void }) {
  const { t } = useTranslation()
  const translateError = useApiError()
  const toast = useUi((s) => s.toast)

  const [form, setForm] = useState({
    company_name: branding.company_name,
    // Empty means "not renamed": the product shows its own name.
    system_name: branding.system_name ?? '',
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
              onChange={(e) => setForm((prev) => ({ ...prev, company_name: e.target.value }))}
            />
            <Input
              label={t('settings.systemName')}
              hint={t('settings.systemNameHint')}
              placeholder={t('common.appName')}
              value={form.system_name}
              onChange={(e) => setForm((prev) => ({ ...prev, system_name: e.target.value }))}
            />
          </div>

          <Input
            dir="ltr"
            label={t('settings.logoUrl')}
            hint={t('common.optional')}
            value={form.logo_url}
            onChange={(e) => setForm((prev) => ({ ...prev, logo_url: e.target.value }))}
            placeholder="https://…"
          />

          <div className="grid gap-4 sm:grid-cols-2">
            <ColorInput
              label={t('settings.primaryColor')}
              value={form.primary_color}
              onChange={(value) => setForm((prev) => ({ ...prev, primary_color: value }))}
            />
            <ColorInput
              label={t('settings.secondaryColor')}
              value={form.secondary_color}
              onChange={(value) => setForm((prev) => ({ ...prev, secondary_color: value }))}
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              dir="ltr"
              type="email"
              label={t('settings.supportEmail')}
              hint={t('common.optional')}
              value={form.support_email}
              onChange={(e) => setForm((prev) => ({ ...prev, support_email: e.target.value }))}
            />
            <Input
              dir="ltr"
              label={t('settings.supportPhone')}
              hint={t('common.optional')}
              value={form.support_phone}
              onChange={(e) => setForm((prev) => ({ ...prev, support_phone: e.target.value }))}
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
      <p className="mb-1.5 text-[0.9375rem] font-medium">{label}</p>
      <div className="flex items-center gap-2">
        <input
          type="color"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          aria-label={label}
          className="size-10 shrink-0 cursor-pointer rounded-[var(--radius-control)] border border-[var(--color-border-strong)] bg-transparent p-1"
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

/**
 * Workspace basics and scoring share a form because they share one endpoint —
 * splitting them into two requests would let a half-saved workspace end up
 * with a currency from one submit and a threshold from another.
 */
function GeneralForm({
  settings,
  thresholds,
  section,
  onSaved,
}: {
  settings: TenantSettings
  thresholds?: Thresholds
  section: 'general' | 'scoring'
  onSaved: () => void
}) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [form, setForm] = useState({
    locale: settings.locale,
    currency: settings.currency,
    timezone: settings.timezone,
    default_country_code: settings.default_country_code,
    assignment_mode: settings.assignment_mode,
    assignment_strategy: settings.assignment_strategy ?? 'workload',
    sla_hot_minutes: settings.sla_hot_minutes ?? '',
    sla_interested_minutes: settings.sla_interested_minutes ?? '',
    sla_visit_minutes: settings.sla_visit_minutes ?? '',
    sla_reservation_minutes: settings.sla_reservation_minutes ?? '',
    score_warm_threshold: settings.score_warm_threshold,
    score_hot_threshold: settings.score_hot_threshold,
    score_priority_threshold: settings.score_priority_threshold ?? '',
    high_value_from: settings.high_value_from ?? '',
    stalled_after_days: settings.stalled_after_days ?? '',
    first_contact_target_minutes: settings.first_contact_target_minutes ?? 30,
    whatsapp_template: settings.whatsapp_template ?? '',
    work_days: settings.work_days ?? [],
    work_starts_at: settings.work_starts_at?.slice(0, 5) ?? '09:00',
    work_ends_at: settings.work_ends_at?.slice(0, 5) ?? '18:00',
    quiet_hours_enabled: settings.quiet_hours_enabled ?? false,
    quiet_hours_start: settings.quiet_hours_start?.slice(0, 5) ?? '21:00',
    quiet_hours_end: settings.quiet_hours_end?.slice(0, 5) ?? '08:00',
    max_contacts_per_day: settings.max_contacts_per_day ?? 6,
    contact_cooldown_minutes: settings.contact_cooldown_minutes ?? 0,
  })

  const mutation = useMutation({
    mutationFn: () => apiPatch('/settings', {
      ...form,
      // A blank is "the default", sent as null so the server puts it back.
      score_priority_threshold: form.score_priority_threshold === '' ? null : Number(form.score_priority_threshold),
      high_value_from: form.high_value_from === '' ? null : Number(form.high_value_from),
      stalled_after_days: form.stalled_after_days === '' ? null : Number(form.stalled_after_days),
      sla_hot_minutes: form.sla_hot_minutes === '' ? null : Number(form.sla_hot_minutes),
      sla_interested_minutes: form.sla_interested_minutes === '' ? null : Number(form.sla_interested_minutes),
      sla_visit_minutes: form.sla_visit_minutes === '' ? null : Number(form.sla_visit_minutes),
      sla_reservation_minutes: form.sla_reservation_minutes === '' ? null : Number(form.sla_reservation_minutes),
      /*
       * No days chosen means always open, and the hours go with it — sending
       * times beside an empty week would store an opinion nobody expressed.
       */
      work_days: form.work_days.length ? form.work_days : null,
      work_starts_at: form.work_days.length ? form.work_starts_at : null,
      work_ends_at: form.work_days.length ? form.work_ends_at : null,
    }),
    onSuccess: onSaved,
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Card>
      <CardHeader
        title={t(section === 'general' ? 'settings.general' : 'settings.scoring')}
        description={t(section === 'general' ? 'settings.generalHint' : 'settings.scoringHint')}
      />
      <CardBody>
        <form
          onSubmit={(e) => {
            e.preventDefault()
            mutation.mutate()
          }}
          className="space-y-4"
        >
          {section === 'general' && (
          <div className="grid gap-4 sm:grid-cols-3">
            <Select
              label={t('settings.language')}
              value={form.locale}
              onChange={(e) => setForm((prev) => ({ ...prev, locale: e.target.value as 'ar' | 'en' }))}
            >
              <option value="ar">العربية</option>
              <option value="en">English</option>
            </Select>
            <Input
              dir="ltr"
              label={t('settings.currency')}
              value={form.currency}
              maxLength={3}
              onChange={(e) => setForm((prev) => ({ ...prev, currency: e.target.value.toUpperCase() }))}
            />
            <Input
              dir="ltr"
              label={t('settings.countryCode')}
              value={form.default_country_code}
              onChange={(e) => setForm((prev) => ({ ...prev, default_country_code: e.target.value }))}
            />
          </div>
          )}

          {/*
            The timezone drives how every date in the app is read. It was
            settable through the API but had no control here, so a tenant outside
            Riyadh could not correct it without a support request.
          */}
          {section === 'general' && (
            <Select
              label={t('settings.timezone')}
              value={form.timezone}
              onChange={(e) => setForm((prev) => ({ ...prev, timezone: e.target.value }))}
            >
              {TIMEZONES.map((zone) => (
                <option key={zone} value={zone}>
                  {zone}
                </option>
              ))}
            </Select>
          )}

          {section === 'general' ? (
            <div className="space-y-4">
              <Select
                label={t('settings.assignmentMode')}
                hint={t('settings.assignmentHint')}
                value={form.assignment_mode}
                onChange={(e) => setForm((prev) => ({ ...prev, assignment_mode: e.target.value as 'manual' | 'round_robin' }))}
              >
                <option value="manual">{t('settings.manual')}</option>
                <option value="round_robin">{t('settings.roundRobin')}</option>
              </Select>
              {/* Who is chosen once the rules (project, language, city, deal type, who is in) have narrowed the team. */}
              {form.assignment_mode === 'round_robin' && (
                <Select
                  label={t('settings.assignmentStrategy')}
                  hint={t('settings.assignmentStrategyHint')}
                  value={form.assignment_strategy}
                  data-testid="assignment-strategy"
                  onChange={(e) => setForm((prev) => ({ ...prev, assignment_strategy: e.target.value as 'workload' | 'round_robin' | 'performance' }))}
                >
                  <option value="workload">{t('settings.strategy.workload')}</option>
                  <option value="round_robin">{t('settings.strategy.round_robin')}</option>
                  <option value="performance">{t('settings.strategy.performance')}</option>
                </Select>
              )}
            </div>
          ) : (
            <>
              {/*
                The thresholds decide what "hot" means in this workspace, which
                is the number a manager sorts by — it belongs with scoring, not
                buried under currency and timezone.
              */}
              <div className="grid gap-4 sm:grid-cols-3">
                <Input
                  type="number"
                  min={1}
                  max={99}
                  label={t('settings.warmThreshold')}
                  value={form.score_warm_threshold}
                  onChange={(e) => setForm((prev) => ({ ...prev, score_warm_threshold: Number(e.target.value) }))}
                />
                <Input
                  type="number"
                  min={2}
                  max={100}
                  label={t('settings.hotThreshold')}
                  value={form.score_hot_threshold}
                  onChange={(e) => setForm((prev) => ({ ...prev, score_hot_threshold: Number(e.target.value) }))}
                />
                <Input
                  type="number"
                  min={1}
                  max={100}
                  label={t('thresholds.priority_from')}
                  placeholder={String(thresholds?.priority_from.default ?? 88)}
                  value={form.score_priority_threshold}
                  data-testid="threshold-priority"
                  onChange={(e) => setForm((prev) => ({ ...prev, score_priority_threshold: e.target.value }))}
                />
                <Input
                  type="number"
                  min={1}
                  label={t('thresholds.high_value_from')}
                  hint={t('thresholds.high_value_hint')}
                  placeholder={String(thresholds?.high_value_from.default ?? 500000)}
                  value={form.high_value_from}
                  data-testid="threshold-high-value"
                  onChange={(e) => setForm((prev) => ({ ...prev, high_value_from: e.target.value }))}
                />
                <Input
                  type="number"
                  min={1}
                  max={90}
                  label={t('thresholds.stalled_after_days')}
                  hint={t('thresholds.stalled_hint')}
                  placeholder={String(thresholds?.stalled_after_days.default ?? 3)}
                  value={form.stalled_after_days}
                  data-testid="threshold-stalled"
                  onChange={(e) => setForm((prev) => ({ ...prev, stalled_after_days: e.target.value }))}
                />
                {/* How long an interested lead may wait for a person, by how far along they are. */}
                {(['sla_reservation_minutes', 'sla_visit_minutes', 'sla_hot_minutes', 'sla_interested_minutes'] as const).map((key) => (
                  <Input
                    key={key}
                    type="number"
                    min={1}
                    max={10_080}
                    label={t(`thresholds.${key}`)}
                    placeholder={String(thresholds?.[key]?.default ?? '')}
                    value={form[key]}
                    data-testid={`threshold-${key}`}
                    onChange={(e) => setForm((prev) => ({ ...prev, [key]: e.target.value }))}
                  />
                ))}
                <Input
                  type="number"
                  min={1}
                  max={10_080}
                  label={t('settings.firstContactTarget')}
                  hint={t('settings.firstContactHint')}
                  value={form.first_contact_target_minutes}
                  onChange={(e) =>
                    setForm((prev) => ({ ...prev, first_contact_target_minutes: Number(e.target.value) }))
                  }
                />
              </div>

              {/*
                When the business is open.

                The response-time clock counted wall-clock minutes, so a lead
                arriving at two in the morning was escalated to the management
                before anybody could have called. Leaving every day unticked
                means always open, which is where every workspace starts.
              */}
              <fieldset className="space-y-2">
                <legend className="t-small mb-1 font-medium">{t('settings.workingHours')}</legend>
                <p className="t-caption">{t('settings.workingHoursHint')}</p>

                <div className="flex flex-wrap gap-2" data-testid="work-days">
                  {WEEK.map(({ iso, key }) => {
                    const on = form.work_days.includes(iso)

                    return (
                      <button
                        key={iso}
                        type="button"
                        aria-pressed={on}
                        data-testid={`work-day-${iso}`}
                        onClick={() =>
                          setForm((prev) => ({
                            ...prev,
                            work_days: on
                              ? prev.work_days.filter((d) => d !== iso)
                              : [...prev.work_days, iso].sort((a, b) => a - b),
                          }))
                        }
                        className={cn(
                          'pressable rounded-full border px-3 py-1 text-[0.9375rem]',
                          on
                            ? 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-ink)]'
                            : 'border-[var(--color-border)] text-[var(--color-ink-muted)]',
                        )}
                      >
                        {t(`settings.weekday.${key}`)}
                      </button>
                    )
                  })}
                </div>

                {form.work_days.length > 0 && (
                  <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                      type="time"
                      dir="ltr"
                      label={t('settings.workStarts')}
                      value={form.work_starts_at}
                      data-testid="work-starts-at"
                      onChange={(e) => setForm((prev) => ({ ...prev, work_starts_at: e.target.value }))}
                    />
                    <Input
                      type="time"
                      dir="ltr"
                      label={t('settings.workEnds')}
                      value={form.work_ends_at}
                      data-testid="work-ends-at"
                      onChange={(e) => setForm((prev) => ({ ...prev, work_ends_at: e.target.value }))}
                    />
                  </div>
                )}
              </fieldset>

              {/*
                What automation may not do to a person. Checked at the moment
                of sending, so a rule written months ago still keeps to it.
              */}
              <fieldset className="space-y-3" data-testid="guardrails">
                <legend className="t-small mb-1 font-medium">{t('settings.guardrails')}</legend>
                <p className="t-caption">{t('settings.guardrailsHint')}</p>

                <div className="flex flex-wrap items-center gap-3">
                  <button
                    type="button"
                    aria-pressed={form.quiet_hours_enabled}
                    data-testid="quiet-hours-toggle"
                    onClick={() => setForm((prev) => ({ ...prev, quiet_hours_enabled: !prev.quiet_hours_enabled }))}
                    className={cn(
                      'pressable rounded-full border px-3 py-1 text-[0.9375rem]',
                      form.quiet_hours_enabled
                        ? 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-ink)]'
                        : 'border-[var(--color-border)] text-[var(--color-ink-muted)]',
                    )}
                  >
                    {t('settings.quietHours')}
                  </button>
                  {form.quiet_hours_enabled && (
                    <>
                      <Input
                        type="time"
                        dir="ltr"
                        aria-label={t('settings.quietFrom')}
                        value={form.quiet_hours_start}
                        data-testid="quiet-hours-start"
                        onChange={(e) => setForm((prev) => ({ ...prev, quiet_hours_start: e.target.value }))}
                      />
                      <span className="t-caption">{t('settings.quietTo')}</span>
                      <Input
                        type="time"
                        dir="ltr"
                        aria-label={t('settings.quietTo')}
                        value={form.quiet_hours_end}
                        data-testid="quiet-hours-end"
                        onChange={(e) => setForm((prev) => ({ ...prev, quiet_hours_end: e.target.value }))}
                      />
                    </>
                  )}
                </div>
                <p className="t-caption">{t('settings.quietHoursHint')}</p>

                <div className="grid gap-4 sm:grid-cols-2">
                  <Input
                    type="number"
                    min={1}
                    max={50}
                    label={t('settings.maxContacts')}
                    value={form.max_contacts_per_day}
                    data-testid="max-contacts"
                    onChange={(e) => setForm((prev) => ({ ...prev, max_contacts_per_day: Number(e.target.value) }))}
                  />
                  <Input
                    type="number"
                    min={0}
                    max={1440}
                    label={t('settings.cooldown')}
                    hint={t('settings.cooldownHint')}
                    value={form.contact_cooldown_minutes}
                    data-testid="contact-cooldown"
                    onChange={(e) => setForm((prev) => ({ ...prev, contact_cooldown_minutes: Number(e.target.value) }))}
                  />
                </div>
              </fieldset>

              <Textarea
                label={t('settings.whatsappTemplate')}
                hint={t('settings.whatsappHint')}
                value={form.whatsapp_template}
                onChange={(e) => setForm((prev) => ({ ...prev, whatsapp_template: e.target.value }))}
                rows={3}
              />
            </>
          )}

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
              <span className={cn('text-[1rem]', !reason.is_active && 'text-[var(--color-ink-subtle)] line-through')}>
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
            <p className="py-6 text-center text-[0.9375rem] text-[var(--color-ink-muted)]">
              {t('common.noResults')}
            </p>
          ) : (
            <ul className="divide-y divide-[var(--color-border)]">
              {fields.map((field) => (
                <li key={field.id} className="flex items-center justify-between gap-3 py-2.5">
                  <div className="min-w-0">
                    <p className="truncate text-[1rem] font-medium">{field.label}</p>
                    <p className="text-[0.875rem] text-[var(--color-ink-subtle)]"><bdi dir="ltr">{field.key}</bdi></p>
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
            onChange={(e) => setForm((prev) => ({ ...prev, key: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') }))}
          />
          <Input
            label={t('settings.fieldLabel')}
            value={form.label}
            onChange={(e) => setForm((prev) => ({ ...prev, label: e.target.value }))}
          />
          <Select
            label={t('settings.fieldType')}
            value={form.type}
            onChange={(e) => setForm((prev) => ({ ...prev, type: e.target.value }))}
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
              onChange={(e) => setForm((prev) => ({ ...prev, options: e.target.value }))}
              rows={4}
            />
          )}
        </div>
      </Modal>
    </>
  )
}
