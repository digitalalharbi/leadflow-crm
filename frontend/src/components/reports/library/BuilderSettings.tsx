import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Input, Select } from '@/components/ui'
import { useCampaigns } from '@/features/campaigns'
import { fetchAssignableUsers, fetchProjects } from '@/features/leads'
import { ATTRIBUTION_MODELS, COMPARE_MODES, REPORT_PERIODS, type AttributionModel, type CompareMode, type ReportBranding, type ReportFilters, type ReportOptions, type ReportPeriodKey, type ReportShare, type ShareInput } from '@/features/reportShares'
import { useEntitled } from '@/stores/auth'

/** Everything beyond the sections: the client, the projects, the period and its comparison, the model, the filters, the look. */
export interface SettingsState {
  client: string
  projectIds: string[]
  period: ReportPeriodKey
  periodFrom: string
  periodTo: string
  compareMode: CompareMode
  compareFrom: string
  compareTo: string
  model: AttributionModel
  currency: string
  filters: ReportFilters
  branding: ReportBranding
  options: ReportOptions
}

export const AD_PLATFORMS = ['meta', 'tiktok', 'snapchat', 'google_ads', 'x', 'linkedin'] as const

/** The channels a lead can be filed under — the same ones the sources section lists. */
export const CHANNELS = ['meta', 'tiktok', 'snapchat', 'google_ads', 'linkedin', 'x', 'whatsapp', 'referral', 'broker', 'influencer', 'qr', 'portals', 'organic', 'social', 'landing_page', 'form', 'manual', 'import', 'other'] as const

export function settingsFrom(share: ReportShare | null): SettingsState {
  return {
    client: share?.client_name ?? '',
    projectIds: share?.projects.map((p) => p.id) ?? [],
    period: share?.period ?? 'last_n',
    periodFrom: share?.period_from ?? '',
    periodTo: share?.period_to ?? '',
    compareMode: share?.compare_mode ?? 'previous_period',
    compareFrom: share?.compare_from ?? '',
    compareTo: share?.compare_to ?? '',
    model: share?.attribution_model ?? 'last',
    currency: share?.currency ?? '',
    filters: { ...share?.filters },
    branding: { ...share?.branding },
    options: { ...share?.options },
  }
}

/** What the API takes. A period that follows “last N days” is sent as null so the report’s own `days` keeps governing it. */
export function settingsPayload(s: SettingsState): Partial<ShareInput> {
  const custom = s.period === 'custom'
  const compareCustom = s.compareMode === 'custom'

  return {
    client_name: s.client || null,
    project_ids: s.projectIds,
    period: s.period === 'last_n' ? null : s.period,
    period_from: custom ? s.periodFrom || null : null,
    period_to: custom ? s.periodTo || null : null,
    compare_mode: s.compareMode,
    compare_from: compareCustom ? s.compareFrom || null : null,
    compare_to: compareCustom ? s.compareTo || null : null,
    attribution_model: s.model,
    currency: s.currency ? s.currency.toUpperCase() : null,
    filters: s.filters,
    branding: s.branding,
    options: s.options,
  }
}

function toggle<T>(list: T[] | undefined, value: T): T[] {
  const current = list ?? []

  return current.includes(value) ? current.filter((x) => x !== value) : [...current, value]
}

function Check({ checked, label, onChange, testId, disabled }: { checked: boolean; label: string; onChange: (v: boolean) => void; testId?: string; disabled?: boolean }) {
  return (
    <label className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 hover:bg-[var(--color-surface-muted)]">
      <input type="checkbox" checked={checked} disabled={disabled} data-testid={testId} onChange={(e) => onChange(e.target.checked)} className="size-4 accent-[var(--brand)]" />
      <span className="t-small">{label}</span>
    </label>
  )
}

