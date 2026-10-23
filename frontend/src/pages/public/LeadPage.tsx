import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Skeleton } from '@/components/ui'
import { FormRenderer, type FormEvents, type RenderForm } from '@/components/forms/FormRenderer'
import { ThankYou } from '@/components/forms/ThankYou'
import { fetchLanding, submitLanding, type LandingSubmitResult, type PublicLanding } from '@/features/hub'
import { designVars } from '@/lib/formDesign'
import { loadTags, pushDataLayer, trackSubmit } from '@/lib/landingTags'
import type { Answers } from '@/lib/formValidation'

const CLICK_ID_KEYS = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'sccid', 'li_fat_id', 'twclid', 'msclkid']
const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as const

/** What the address carries that the server should hear about: the click ids and the UTMs. */
function fromAddress(search: string) {
  const q = new URLSearchParams(search)
  const click_ids = Object.fromEntries(CLICK_ID_KEYS.flatMap((k) => (q.get(k) ? [[k, q.get(k)!] as [string, string]] : [])))
  const utm = Object.fromEntries(UTM_KEYS.flatMap((k) => (q.get(k) ? [[k, q.get(k)!] as [string, string]] : [])))

  return { click_ids, utm, mt_vid: q.get('mt_vid') ?? undefined, mt_sid: q.get('mt_sid') ?? undefined }
}

/** A beacon that survives the page closing: a fetch that is allowed to outlive it, and never a reason for the visitor to wait. */
function beacon(token: string, body: Record<string, unknown>) {
  try {
    void fetch(`/api/v1/track/${token}`, { method: 'POST', body: JSON.stringify(body), headers: { 'Content-Type': 'text/plain' }, keepalive: true }).catch(() => undefined)
  } catch { /* a lost beacon is lost: the form is what matters */ }
}

const storageKey = (token: string, version: number) => `mt:form:${token}:${version}`

function restore(token: string, version: number): { answers: Answers; step: number } | undefined {
  try {
    const raw = localStorage.getItem(storageKey(token, version))

    return raw ? (JSON.parse(raw) as { answers: Answers; step: number }) : undefined
  } catch {
    return undefined
  }
}

/**
 * `/l/:token` — the page a tracking link opens: what the project is, and the form that turns interest into a lead.
 *
 * No sign-in and no app chrome; right-to-left and one column, because it is opened on a phone from an ad. The view was recorded by the server
 * before this page was sent. The page's own tags (analytics, pixels) wait for the visitor's yes; the server's copies of the events follow the
 * consent rules on their own. The conversion is announced only once the server has kept the lead.
 */
export function LeadPage() {
  const { token = '' } = useParams()
  const { t } = useTranslation()
  const { search } = useLocation()
  const address = useMemo(() => fromAddress(search), [search])
  const { data, isLoading, isError } = useQuery({
    queryKey: ['landing', token],
    queryFn: () => fetchLanding(token, { ...address.click_ids, ...address.utm, ...(address.mt_vid ? { mt_vid: address.mt_vid } : {}), ...(address.mt_sid ? { mt_sid: address.mt_sid } : {}) }),
    retry: false,
    staleTime: Infinity,
  })

  if (isLoading) return <Shell><Skeleton className="h-72" /></Shell>
  if (isError || !data) return <Shell><p className="py-16 text-center text-[1.0625rem] text-[var(--color-ink-muted)]" data-testid="landing-unavailable">{t('hub.public.unavailable')}</p></Shell>

  return <Shell design={data.landing.design}><Landing token={token} page={data} address={address} /></Shell>
}

function Shell({ children, design }: { children: React.ReactNode; design?: PublicLanding['landing']['design'] }) {
  return (
    <div className="min-h-dvh overflow-x-hidden bg-[var(--f-canvas,var(--color-canvas))] text-[var(--color-ink)]" style={design ? designVars(design) : undefined} data-testid="landing">
      <main className="mx-auto w-full max-w-lg px-4 py-6 sm:py-10">{children}</main>
    </div>
  )
}

