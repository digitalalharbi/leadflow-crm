import { type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { MapPin, MessageCircle, Phone } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { FormDesign, LandingPageConfig, PageCtaKind } from '@/features/hub'
import { cn } from '@/lib/cn'
import { designVars } from '@/lib/formDesign'

/** The message a tap on WhatsApp prepares, always ending in the reference that ties the conversation back to this visit. */
export function whatsappMessage(project: string | null, custom: string | null, ref: string | undefined, fallback: string): string {
  const text = custom || fallback.replace('{{project}}', project ?? '').replace(/\s+/g, ' ').trim()

  return ref ? `${text} — Ref: ${ref}` : text
}

export function whatsappHref(number: string, message: string): string {
  return `https://wa.me/${number.replace(/^\+/, '')}?text=${encodeURIComponent(message)}`
}

export interface PageContent {
  page: LandingPageConfig
  design: FormDesign
  headline: string | null
  description: string | null
  benefits: string[]
  logo: string | null
  image: string | null
  project: string | null
  cta_label: string | null
  ref?: string
}

/**
 * A campaign page: who the project is, what is on offer, and the way to act, all on the first screen of a laptop, with the details
 * below it. One design, three shapes and the project's own colour from a closed list, so no page comes out badly. A phone scrolls
 * normally, with WhatsApp and call within reach when the page offers them. Never sideways.
 */
export function LandingPageView({ content, form, preview = false, onCta }: {
  content: PageContent
  /** The form itself, already wired by whoever renders the page. */
  form: ReactNode
  preview?: boolean
  onCta?: (kind: PageCtaKind, place: 'hero' | 'floating') => void
}) {
  const { t } = useTranslation()
  const { page, design } = content
  const { cta } = page
  const minimal = page.template === 'minimal_lead'
  const showcase = page.template === 'project_showcase'
  const message = whatsappMessage(content.project, cta.whatsapp.message, content.ref, t('hub.page.whatsappMessage'))

  const action = (kind: PageCtaKind, weight: 'primary' | 'secondary', place: 'hero' | 'floating' = 'hero') => {
    if (kind === 'form') return null

    const number = kind === 'whatsapp' ? cta.whatsapp.number : cta.phone.number

    if (!number) return null

    const Icon = kind === 'whatsapp' ? MessageCircle : Phone
    const href = kind === 'whatsapp' ? whatsappHref(number, message) : `tel:${number}`

    return (
      <a
        key={`${place}-${kind}`}
        href={preview ? undefined : href}
        target={kind === 'whatsapp' ? '_blank' : undefined}
        rel="noopener noreferrer"
        onClick={() => onCta?.(kind, place)}
        data-testid={`page-${place}-${kind}`}
        className={cn(
          'inline-flex min-h-12 items-center justify-center gap-2 rounded-[var(--f-radius)] px-5 text-[1rem] font-bold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--f-primary)]',
          weight === 'primary' ? 'bg-[var(--f-primary)] text-white' : 'border border-[var(--f-primary)] bg-white text-[var(--f-primary)]',
        )}
      >
        <Icon className="size-5" aria-hidden />
        {t(kind === 'whatsapp' ? 'hub.page.cta.whatsapp' : 'hub.page.cta.phone')}
      </a>
    )
  }

  const buttons = [
    cta[cta.primary]?.enabled && cta.primary !== 'form' ? action(cta.primary, 'primary') : null,
    ...(['whatsapp', 'phone'] as const).filter((k) => cta[k].enabled && k !== cta.primary).map((k) => action(k, 'secondary')),
  ].filter(Boolean)

  const floating = !preview && (cta.floating_whatsapp || cta.floating_call)

  return (
    <div className={cn('@container overflow-x-hidden bg-[var(--f-canvas,var(--color-canvas))]', !preview && 'min-h-dvh')} style={designVars(design)} data-testid="landing-page" data-template={page.template}><div className="contents text-[var(--f-ink,var(--color-ink))]">
      <main className={cn('mx-auto grid w-full gap-5 px-4 py-4 @2xl:px-6 @4xl:items-center @4xl:gap-10 @4xl:py-6', !preview && '@4xl:min-h-dvh', cta.form.enabled && !minimal ? 'max-w-6xl @4xl:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]' : 'max-w-xl')}>
        <section className="min-w-0 space-y-3 @4xl:space-y-4" data-testid="page-hero">
          <div className="flex items-center gap-3">
            {content.logo && <img src={content.logo} alt={content.project ?? ''} className="max-h-12 max-w-[9rem] object-contain" data-testid="page-logo" />}
            {page.developer_logo_url && <img src={page.developer_logo_url} alt={page.developer_name ?? ''} className="max-h-9 max-w-[6rem] object-contain" data-testid="page-developer-logo" />}
            {!content.logo && content.project && <p className="text-[1.0625rem] font-extrabold" data-testid="page-project">{content.project}</p>}
          </div>

          {!minimal && content.image && (
            // The picture's height is capped so the form and the way to act stay on the first screen.
            <img src={content.image} alt="" fetchPriority="high" className="max-h-44 w-full rounded-[var(--f-radius)] object-cover @4xl:max-h-56" data-testid="page-image" />
          )}

          <div>
            {content.logo && content.project && <p className="text-[0.9375rem] font-bold text-[var(--f-primary)]" data-testid="page-project">{content.project}</p>}
            <h1 className="text-[1.625rem] font-extrabold leading-tight @4xl:text-[2.125rem]" data-testid="landing-headline">{content.headline || content.project}</h1>
            {content.description && <p className="mt-1.5 text-[1rem] leading-relaxed text-[var(--f-muted)] line-clamp-3" data-testid="page-description">{content.description}</p>}
            {page.developer_name && <p className="mt-1 text-[0.875rem] text-[var(--f-muted)]">{t('hub.page.byDeveloper', { name: page.developer_name })}</p>}
          </div>

          {!minimal && page.facts.length > 0 && (
            <dl className="flex flex-wrap gap-2" data-testid="page-facts">
              {page.facts.map((fact) => (
                <div key={fact.key} className="min-w-0 rounded-lg border border-[var(--f-border)] bg-white px-3 py-1.5" data-testid={`page-fact-${fact.key}`}>
                  <dt className="text-[0.75rem] text-[var(--f-muted)]">{fact.key === 'location' && <MapPin className="me-1 inline size-3" aria-hidden />}{t(`hub.page.facts.${fact.key}`)}</dt>
                  <dd className="truncate text-[0.9375rem] font-bold">{fact.value}</dd>
                </div>
              ))}
            </dl>
          )}

          {buttons.length > 0 && <div className="flex flex-wrap gap-2" data-testid="page-actions">{buttons}</div>}
        </section>

        {cta.form.enabled && (
          <section id="page-form" aria-label={content.cta_label ?? t('hub.page.cta.form')} className="min-w-0 rounded-[var(--f-radius)] border border-[var(--f-border)] bg-white p-4 shadow-sm @4xl:p-5" data-testid="page-form">
            {form}
          </section>
        )}

        {(showcase && content.benefits.length > 0) && (
          <ul className="grid gap-2 @2xl:grid-cols-2 @4xl:col-span-full" data-testid="page-benefits">
            {content.benefits.map((b) => <li key={b} className="rounded-lg border border-[var(--f-border)] bg-white p-3 text-[0.9375rem]">{b}</li>)}
          </ul>
        )}

        {!preview && (
          <footer className={cn('text-center text-[0.8125rem] text-[var(--f-muted)] @4xl:col-span-full', floating && 'pb-16 lg:pb-0')} data-testid="page-legal">
            <Link to="/privacy" className="underline-offset-2 hover:underline">{t('hub.page.privacy')}</Link>
            <span aria-hidden> · </span>
            <Link to="/terms" className="underline-offset-2 hover:underline">{t('hub.page.terms')}</Link>
          </footer>
        )}
      </main>

      {floating && (
        <div className="fixed inset-x-0 bottom-0 z-40 flex justify-center gap-2 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] lg:inset-x-auto lg:bottom-4 lg:end-4 lg:p-0" data-testid="page-floating">
          {cta.floating_whatsapp && action('whatsapp', 'primary', 'floating')}
          {cta.floating_call && action('phone', cta.floating_whatsapp ? 'secondary' : 'primary', 'floating')}
        </div>
      )}
    </div></div>
  )
}
