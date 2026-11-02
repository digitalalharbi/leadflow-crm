import { CheckCircle2, MapPin, ShieldCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { FormDesign } from '@/features/hub'
import { cn } from '@/lib/cn'
import { formatNumber } from '@/lib/formatNumber'

export interface HeaderContent {
  headline: string | null
  description: string | null
  benefits: string[]
  logo_url: string | null
  image_url: string | null
  project: { name: string } | null
}

const LOGO = { sm: 'h-8', md: 'h-12', lg: 'h-16' }
const ALIGN = { start: 'justify-start', center: 'justify-center', end: 'justify-end' }

/**
 * The top of a landing page, in five layouts: minimal, centred, split, image cover and a project card. What differs is only where the same
 * parts sit — the logo, the project, the headline, the description, the selling points, the location, a starting price and a line of trust —
 * so changing the layout never loses a word.
 */
export function FormHeader({ content, design }: { content: HeaderContent; design: FormDesign }) {
  const { t, i18n } = useTranslation()
  const h = design.header
  const { logo_url, image_url, project } = content
  const brand = h.background === 'brand' || h.layout === 'cover'
  const soft = h.background === 'soft'

  const logo = h.show_logo && logo_url ? (
    <div className={cn('flex', ALIGN[h.logo_align])} data-testid="form-logo-wrap">
      <img src={logo_url} alt={project?.name ?? ''} className={cn(LOGO[h.logo_size], 'max-w-[60%] object-contain')} data-testid="form-logo" />
    </div>
  ) : null

  const name = h.show_project_name && project ? <p className="text-[0.875rem] font-bold text-[var(--f-primary)]" data-testid="landing-project">{project.name}</p> : null

  const facts = (h.location || h.price_from) ? (
    <ul className="flex flex-wrap gap-x-4 gap-y-1 text-[0.875rem] font-medium" data-testid="form-facts">
      {h.location && <li className="inline-flex items-center gap-1"><MapPin className="size-4" aria-hidden />{h.location}</li>}
      {h.price_from !== null && <li>{t('hub.public.priceFrom', { price: formatNumber(h.price_from, i18n.language) })}</li>}
    </ul>
  ) : null

  const benefits = content.benefits.length > 0 ? (
    <ul className="space-y-1.5" data-testid="landing-benefits">
      {content.benefits.map((b) => <li key={b} className="flex items-start gap-2 text-[0.9375rem]"><CheckCircle2 className="mt-0.5 size-4 shrink-0 text-[var(--f-primary)]" aria-hidden />{b}</li>)}
    </ul>
  ) : null

  const trust = h.trust_note ? <p className="inline-flex items-center gap-1.5 text-[0.8125rem] text-[var(--f-muted)]" data-testid="form-trust"><ShieldCheck className="size-4" aria-hidden />{h.trust_note}</p> : null

  const text = (
    <div className="space-y-2">
      {name}
      <h1 className="text-[1.625rem] font-extrabold leading-snug" data-testid="landing-headline">{content.headline || project?.name}</h1>
      {content.description && <p className="text-[1rem] opacity-90">{content.description}</p>}
      {facts}
      {benefits}
      {trust}
    </div>
  )

  if (h.layout === 'cover') {
    return (
      <header className="relative overflow-hidden rounded-[var(--f-radius)] text-[var(--f-primary-ink)]" data-testid="form-header" data-layout="cover">
        {image_url ? <img src={image_url} alt="" className="absolute inset-0 size-full object-cover" /> : null}
        <div className="absolute inset-0" style={{ background: image_url ? 'linear-gradient(to top, rgb(0 0 0 / 0.72), rgb(0 0 0 / 0.25))' : 'var(--f-primary)' }} />
        <div className="relative space-y-3 p-5 pt-24 text-white [&_*]:text-inherit">{logo}{text}</div>
      </header>
    )
  }

  if (h.layout === 'split') {
    return (
      <header className="@container" data-testid="form-header" data-layout="split">
        <div className="grid gap-4 @md:grid-cols-2 @md:items-center">
          <div className="space-y-3">{logo}{text}</div>
          {image_url && <img src={image_url} alt="" className="aspect-[4/3] w-full rounded-[var(--f-radius)] object-cover" />}
        </div>
      </header>
    )
  }

  if (h.layout === 'project_card') {
    return (
      <header data-testid="form-header" data-layout="project_card" className="overflow-hidden rounded-[var(--f-radius)] border border-[var(--f-border)] bg-white">
        {image_url && <img src={image_url} alt="" className="aspect-[16/9] w-full object-cover" />}
        <div className="space-y-3 p-4">{logo}{text}</div>
      </header>
    )
  }

  const minimal = h.layout === 'minimal'

  return (
    <header data-testid="form-header" data-layout={h.layout} className={cn('space-y-3 rounded-[var(--f-radius)]', soft && 'bg-[color-mix(in_srgb,var(--f-primary)_8%,white)] p-4', brand && !soft && 'bg-[var(--f-primary)] p-4 text-[var(--f-primary-ink)]', !minimal && 'text-center [&_ul]:justify-center')}>
      {image_url && h.background === 'image' && <img src={image_url} alt="" className="aspect-[16/9] w-full rounded-[var(--f-radius)] object-cover" />}
      {logo}
      <div className={cn(!minimal && 'mx-auto max-w-md')}>{text}</div>
    </header>
  )
}