function Landing({ token, page, address }: { token: string; page: PublicLanding; address: ReturnType<typeof fromAddress> }) {
  const { landing, tracking } = page
  const [done, setDone] = useState<LandingSubmitResult | null>(null)
  const startedAt = useRef<number | null>(null)
  const reached = useRef<{ step: number; field: string | null }>({ step: 1, field: null })
  const initial = useMemo(() => restore(token, landing.version), [token, landing.version])
  // One submission id for as long as the page is open: a retry after a bad network is the same submission, not a second lead.
  const submissionId = useRef(crypto.randomUUID())
  const events = landing.settings.gtm.events

  const form: RenderForm = useMemo(() => ({
    headline: landing.headline, description: landing.description, benefits: landing.benefits, logo_url: landing.logo_url, image_url: landing.image_url, project: landing.project,
    cta_label: landing.cta_label, fields: landing.fields, layout: landing.layout, design: landing.design, settings: landing.settings, consent: landing.consent,
  }), [landing])

  // The dataLayer hears about the view the server recorded, under the same event id.
  useEffect(() => { if (events) pushDataLayer({ ...tracking.datalayer, event: 'form_view' }) }, [events, tracking.datalayer])

  const base = useCallback(() => ({ visitor_id: tracking.visitor_id, session_id: tracking.session_id, url: window.location.href, ...address.utm, click_ids: address.click_ids }), [tracking, address])

  // Where a visitor stopped, if they leave: a step and a field's key, never a word they typed.
  useEffect(() => {
    const leave = () => {
      if (startedAt.current === null || done) return
      beacon(token, { event: 'form_abandon', ...base(), meta: { step: String(reached.current.step), ...(reached.current.field ? { field: reached.current.field } : {}) } })
      startedAt.current = null
    }
    const onHide = () => { if (document.visibilityState === 'hidden') leave() }
    window.addEventListener('pagehide', leave)
    document.addEventListener('visibilitychange', onHide)

    return () => { window.removeEventListener('pagehide', leave); document.removeEventListener('visibilitychange', onHide) }
  }, [token, base, done])

  const hooks: FormEvents = {
    onStart: () => {
      startedAt.current = Date.now()
      beacon(token, { event: 'form_start', ...base() })
      if (events) pushDataLayer({ event: 'form_start', event_id: tracking.view_event_id ?? undefined })
    },
    onStep: (step) => {
      beacon(token, { event: 'form_step', ...base(), meta: { step: String(step) } })
      if (events) pushDataLayer({ event: 'form_step', step })
    },
    onProgress: (state) => { reached.current = state },
    onAttempt: () => { if (events) pushDataLayer({ event: 'form_submit' }) },
    // The visitor's own browser keeps their place, so a phone call or a lock screen does not lose what they typed.
    onChange: ({ answers, step }) => {
      try { localStorage.setItem(storageKey(token, landing.version), JSON.stringify({ answers: stripSecrets(answers), step })) } catch { /* private mode: no resume */ }
    },
  }

  const onSubmit = async (given: Answers, consent: boolean) => {
    const { website, ...answers } = given
    const result = await submitLanding(token, {
      submission_id: submissionId.current, answers, consent, website: typeof website === 'string' ? website : undefined,
      ...base(), referrer: document.referrer || undefined,
    })

    // Only now — the lead is kept — is the conversion announced, under the id the server recorded it with.
    if (events) pushDataLayer({ ...result.datalayer, event: 'form_success', event_id: result.event_id })
    if (consent) {
      // The page's own tags load now — the visitor has said yes — and hear the conversion under the same id the server recorded.
      loadTags(landing.tags)
      trackSubmit(landing.tags, result.event_id)
    }
    try { localStorage.removeItem(storageKey(token, landing.version)) } catch { /* nothing to clear */ }
    startedAt.current = null
    setDone(result)
  }

  if (done) {
    return <ThankYou title={done.thank_you.title ?? landing.thank_you.title} message={done.thank_you.message ?? landing.thank_you.message} actions={done.thank_you.actions} redirectUrl={done.thank_you.redirect_url} redirectDelay={done.thank_you.redirect_delay} design={landing.design} />
  }

  return <FormRenderer form={form} initial={initial} events={hooks} onSubmit={onSubmit} />
}

/** Nothing is stored that the visitor did not type for this form; the honeypot never is. */
function stripSecrets(answers: Answers): Answers {
  const { website: _gone, ...rest } = answers

  return rest
}
