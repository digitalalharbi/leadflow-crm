import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BarChart3, Check, Copy, ExternalLink, Plus, QrCode, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Drawer, Input, Select, Skeleton } from '@/components/ui'
import { cn } from '@/lib/cn'
import { ApiError } from '@/lib/api'
import { useApiError } from '@/hooks/useApiError'
import { fetchProjects } from '@/features/leads'
import { fetchProjectProperties } from '@/features/properties'
import { CHANNELS, createLink, fetchAttributionOptions, type Channel, type LinkInput, type TrackingLink } from '@/features/tracking'
import { fetchDestinations, fetchForms, UTM_DEFAULTS, utmSlug, validParamName, withParams } from '@/features/hub'
import { useUi } from '@/stores/ui'
import { CHANNEL_ICON } from './channels'

const STEPS = ['project', 'source', 'destination', 'tracking', 'review'] as const
type Step = (typeof STEPS)[number]

interface Draft {
  project_id: string
  property_id: string
  channel: Channel
  name: string
  campaign_id: string
  ad_id: string
  creative_id: string
  partner_id: string
  mode: 'redirect' | 'landing'
  landing_form_id: string
  destination_url: string
  utm_source: string
  utm_medium: string
  utm_campaign: string
  utm_term: string
  utm_content: string
  custom: { name: string; value: string }[]
}

const BLANK: Draft = {
  project_id: '', property_id: '', channel: 'meta', name: '', campaign_id: '', ad_id: '', creative_id: '', partner_id: '', mode: 'landing', landing_form_id: '', destination_url: '',
  utm_source: UTM_DEFAULTS.meta.source, utm_medium: UTM_DEFAULTS.meta.medium, utm_campaign: '', utm_term: '', utm_content: '', custom: [],
}

/**
 * Five questions, one after the other: which project, where the customer comes from, where they land, how it is measured, is it right.
 * Each step says what it needs before the next one opens; nothing is created until the last. When it is made, the link is already in
 * your hand — to copy, to print as a code, to try, to watch.
 */
