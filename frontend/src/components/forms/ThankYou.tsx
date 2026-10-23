import { useEffect } from 'react'
import { CalendarCheck, CheckCircle2, Download, ExternalLink, MessageCircle, Phone } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { FormDesign, ThankAction } from '@/features/hub'
import { designVars } from '@/lib/formDesign'

const ICON = { whatsapp: MessageCircle, call: Phone, appointment: CalendarCheck, project: ExternalLink, brochure: Download }

/** Where each action goes: WhatsApp and the phone from a number, the rest from an https address the server already checked. */
export function actionHref(a: ThankAction): string | null {
  const digits = a.value.replace(/[\s\-().]/g, '').replace(/^\+/, '')

  switch (a.type) {
    case 'whatsapp': return /^\d{7,15}$/.test(digits) ? `https://wa.me/${digits}` : null
    case 'call': return /^\d{7,15}$/.test(digits) ? `tel:+${digits}` : null
    default: return /^https:\/\//i.test(a.value) ? a.value : null
  }
}

/** After the lead is kept: the thanks, whatever the workspace offers next, and — if it asked for one — a redirect after a delay. */
export function ThankYou({ title, message, actions, redirectUrl, redirectDelay, design, preview = false }: {
  title: string | null
  message: string | null
  actions: ThankAction[]
  redirectUrl: string | null
  redirectDelay: number
  design: FormDesign
  preview?: boolean
}) {
  const { t } = useTranslation()

  useEffect(() => {
    if (preview || !redirectUrl || !/^https:\/\//i.test(redirectUrl)) return
    const timer = window.setTimeout(() => window.location.assign(redirectUrl), Math.max(0, redirectDelay) * 1000)

    return () => window.clearTimeout(timer)
  }, [preview, redirectUrl, redirectDelay])

  return (
    <section className="@container py-8 text-center text-[var(--f-ink)]" style={designVars(design)} data-testid="landing-thanks" aria-live="polite">
      <CheckCircle2 className="mx-auto size-14 text-[var(--f-primary)]" aria-hidden />
      <h1 className="mt-4 text-[1.5rem] font-extrabold">{title || t('hub.public.thanksTitle')}</h1>
      <p className="mt-2 text-[1.0625rem] text-[var(--f-muted)]">{message || t('hub.public.thanksBody')}</p>
      {actions.length > 0 && (
        <ul className="mx-auto mt-5 flex max-w-sm flex-col gap-2" data-testid="thanks-actions">
          {actions.map((a, i) => {
            const href = actionHref(a)
            const Icon = ICON[a.type]

            return href ? (
              <li key={i}>
                <a href={href} target={a.type === 'call' ? undefined : '_blank'} rel="noopener noreferrer" data-testid={`thanks-action-${a.type}`}
                  className="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-[var(--f-radius)] border border-[var(--f-primary)] px-4 text-[1rem] font-bold text-[var(--f-primary)]">
                  <Icon className="size-5" aria-hidden />{a.label}
                </a>
              </li>
            ) : null
          })}
        </ul>
      )}
      {redirectUrl && !preview && <p className="mt-4 text-[0.875rem] text-[var(--f-muted)]">{t('hub.public.redirecting', { n: redirectDelay })}</p>}
    </section>
  )
}
