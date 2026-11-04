import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Copy, QrCode } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Select, Textarea } from '@/components/ui'
import { addPageLink, checkPageSlug, uploadFormAsset, type LandingPageConfig, type PageCtaKind, type PageFactKey, type PageTemplate } from '@/features/hub'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { PAGE_SECTIONS } from '@/lib/landingSections'

const TEMPLATES: PageTemplate[] = ['real_estate_lead', 'project_showcase', 'minimal_lead']
const FACTS: PageFactKey[] = ['location', 'project_type', 'price_from', 'unit_types', 'delivery']
const CTAS: PageCtaKind[] = ['form', 'whatsapp', 'phone']
const PLATFORMS = ['meta', 'tiktok', 'snapchat', 'google', 'linkedin', 'x', 'other'] as const

/** What makes a form a campaign page: the shape, the facts, the ways to act, its address, and how it travels. */
export function PagePanel({ page, slug, formId, published, onPage, onSlug, onQr }: {
  page: LandingPageConfig
  slug: string
  formId: string
  published: boolean
  onPage: (page: LandingPageConfig) => void
  onSlug: (slug: string) => void
  onQr: (link: { id: string; token: string; name: string }) => void
}) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [problem, setProblem] = useState<string | null>(null)
  const [suggestion, setSuggestion] = useState<string | null>(null)
  const [campaign, setCampaign] = useState({ platform: 'meta', campaign: '', group: '', ad: '', medium: 'paid_social' })

  useEffect(() => {
    if (!slug) { setProblem(null); setSuggestion(null); return }
    const timer = window.setTimeout(() => { void checkPageSlug(slug, formId).then((r) => { setProblem(r.problem); setSuggestion(r.suggestion) }).catch(() => { setProblem(null); setSuggestion(null) }) }, 400)

    return () => window.clearTimeout(timer)
  }, [slug, formId])

  const cta = page.cta
  const setCta = (patch: Partial<LandingPageConfig['cta']>) => onPage({ ...page, cta: { ...cta, ...patch } })
  const fact = (key: PageFactKey) => page.facts.find((f) => f.key === key)
  const setFact = (key: PageFactKey, patch: { value?: string; visible?: boolean }) => {
    const current = fact(key) ?? { key, value: '', visible: true }
    onPage({ ...page, facts: [...page.facts.filter((f) => f.key !== key), { ...current, ...patch }] })
  }

  const address = `${window.location.origin}/l/${slug || '…'}`
  const campaignUrl = () => {
    const q = new URLSearchParams()
    q.set('utm_source', campaign.platform)
    q.set('utm_medium', campaign.medium || 'paid_social')
    if (campaign.campaign) q.set('utm_campaign', campaign.campaign)
    if (campaign.group) q.set('utm_term', campaign.group)
    if (campaign.ad) q.set('utm_content', campaign.ad)

    return `${address}?${q.toString()}`
  }
  const copy = (value: string) => void navigator.clipboard?.writeText(value).then(() => toast(t('hub.page.builder.copied'), 'success'))

  const qr = useMutation({
    mutationFn: () => addPageLink(formId, { name: `${t('hub.page.builder.qrName')} ${campaign.campaign || campaign.platform}`.trim(), channel: 'qr', kind: 'qr', utm_source: campaign.platform, utm_campaign: campaign.campaign || undefined }),
    onSuccess: (r) => onQr(r.link),
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const logo = useMutation({
    mutationFn: uploadFormAsset,
    onSuccess: (r) => onPage({ ...page, developer_logo_url: r.url }),
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <div className="space-y-5" data-testid="page-panel">
      <section className="space-y-2">
        <h3 className="font-semibold">{t('hub.page.builder.address')}</h3>
        <Input label={t('hub.page.builder.slug')} dir="ltr" value={slug} maxLength={64} onChange={(e) => onSlug(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))} hint={problem ? t(`hub.page.builder.slugProblem.${problem}`) : address} data-testid="page-slug" />
        {problem && <p role="alert" className="t-caption text-[var(--color-danger)]" data-testid="page-slug-problem">{t(`hub.page.builder.slugProblem.${problem}`)}</p>}
        {problem && suggestion && <button type="button" dir="ltr" className="t-caption text-[var(--brand)] underline" data-testid="page-slug-suggestion" onClick={() => onSlug(suggestion)}>{suggestion}</button>}
      </section>

      <section className="space-y-2">
        <Select label={t('hub.page.builder.template')} value={page.template} onChange={(e) => onPage({ ...page, template: e.target.value as PageTemplate })} data-testid="page-template">
          {TEMPLATES.map((k) => <option key={k} value={k}>{t(`hub.page.templates.${k}`)}</option>)}
        </Select>
        <Input label={t('hub.page.builder.developer')} value={page.developer_name ?? ''} onChange={(e) => onPage({ ...page, developer_name: e.target.value || null })} />
        <label className="block text-[0.875rem]">{t('hub.page.builder.developerLogo')}
          <input type="file" accept="image/png,image/webp,image/svg+xml" className="mt-1 block w-full text-[0.875rem]" onChange={(e) => { const f = e.target.files?.[0]; if (f) logo.mutate(f) }} />
        </label>
      </section>

      <section className="space-y-2" data-testid="page-facts-editor">
        <h3 className="font-semibold">{t('hub.page.builder.facts')}</h3>
        {FACTS.map((key) => (
          <div key={key} className="flex items-end gap-2">
            <Input className="min-w-0 flex-1" label={t(`hub.page.facts.${key}`)} value={fact(key)?.value ?? ''} maxLength={120} onChange={(e) => setFact(key, { value: e.target.value })} data-testid={`page-fact-input-${key}`} />
            <label className="flex min-h-11 items-center gap-1.5 text-[0.8125rem]"><input type="checkbox" className="size-4" checked={fact(key)?.visible ?? true} onChange={(e) => setFact(key, { visible: e.target.checked })} />{t('hub.page.builder.show')}</label>
          </div>
        ))}
      </section>

      <section className="space-y-2" data-testid="page-cta-editor">
        <h3 className="font-semibold">{t('hub.page.builder.ctas')}</h3>
        <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={cta.form.enabled} onChange={(e) => setCta({ form: { enabled: e.target.checked } })} data-testid="page-cta-form" />{t('hub.page.cta.form')}</label>
        <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={cta.whatsapp.enabled} onChange={(e) => setCta({ whatsapp: { ...cta.whatsapp, enabled: e.target.checked } })} data-testid="page-cta-whatsapp" />{t('hub.page.cta.whatsapp')}</label>
        {cta.whatsapp.enabled && (
          <div className="space-y-2 ps-7">
            <Input label={t('hub.page.builder.number')} dir="ltr" placeholder="+9665XXXXXXXX" value={cta.whatsapp.number ?? ''} onChange={(e) => setCta({ whatsapp: { ...cta.whatsapp, number: e.target.value } })} data-testid="page-whatsapp-number" />
            <Textarea label={t('hub.page.builder.message')} hint={t('hub.page.builder.messageHint')} rows={2} value={cta.whatsapp.message ?? ''} onChange={(e) => setCta({ whatsapp: { ...cta.whatsapp, message: e.target.value } })} />
            <Select label={t('hub.page.builder.whatsappMode')} value={cta.whatsapp.mode ?? 'inbound'} onChange={(e) => setCta({ whatsapp: { ...cta.whatsapp, mode: e.target.value as 'track' | 'inbound' } })} data-testid="page-whatsapp-mode">
              <option value="inbound">{t('hub.page.builder.modes.inbound')}</option>
              <option value="track">{t('hub.page.builder.modes.track')}</option>
            </Select>
            <label className="flex items-center gap-2 text-[0.875rem]"><input type="checkbox" className="size-4" checked={cta.floating_whatsapp} onChange={(e) => setCta({ floating_whatsapp: e.target.checked })} data-testid="page-floating-whatsapp" />{t('hub.page.builder.floating')}</label>
          </div>
        )}
        <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={cta.phone.enabled} onChange={(e) => setCta({ phone: { ...cta.phone, enabled: e.target.checked } })} data-testid="page-cta-phone" />{t('hub.page.cta.phone')}</label>
        {cta.phone.enabled && (
          <div className="space-y-2 ps-7">
            <Input label={t('hub.page.builder.number')} dir="ltr" placeholder="+9665XXXXXXXX" value={cta.phone.number ?? ''} onChange={(e) => setCta({ phone: { ...cta.phone, number: e.target.value } })} data-testid="page-phone-number" />
            <label className="flex items-center gap-2 text-[0.875rem]"><input type="checkbox" className="size-4" checked={cta.floating_call} onChange={(e) => setCta({ floating_call: e.target.checked })} data-testid="page-floating-call" />{t('hub.page.builder.floating')}</label>
          </div>
        )}
        <Select label={t('hub.page.builder.primary')} value={cta.primary} onChange={(e) => setCta({ primary: e.target.value as PageCtaKind })} data-testid="page-cta-primary">
          {CTAS.filter((k) => cta[k].enabled).map((k) => <option key={k} value={k}>{t(`hub.page.cta.${k}`)}</option>)}
        </Select>
      </section>

      <section className="space-y-2">
        <h3 className="font-semibold">{t('hub.page.builder.behaviour')}</h3>
        <fieldset className="space-y-2" data-testid="page-sections">
          <legend className="text-[0.9375rem] font-semibold">{t('hub.page.sections.title')}</legend>
          {(page.sections ?? PAGE_SECTIONS.map((key) => ({ key, visible: true }))).map((row, index, rows) => (
            <div key={row.key} className="flex items-center gap-2">
              <label className="flex min-h-10 flex-1 items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={row.visible} data-testid={`page-section-${row.key}`} onChange={(e) => onPage({ ...page, sections: rows.map((r) => (r.key === row.key ? { ...r, visible: e.target.checked } : r)) })} />{t(`hub.page.sections.${row.key}`)}</label>
              <Button size="sm" variant="ghost" aria-label={t('common.up')} disabled={index === 0} onClick={() => { const next = [...rows]; [next[index - 1], next[index]] = [next[index], next[index - 1]]; onPage({ ...page, sections: next }) }}>↑</Button>
              <Button size="sm" variant="ghost" aria-label={t('common.down')} disabled={index === rows.length - 1} onClick={() => { const next = [...rows]; [next[index + 1], next[index]] = [next[index], next[index + 1]]; onPage({ ...page, sections: next }) }}>↓</Button>
            </div>
          ))}
          <Input label={t('hub.page.sections.location')} value={page.location_note ?? ''} onChange={(e) => onPage({ ...page, location_note: e.target.value || null })} data-testid="page-location-note" />
          <Input label={t('hub.page.sections.financing')} value={page.financing_note ?? ''} onChange={(e) => onPage({ ...page, financing_note: e.target.value || null })} data-testid="page-financing-note" />
          <Textarea label={t('hub.page.sections.gallery')} hint={t('hub.page.sections.galleryHint')} dir="ltr" value={(page.gallery ?? []).join('\n')} onChange={(e) => onPage({ ...page, gallery: e.target.value.split('\n').map((u) => u.trim()).filter(Boolean).slice(0, 6) })} data-testid="page-gallery-urls" />
        </fieldset>
        <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={page.auto_steps} onChange={(e) => onPage({ ...page, auto_steps: e.target.checked })} data-testid="page-auto-steps" />{t('hub.page.builder.autoSteps')}</label>
        <Input label={t('hub.page.builder.tags')} hint={t('hub.page.builder.tagsHint')} value={(page.tags ?? []).join('، ')} onChange={(e) => onPage({ ...page, tags: e.target.value.split(/[,،]/).map((v) => v.trimStart()).slice(0, 5) })} data-testid="page-tags" />
      </section>

      <section className="space-y-2" data-testid="page-seo-editor">
        <h3 className="font-semibold">{t('hub.page.builder.seo')}</h3>
        <Input label={t('hub.page.builder.seoTitle')} maxLength={70} value={page.seo.title ?? ''} onChange={(e) => onPage({ ...page, seo: { ...page.seo, title: e.target.value || null } })} />
        <Textarea label={t('hub.page.builder.seoDescription')} rows={2} maxLength={200} value={page.seo.description ?? ''} onChange={(e) => onPage({ ...page, seo: { ...page.seo, description: e.target.value || null } })} />
        <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={page.seo.index} onChange={(e) => onPage({ ...page, seo: { ...page.seo, index: e.target.checked } })} data-testid="page-index" />{t('hub.page.builder.index')}</label>
      </section>

      <section className="space-y-2" data-testid="page-mirror-editor">
        <h3 className="font-semibold">{t('hub.page.builder.mirror')}</h3>
        <p className="t-caption">{t('hub.page.builder.mirrorHint')}</p>
        <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={page.mirror?.enabled ?? false} onChange={(e) => onPage({ ...page, mirror: { spreadsheet_id: page.mirror?.spreadsheet_id ?? null, tab: page.mirror?.tab ?? null, enabled: e.target.checked } })} data-testid="page-mirror" />{t('hub.page.builder.mirrorOn')}</label>
        {page.mirror?.enabled && <Input label={t('hub.page.builder.spreadsheet')} dir="ltr" value={page.mirror.spreadsheet_id ?? ''} onChange={(e) => onPage({ ...page, mirror: { ...page.mirror!, spreadsheet_id: e.target.value } })} data-testid="page-mirror-id" />}
      </section>

      <section className="space-y-2" data-testid="page-campaign-link">
        <h3 className="font-semibold">{t('hub.page.builder.campaignLink')}</h3>
        <Select label={t('hub.page.builder.platform')} value={campaign.platform} onChange={(e) => setCampaign({ ...campaign, platform: e.target.value })} data-testid="campaign-platform">
          {PLATFORMS.map((k) => <option key={k} value={k}>{t(`platforms.${k}`, { defaultValue: k })}</option>)}
        </Select>
        <Input label={t('hub.page.builder.campaign')} dir="ltr" value={campaign.campaign} onChange={(e) => setCampaign({ ...campaign, campaign: e.target.value })} data-testid="campaign-name" />
        <Input label={t('hub.page.builder.adGroup')} dir="ltr" value={campaign.group} onChange={(e) => setCampaign({ ...campaign, group: e.target.value })} />
        <Input label={t('hub.page.builder.ad')} dir="ltr" value={campaign.ad} onChange={(e) => setCampaign({ ...campaign, ad: e.target.value })} />
        <Input label={t('hub.page.builder.medium')} dir="ltr" value={campaign.medium} onChange={(e) => setCampaign({ ...campaign, medium: e.target.value })} />
        <p className="break-all rounded-lg bg-[var(--color-surface-muted)] p-2 text-[0.8125rem]" dir="ltr" data-testid="campaign-url">{campaignUrl()}</p>
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="secondary" icon={<Copy className="size-4" />} onClick={() => copy(campaignUrl())} disabled={!slug} data-testid="campaign-copy">{t('hub.page.builder.copy')}</Button>
          <Button size="sm" variant="outline" icon={<QrCode className="size-4" />} loading={qr.isPending} disabled={!published} onClick={() => qr.mutate()} data-testid="campaign-qr">{t('hub.page.builder.qr')}</Button>
        </div>
      </section>
    </div>
  )
}