export function LinkWizard({ open, onClose, onAnalytics, onQr }: { open: boolean; onClose: () => void; onAnalytics: (link: TrackingLink) => void; onQr: (link: TrackingLink) => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [step, setStep] = useState<Step>('project')
  const [draft, setDraft] = useState<Draft>(BLANK)
  const [made, setMade] = useState<TrackingLink | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [touchedUtm, setTouchedUtm] = useState(false)

  const { data: projects } = useQuery({ queryKey: ['projects-simple'], queryFn: fetchProjects, enabled: open })
  const { data: options } = useQuery({ queryKey: ['attribution-options'], queryFn: fetchAttributionOptions, enabled: open })
  const { data: forms } = useQuery({ queryKey: ['landing-forms'], queryFn: fetchForms, enabled: open })
  const { data: destinations } = useQuery({ queryKey: ['tracking-destinations'], queryFn: fetchDestinations, enabled: open && step === 'tracking' })
  const { data: properties } = useQuery({ queryKey: ['project-properties', draft.project_id], queryFn: () => fetchProjectProperties(draft.project_id), enabled: open && draft.project_id !== '', select: (d) => d.properties })

  useEffect(() => {
    if (!open) return
    setStep('project')
    setDraft(BLANK)
    setMade(null)
    setError(null)
    setTouchedUtm(false)
  }, [open])

  const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => ({ ...d, [key]: value }))
  const project = projects?.find((p) => p.id === draft.project_id)
  const campaign = options?.campaigns.find((c) => c.id === draft.campaign_id)
  const form = forms?.find((f) => f.id === draft.landing_form_id)

  // The UTMs a platform expects, until the person writes their own.
  const pickChannel = (channel: Channel) => setDraft((d) => {
    const defaults = UTM_DEFAULTS[channel]
    return { ...d, channel, ...(touchedUtm || !defaults ? {} : { utm_source: defaults.source, utm_medium: defaults.medium }) }
  })

  // A name worth keeping, written for them — until they write one.
  const suggested = [project?.name, t(`tracking.channels.${draft.channel}`), campaign?.name].filter(Boolean).join(' · ')
  const name = draft.name || suggested
  const campaignSlug = draft.utm_campaign || (campaign ? utmSlug(campaign.name) : '')

  const customErrors = draft.custom.map((c) => (c.name !== '' && !validParamName(c.name)) || (c.name === '' && c.value !== ''))

  const problem: Record<Step, string | null> = {
    project: draft.project_id === '' ? 'project' : null,
    source: name.trim() === '' ? 'name' : null,
    destination: draft.mode === 'landing' ? (draft.landing_form_id === '' ? 'form' : null) : (!/^(https?:\/\/|tel:|mailto:)/i.test(draft.destination_url) ? 'url' : null),
    tracking: customErrors.some(Boolean) ? 'custom' : null,
    review: null,
  }

  const input = (): LinkInput => ({
    name: name.trim(),
    kind: draft.channel === 'qr' ? 'qr' : 'link',
    channel: draft.channel,
    mode: draft.mode,
    project_id: draft.project_id,
    property_id: draft.property_id || null,
    campaign_id: draft.campaign_id || null,
    ad_id: draft.ad_id || null,
    creative_id: draft.creative_id || null,
    partner_id: draft.partner_id || null,
    landing_form_id: draft.mode === 'landing' ? draft.landing_form_id : null,
    destination_url: draft.mode === 'redirect' ? draft.destination_url : null,
    utm_source: draft.utm_source || null,
    utm_medium: draft.utm_medium || null,
    utm_campaign: campaignSlug || null,
    utm_term: draft.utm_term || null,
    utm_content: draft.utm_content || null,
    custom_params: Object.fromEntries(draft.custom.filter((c) => c.name && c.value).map((c) => [c.name, c.value])),
    is_active: true,
  })

  const create = useMutation({
    mutationFn: () => createLink(input()),
    onSuccess: ({ link }) => {
      void queryClient.invalidateQueries({ queryKey: ['tracking-links'] })
      void queryClient.invalidateQueries({ queryKey: ['project-acquisition'] })
      setMade(link)
    },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.errors) setError(Object.values(caught.errors)[0]?.[0] ?? t('errors.validation'))
      else toast(translateError(caught), 'error')
    },
  })

  const copy = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text)
      toast(t('tracking.copied'), 'success')
    } catch {
      toast(text, 'info')
    }
  }

  const index = STEPS.indexOf(step)
  const next = () => { if (!problem[step]) setStep(STEPS[index + 1]) }

  const landsOn = useMemo(() => {
    const base = draft.mode === 'landing' ? `${window.location.origin}/l/…` : draft.destination_url
    if (!base) return ''

    return withParams(base, { utm_source: draft.utm_source, utm_medium: draft.utm_medium, utm_campaign: campaignSlug, utm_term: draft.utm_term, utm_content: draft.utm_content, ...Object.fromEntries(draft.custom.filter((c) => c.name && c.value).map((c) => [c.name, c.value])) })
  }, [draft, campaignSlug])

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={made ? t('hub.wizard.madeTitle') : t('hub.wizard.title')}
      testId="link-wizard"
      size="lg"
      footer={made ? <Button onClick={onClose} data-testid="wizard-done">{t('hub.wizard.done')}</Button> : (
        <>
          {index > 0 && <Button variant="ghost" onClick={() => setStep(STEPS[index - 1])} data-testid="wizard-back">{t('hub.wizard.back')}</Button>}
          {step !== 'review'
            ? <Button onClick={next} disabled={problem[step] !== null} data-testid="wizard-next">{t('hub.wizard.next')}</Button>
            : <Button onClick={() => create.mutate()} loading={create.isPending} data-testid="wizard-create">{t('hub.wizard.create')}</Button>}
        </>
      )}
    >
      {made ? (
        <div className="space-y-4" data-testid="wizard-made">
          <div className="flex items-center gap-2 text-[var(--color-success)]"><Check className="size-5" aria-hidden /><p className="font-bold">{t('hub.wizard.madeBody')}</p></div>
          <code className="block break-all rounded-lg bg-[var(--color-surface-muted)] px-3 py-2 text-[0.9375rem]" dir="ltr" data-testid="wizard-url">{made.url}</code>
          <div className="grid grid-cols-2 gap-2">
            <Button variant="secondary" icon={<Copy className="size-4" />} onClick={() => void copy(made.url)} data-testid="wizard-copy">{t('hub.wizard.copy')}</Button>
            <Button variant="secondary" icon={<QrCode className="size-4" />} onClick={() => onQr(made)} data-testid="wizard-qr">{t('hub.wizard.qr')}</Button>
            <Button variant="secondary" icon={<ExternalLink className="size-4" />} onClick={() => window.open(made.landing_url ?? made.url, '_blank', 'noopener,noreferrer')} data-testid="wizard-preview">{t('hub.wizard.preview')}</Button>
            <Button variant="secondary" icon={<ExternalLink className="size-4" />} onClick={() => window.open(made.url, '_blank', 'noopener,noreferrer')} data-testid="wizard-test">{t('hub.wizard.test')}</Button>
            <Button className="col-span-2" icon={<BarChart3 className="size-4" />} onClick={() => onAnalytics(made)} data-testid="wizard-analytics">{t('hub.wizard.analytics')}</Button>
          </div>
          <p className="t-caption text-[var(--color-ink-subtle)]">{t('hub.wizard.testHint')}</p>
        </div>
      ) : (
        <div className="space-y-5">
          <ol className="flex items-center gap-1.5" aria-label={t('hub.wizard.steps')} data-testid="wizard-steps">
            {STEPS.map((key, i) => (
              <li key={key} data-state={i < index ? 'done' : i === index ? 'current' : 'todo'} aria-current={i === index ? 'step' : undefined}
                className={cn('flex-1 rounded-full py-1 text-center text-[0.8125rem] font-bold', i === index ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : i < index ? 'bg-[var(--brand-soft)] text-[var(--brand-text)]' : 'bg-[var(--color-surface-muted)] text-[var(--color-ink-subtle)]')}>
                {i + 1}<span className="sr-only sm:not-sr-only sm:ms-1">{t(`hub.wizard.step.${key}`)}</span>
              </li>
            ))}
          </ol>

          {step === 'project' && (
            <section className="space-y-4" data-testid="wizard-step-project">
              <p className="text-[0.9375rem] text-[var(--color-ink-muted)]">{t('hub.wizard.projectHint')}</p>
              {!projects ? <Skeleton className="h-10" /> : (
                <Select label={t('tracking.project')} value={draft.project_id} onChange={(e) => setDraft((d) => ({ ...d, project_id: e.target.value, property_id: '' }))} data-testid="wizard-project">
                  <option value="">{t('hub.wizard.choose')}</option>
                  {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </Select>
              )}
              {(properties?.length ?? 0) > 0 && (
                <Select label={t('hub.wizard.unit')} hint={t('common.optional')} value={draft.property_id} onChange={(e) => set('property_id', e.target.value)} data-testid="wizard-property">
                  <option value="">—</option>
                  {properties!.map((p) => <option key={p.id} value={p.id}>{p.code}</option>)}
                </Select>
              )}
            </section>
          )}

          {step === 'source' && (
            <section className="space-y-4" data-testid="wizard-step-source">
              <div role="radiogroup" aria-label={t('tracking.channel')} className="grid grid-cols-3 gap-2 sm:grid-cols-4" data-testid="wizard-channels">
                {CHANNELS.map((c) => {
                  const Icon = CHANNEL_ICON[c]
                  return (
                    <button key={c} type="button" role="radio" aria-checked={draft.channel === c} onClick={() => pickChannel(c)} data-testid={`wizard-channel-${c}`}
                      className={cn('pressable flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl border px-1 py-2 text-[0.8125rem] font-semibold', draft.channel === c ? 'border-[var(--brand)] bg-[var(--brand-soft)] text-[var(--brand-text)]' : 'border-[var(--color-border)]')}>
                      <Icon className="size-5" aria-hidden />{t(`tracking.channels.${c}`)}
                    </button>
                  )
                })}
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <Select label={t('hub.wizard.campaign')} hint={t('common.optional')} value={draft.campaign_id} onChange={(e) => set('campaign_id', e.target.value)} data-testid="wizard-campaign">
                  <option value="">—</option>
                  {(options?.campaigns ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </Select>
                <Select label={t('hub.wizard.ad')} hint={t('common.optional')} value={draft.ad_id} onChange={(e) => set('ad_id', e.target.value)} data-testid="wizard-ad">
                  <option value="">—</option>
                  {(options?.ads ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </Select>
                <Select label={t('hub.wizard.creative')} hint={t('common.optional')} value={draft.creative_id} onChange={(e) => set('creative_id', e.target.value)} data-testid="wizard-creative">
                  <option value="">—</option>
                  {(options?.creatives ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </Select>
                <Select label={t('hub.wizard.partner')} hint={t('common.optional')} value={draft.partner_id} onChange={(e) => set('partner_id', e.target.value)} data-testid="wizard-partner">
                  <option value="">—</option>
                  {(options?.partners ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </Select>
              </div>
              <Input label={t('tracking.name')} value={draft.name} placeholder={suggested} onChange={(e) => set('name', e.target.value)} data-testid="wizard-name" />
            </section>
          )}

          {step === 'destination' && (
            <section className="space-y-4" data-testid="wizard-step-destination">
              <div role="radiogroup" aria-label={t('hub.wizard.destination')} className="grid grid-cols-2 gap-2">
                {(['landing', 'redirect'] as const).map((m) => (
                  <button key={m} type="button" role="radio" aria-checked={draft.mode === m} onClick={() => set('mode', m)} data-testid={`wizard-mode-${m}`}
                    className={cn('pressable rounded-xl border p-3 text-start', draft.mode === m ? 'border-[var(--brand)] bg-[var(--brand-soft)]' : 'border-[var(--color-border)]')}>
                    <span className="block font-bold">{t(`hub.wizard.mode.${m}`)}</span>
                    <span className="block text-[0.8125rem] text-[var(--color-ink-muted)]">{t(`hub.wizard.modeHint.${m}`)}</span>
                  </button>
                ))}
              </div>
              {draft.mode === 'landing' ? (
                <div className="space-y-2">
                  <Select label={t('hub.wizard.form')} value={draft.landing_form_id} onChange={(e) => set('landing_form_id', e.target.value)} data-testid="wizard-form">
                    <option value="">{t('hub.wizard.choose')}</option>
                    {(forms ?? []).filter((f) => f.status !== 'archived').map((f) => <option key={f.id} value={f.id}>{f.name}{f.status === 'draft' ? ` (${t('hub.forms.status.draft')})` : ''}</option>)}
                  </Select>
                  {forms?.length === 0 && <p className="t-caption text-[var(--color-ink-subtle)]" data-testid="wizard-no-forms">{t('hub.wizard.noForms')}</p>}
                  {form?.status === 'draft' && <p className="t-caption text-[var(--color-warning)]" data-testid="wizard-draft-form">{t('hub.wizard.draftForm')}</p>}
                </div>
              ) : (
                <Input label={t('tracking.destination')} dir="ltr" placeholder="https://" value={draft.destination_url} onChange={(e) => set('destination_url', e.target.value)} data-testid="wizard-destination-url" />
              )}
            </section>
          )}

          {step === 'tracking' && (
            <section className="space-y-4" data-testid="wizard-step-tracking">
              <div className="grid gap-4 sm:grid-cols-2">
                {(['source', 'medium', 'campaign', 'term', 'content'] as const).map((k) => (
                  <Input key={k} label={`utm_${k}`} dir="ltr" value={k === 'campaign' ? campaignSlug : draft[`utm_${k}` as const]}
                    onChange={(e) => { setTouchedUtm(true); set(`utm_${k}` as 'utm_source', e.target.value) }} data-testid={`wizard-utm-${k}`} />
                ))}
              </div>
              <div className="space-y-2">
                <div className="flex items-center justify-between"><p className="font-semibold">{t('hub.wizard.custom')}</p>
                  <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} disabled={draft.custom.length >= 12} onClick={() => set('custom', [...draft.custom, { name: '', value: '' }])} data-testid="wizard-add-param">{t('hub.wizard.addParam')}</Button>
                </div>
                {draft.custom.map((c, i) => (
                  <div key={i} className="flex items-start gap-2" data-testid={`wizard-param-${i}`}>
                    <Input aria-label={t('hub.wizard.paramName')} dir="ltr" containerClassName="flex-1" error={customErrors[i] ? t('hub.wizard.paramInvalid') : undefined} value={c.name} placeholder="agent_code" onChange={(e) => set('custom', draft.custom.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} />
                    <Input aria-label={t('hub.wizard.paramValue')} dir="ltr" containerClassName="flex-1" value={c.value} onChange={(e) => set('custom', draft.custom.map((x, j) => (j === i ? { ...x, value: e.target.value } : x)))} />
                    <Button size="sm" variant="ghost" aria-label={t('common.delete')} icon={<Trash2 className="size-4" />} onClick={() => set('custom', draft.custom.filter((_, j) => j !== i))} />
                  </div>
                ))}
              </div>
              <div data-testid="wizard-destinations">
                <p className="font-semibold">{t('hub.wizard.sendsTo')}</p>
                <p className="t-caption mb-2 text-[var(--color-ink-subtle)]">{t('hub.wizard.sendsToHint')}</p>
                {!destinations ? <Skeleton className="h-10" /> : destinations.length === 0 ? <p className="t-caption" data-testid="wizard-no-destinations">{t('hub.wizard.noDestinations')}</p> : (
                  <ul className="space-y-1">
                    {destinations.filter((d) => !d.project || d.project.id === draft.project_id).map((d) => (
                      <li key={d.id} className="flex items-center justify-between rounded-lg bg-[var(--color-surface-muted)] px-3 py-1.5 text-[0.9375rem]">
                        <span>{d.name}</span>
                        <span className="t-caption font-semibold">{d.is_enabled ? t(`hub.readiness.${d.readiness as 'ready'}`, { defaultValue: d.readiness }) : t('hub.readiness.disabled')}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </section>
          )}

          {step === 'review' && (
            <section className="space-y-3" data-testid="wizard-step-review">
              <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-[0.9375rem]">
                <dt className="text-[var(--color-ink-subtle)]">{t('tracking.project')}</dt><dd className="font-semibold">{project?.name}</dd>
                <dt className="text-[var(--color-ink-subtle)]">{t('tracking.name')}</dt><dd className="font-semibold" data-testid="review-name">{name}</dd>
                <dt className="text-[var(--color-ink-subtle)]">{t('tracking.channel')}</dt><dd>{t(`tracking.channels.${draft.channel}`)}</dd>
                {campaign && (<><dt className="text-[var(--color-ink-subtle)]">{t('hub.wizard.campaign')}</dt><dd>{campaign.name}</dd></>)}
                <dt className="text-[var(--color-ink-subtle)]">{t('hub.wizard.destination')}</dt>
                <dd>{draft.mode === 'landing' ? `${t('hub.wizard.mode.landing')} · ${form?.name ?? ''}` : t('hub.wizard.mode.redirect')}</dd>
              </dl>
              <div>
                <p className="t-caption text-[var(--color-ink-subtle)]">{t('hub.wizard.landsOn')}</p>
                <code className="mt-1 block break-all rounded-lg bg-[var(--color-surface-muted)] px-3 py-2 text-[0.875rem]" dir="ltr" data-testid="review-url">{landsOn}</code>
              </div>
              {error && <p role="alert" className="text-[0.9375rem] text-[var(--color-status-lost)]" data-testid="wizard-error">{error}</p>}
            </section>
          )}
        </div>
      )}
    </Drawer>
  )
}