export function BuilderSettings({ value, onChange, partner }: { value: SettingsState; onChange: (next: SettingsState) => void; partner: boolean }) {
  const { t } = useTranslation()
  const advancedAttribution = useEntitled('advanced_attribution')
  const whiteLabel = useEntitled('white_label_reports')
  const set = <K extends keyof SettingsState>(key: K, v: SettingsState[K]) => onChange({ ...value, [key]: v })

  const projects = useQuery({ queryKey: ['projects-simple'], queryFn: fetchProjects })
  const campaigns = useCampaigns(365, '')
  const users = useQuery({ queryKey: ['assignable-users'], queryFn: fetchAssignableUsers })

  return (
    <div className="space-y-5" data-testid="builder-settings">
      <Input label={t('builder.client')} hint={t('builder.clientHint')} value={value.client} data-testid="builder-client" onChange={(e) => set('client', e.target.value)} />

      <fieldset className="space-y-1">
        <legend className="t-small mb-1 font-medium">{t('builder.projects')}</legend>
        <p className="t-caption">{t('builder.projectsHint')}</p>
        <div className="grid gap-0.5 sm:grid-cols-2" data-testid="builder-projects">
          {(projects.data ?? []).map((p) => <Check key={p.id} checked={value.projectIds.includes(p.id)} label={p.name} testId={`builder-project-${p.id}`} onChange={() => set('projectIds', toggle(value.projectIds, p.id))} />)}
        </div>
      </fieldset>

      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={t('share.period')} value={value.period} data-testid="builder-period" onChange={(e) => set('period', e.target.value as ReportPeriodKey)}>
          <option value="last_n">{t('builder.periodRange.last_n')}</option>
          {REPORT_PERIODS.map((p) => <option key={p} value={p}>{t(`builder.periodRange.${p}`)}</option>)}
        </Select>
        <Select label={t('builder.compareMode.label')} value={value.compareMode} data-testid="builder-compare-mode" onChange={(e) => set('compareMode', e.target.value as CompareMode)}>
          {COMPARE_MODES.map((m) => <option key={m} value={m}>{t(`builder.compareMode.${m}`)}</option>)}
        </Select>
      </div>
      {value.period === 'custom' && (
        <div className="grid gap-3 sm:grid-cols-2" data-testid="builder-custom-period">
          <Input type="date" label={t('builder.from')} value={value.periodFrom} data-testid="builder-period-from" onChange={(e) => set('periodFrom', e.target.value)} />
          <Input type="date" label={t('builder.to')} value={value.periodTo} data-testid="builder-period-to" onChange={(e) => set('periodTo', e.target.value)} />
        </div>
      )}
      {value.compareMode === 'custom' && (
        <div className="grid gap-3 sm:grid-cols-2" data-testid="builder-custom-compare">
          <Input type="date" label={t('builder.from')} value={value.compareFrom} data-testid="builder-compare-from" onChange={(e) => set('compareFrom', e.target.value)} />
          <Input type="date" label={t('builder.to')} value={value.compareTo} data-testid="builder-compare-to" onChange={(e) => set('compareTo', e.target.value)} />
        </div>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={t('builder.model')} value={value.model} data-testid="builder-model" onChange={(e) => set('model', e.target.value as AttributionModel)}>
          {ATTRIBUTION_MODELS.map((m) => <option key={m} value={m} disabled={m !== 'last' && !advancedAttribution}>{t(`builder.modelName.${m}`)}</option>)}
        </Select>
        <Input label={t('builder.currency')} hint="SAR" value={value.currency} maxLength={3} dir="ltr" data-testid="builder-currency" onChange={(e) => set('currency', e.target.value)} />
      </div>

      {!partner && (
        <fieldset className="space-y-3 rounded-xl border border-[var(--color-line)] p-3" data-testid="builder-filters">
          <legend className="t-small px-1 font-medium">{t('builder.filters')}</legend>
          <p className="t-caption">{t('builder.filtersHint')}</p>
          <div>
            <p className="t-caption mb-0.5 font-medium">{t('builder.platforms')}</p>
            <div className="flex flex-wrap gap-x-3">
              {AD_PLATFORMS.map((p) => <Check key={p} checked={(value.filters.platforms ?? []).includes(p)} label={t(`platforms.${p}`, { defaultValue: p })} testId={`builder-platform-${p}`} onChange={() => set('filters', { ...value.filters, platforms: toggle(value.filters.platforms, p) })} />)}
            </div>
          </div>
          <div>
            <p className="t-caption mb-0.5 font-medium">{t('builder.sources')}</p>
            <div className="flex flex-wrap gap-x-3">
              {CHANNELS.map((c) => <Check key={c} checked={(value.filters.sources ?? []).includes(c)} label={t(`rsec.channel.${c}`, { defaultValue: t(`platforms.${c}`, { defaultValue: c }) })} testId={`builder-source-${c}`} onChange={() => set('filters', { ...value.filters, sources: toggle(value.filters.sources, c) })} />)}
            </div>
          </div>
          <div>
            <p className="t-caption mb-0.5 font-medium">{t('builder.campaigns')}</p>
            <div className="grid max-h-40 gap-0.5 overflow-y-auto sm:grid-cols-2">
              {(campaigns.data?.campaigns ?? []).slice(0, 80).map((c) => <Check key={c.id} checked={(value.filters.campaigns ?? []).includes(c.id)} label={c.name} testId={`builder-campaign-${c.id}`} onChange={() => set('filters', { ...value.filters, campaigns: toggle(value.filters.campaigns, c.id) })} />)}
            </div>
          </div>
          <div>
            <p className="t-caption mb-0.5 font-medium">{t('builder.salesUsers')}</p>
            <div className="grid max-h-32 gap-0.5 overflow-y-auto sm:grid-cols-2">
              {(users.data ?? []).map((u) => <Check key={u.id} checked={(value.filters.sales_users ?? []).includes(u.id)} label={u.name} testId={`builder-user-${u.id}`} onChange={() => set('filters', { ...value.filters, sales_users: toggle(value.filters.sales_users, u.id) })} />)}
            </div>
          </div>
        </fieldset>
      )}

      <fieldset className="space-y-3 rounded-xl border border-[var(--color-line)] p-3" data-testid="builder-branding">
        <legend className="t-small px-1 font-medium">{t('builder.branding')}</legend>
        <p className="t-caption">{t('builder.brandingHint')}</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <Input label={t('builder.brandName')} value={value.branding.name ?? ''} maxLength={120} disabled={!whiteLabel} data-testid="builder-brand-name" onChange={(e) => set('branding', { ...value.branding, name: e.target.value })} />
          <Input type="color" label={t('builder.brandColor')} value={value.branding.color ?? '#1d5e52'} data-testid="builder-brand-color" onChange={(e) => set('branding', { ...value.branding, color: e.target.value })} />
          <Input type="url" dir="ltr" label={t('builder.brandLogo')} value={value.branding.logo_url ?? ''} disabled={!whiteLabel} data-testid="builder-brand-logo" onChange={(e) => set('branding', { ...value.branding, logo_url: e.target.value })} />
          <Input type="url" dir="ltr" label={t('builder.brandProjectLogo')} value={value.branding.project_logo_url ?? ''} data-testid="builder-brand-project-logo" onChange={(e) => set('branding', { ...value.branding, project_logo_url: e.target.value })} />
        </div>
        <Input label={t('builder.brandFooter')} value={value.branding.footer ?? ''} maxLength={200} data-testid="builder-brand-footer" onChange={(e) => set('branding', { ...value.branding, footer: e.target.value })} />
        <Check checked={!!value.branding.hide_mohtam} disabled={!whiteLabel} label={t('builder.hideMohtam')} testId="builder-hide-mohtam" onChange={(v) => set('branding', { ...value.branding, hide_mohtam: v })} />
        {!whiteLabel && <p className="t-caption" data-testid="builder-whitelabel-locked">{t('library.planLocked')}</p>}
      </fieldset>

      <fieldset className="space-y-1" data-testid="builder-options">
        <legend className="t-small mb-1 font-medium">{t('builder.options')}</legend>
        <Check checked={!!value.options.expose_team} label={t('builder.exposeTeam')} testId="builder-expose-team" onChange={(v) => set('options', { ...value.options, expose_team: v })} />
        <Check checked={!!value.options.show_unit_prices} label={t('builder.showUnitPrices')} testId="builder-show-prices" onChange={(v) => set('options', { ...value.options, show_unit_prices: v })} />
        <Check checked={!!value.options.show_owner} label={t('builder.showOwner')} testId="builder-show-owner" onChange={(v) => set('options', { ...value.options, show_owner: v })} />
      </fieldset>
    </div>
  )
}
