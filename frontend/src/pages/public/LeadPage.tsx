import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { CheckCircle2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Select, Skeleton, Textarea } from '@/components/ui'
import { fetchLanding, isVisible, submitLanding, type FormField, type PublicLanding } from '@/features/hub'
import { ApiError, http } from '@/lib/api'
import { loadTags, pushDataLayer, trackSubmit } from '@/lib/landingTags'

type Answers = Record<string, string | string[] | boolean>

const CLICK_ID_KEYS = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'sccid', 'li_fat_id', 'twclid', 'msclkid']
const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as const

/** What the address carries that the server should hear about: the click ids and the UTMs. */
function fromAddress(search: string) {
  const q = new URLSearchParams(search)
  const click_ids = Object.fromEntries(clickIdPairs(q))
  const utm = Object.fromEntries(UTM_KEYS.flatMap((k) => (q.get(k) ? [[k, q.get(k)!]] : [])))

  return { click_ids, utm, mt_vid: q.get('mt_vid') ?? undefined, mt_sid: q.get('mt_sid') ?? undefined }
}

function clickIdPairs(q: URLSearchParams): [string, string][] {
  return CLICK_ID_KEYS.flatMap((k) => (q.get(k) ? [[k, q.get(k)!] as [string, string]] : []))
}

const PHONE = /^[+\d][\d\s-]{6,18}$/
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/** What is wrong with this answer, as a key the page translates — or nothing. */
export function fieldProblem(field: FormField, value: unknown): 'required' | 'phone' | 'email' | 'number' | null {
  const text = Array.isArray(value) ? value.join('') : String(value ?? '').trim()
  if (field.required && (text === '' || value === false)) return 'required'
  if (text === '') return null
  if (field.type === 'phone' && !PHONE.test(text)) return 'phone'
  if (field.type === 'email' && !EMAIL.test(text)) return 'email'
  if (field.type === 'number' && Number.isNaN(Number(text))) return 'number'

  return null
}

/**
 * `/l/:token` — the page a tracking link opens: what the project is, and the form that turns interest into a lead.
 *
 * No sign-in and no app chrome; right-to-left and one column, because it is opened on a phone from an ad. The view was recorded
 * by the server before this page was sent. The page's own tags (analytics, pixels) wait for the visitor's yes; the server's
 * copies of the events follow the consent rules on their own.
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

  return <Shell><Form token={token} page={data} address={address} /></Shell>
}

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-dvh overflow-x-hidden bg-[var(--color-canvas)] text-[var(--color-ink)]" data-testid="landing">
      <main className="mx-auto w-full max-w-lg px-4 py-6 sm:py-10">{children}</main>
    </div>
  )
}

function Form({ token, page, address }: { token: string; page: PublicLanding; address: ReturnType<typeof fromAddress> }) {
  const { t } = useTranslation()
  const { landing, tracking } = page
  const fields = landing.fields
  const shown = useMemo(() => fields.filter((f) => !f.hidden && f.type !== 'hidden'), [fields])
  const steps = Math.max(1, landing.steps)

  const [answers, setAnswers] = useState<Answers>(() => Object.fromEntries(fields.filter((f) => f.default && !String(f.default).startsWith('@')).map((f) => [f.key, f.default as string])))
  const [step, setStep] = useState(1)
  const [consent, setConsent] = useState(false)
  const [problems, setProblems] = useState<Record<string, string>>({})
  const [consentProblem, setConsentProblem] = useState(false)
  const [sending, setSending] = useState(false)
  const [failed, setFailed] = useState(false)
  const [done, setDone] = useState(false)
  const [website, setWebsite] = useState('')
  // One submission id for as long as the page is open: a retry after a bad network is the same submission, not a second lead.
  const submissionId = useRef(crypto.randomUUID())
  const started = useRef(false)

  // The dataLayer hears about the view the server recorded, under the same event id.
  useEffect(() => { pushDataLayer(tracking.datalayer) }, [tracking.datalayer])
  // The page's own tags load once the visitor has agreed.
  useEffect(() => { if (consent) loadTags(landing.tags) }, [consent, landing.tags])

  const beacon = useCallback((event: 'form_start' | 'form_step', extra: Record<string, unknown> = {}) => {
    // Fire and forget: a lost beacon must never stand between a visitor and the form.
    void http.post(`/track/${token}`, { event, visitor_id: tracking.visitor_id, session_id: tracking.session_id, consent: consent ? 'granted' : undefined, url: window.location.href, ...address.utm, click_ids: address.click_ids, ...extra }).catch(() => undefined)
  }, [token, tracking, consent, address])

  const set = (key: string, value: string | string[] | boolean) => {
    if (!started.current) {
      started.current = true
      beacon('form_start')
      pushDataLayer({ event: 'form_started' })
    }
    setAnswers((a) => ({ ...a, [key]: value }))
    setProblems((p) => { const { [key]: _gone, ...rest } = p; return rest })
  }

  const onStep = shown.filter((f) => (f.step ?? 1) === step && isVisible(f, answers))

  const check = (list: FormField[]) => {
    const found: Record<string, string> = {}
    for (const f of list) {
      const problem = fieldProblem(f, answers[f.key])
      if (problem) found[f.key] = problem
    }
    setProblems(found)

    return Object.keys(found).length === 0
  }

  const next = () => {
    if (!check(onStep)) return
    beacon('form_step', { meta: { step } })
    setStep(step + 1)
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (step < steps) return next()
    if (!check(onStep)) return
    if (landing.consent.required && !consent) { setConsentProblem(true); return }

    setSending(true)
    setFailed(false)
    try {
      await submitLanding(token, {
        submission_id: submissionId.current, answers: visibleOnly(fields, answers), consent, website,
        visitor_id: tracking.visitor_id, session_id: tracking.session_id, url: window.location.href, referrer: document.referrer || undefined,
        click_ids: address.click_ids, ...address.utm,
      })
      if (consent) trackSubmit(landing.tags, submissionId.current)
      setDone(true)
    } catch (caught) {
      // A refusal that names fields is shown beside them; anything else is a network and may be tried again.
      if (caught instanceof ApiError && caught.errors) {
        setProblems(Object.fromEntries(Object.entries(caught.errors).map(([k, v]) => [k.replace(/^answers\./, ''), v[0] ?? 'invalid'])))
        const go = shown.find((f) => caught.errors && (`answers.${f.key}` in caught.errors || f.key in caught.errors))
        if (go) setStep(go.step ?? 1)
      }
      setFailed(true)
    } finally {
      setSending(false)
    }
  }

  if (done) {
    return (
      <section className="py-10 text-center" data-testid="landing-thanks" aria-live="polite">
        <CheckCircle2 className="mx-auto size-14 text-[var(--color-success)]" aria-hidden />
        <h1 className="mt-4 text-[1.5rem] font-extrabold">{landing.thank_you.title || t('hub.public.thanksTitle')}</h1>
        <p className="mt-2 text-[1.0625rem] text-[var(--color-ink-muted)]">{landing.thank_you.message || t('hub.public.thanksBody')}</p>
      </section>
    )
  }

  return (
    <article>
      {landing.logo_url && <img src={landing.logo_url} alt="" className="mx-auto mb-4 h-12 max-w-full object-contain" />}
      {landing.image_url && <img src={landing.image_url} alt="" className="mb-4 aspect-[16/9] w-full rounded-[var(--radius-card)] object-cover" />}
      {landing.project && <p className="t-caption mb-1 font-semibold text-[var(--brand-text)]" data-testid="landing-project">{landing.project.name}</p>}
      <h1 className="text-[1.625rem] font-extrabold leading-snug" data-testid="landing-headline">{landing.headline || landing.project?.name}</h1>
      {landing.description && <p className="mt-2 text-[1.0625rem] text-[var(--color-ink-muted)]">{landing.description}</p>}
      {landing.benefits.length > 0 && (
        <ul className="mt-3 space-y-1.5" data-testid="landing-benefits">
          {landing.benefits.map((b) => <li key={b} className="flex items-start gap-2 text-[1rem]"><CheckCircle2 className="mt-0.5 size-4 shrink-0 text-[var(--color-success)]" aria-hidden />{b}</li>)}
        </ul>
      )}

      <form onSubmit={(e) => void submit(e)} noValidate className="mt-5 space-y-4 rounded-[var(--radius-card)] bg-[var(--color-surface)] p-4 shadow-[var(--shadow-card)]" data-testid="landing-form">
        {steps > 1 && <p className="t-caption text-[var(--color-ink-subtle)]" data-testid="landing-step">{t('hub.public.step', { current: step, total: steps })}</p>}
        {/* A honeypot: a person never sees it, so whatever fills it is not one. */}
        <div aria-hidden className="absolute -start-[9999px] h-0 w-0 overflow-hidden"><label>Website<input tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} /></label></div>

        {onStep.map((f) => <FieldInput key={f.key} field={f} value={answers[f.key]} error={problems[f.key]} onChange={(v) => set(f.key, v)} />)}

        {step === steps && landing.consent.required && (
          <div>
            <label className="flex items-start gap-2 text-[0.9375rem]">
              <input type="checkbox" className="mt-1 size-5 shrink-0" checked={consent} onChange={(e) => { setConsent(e.target.checked); setConsentProblem(false) }} data-testid="landing-consent" />
              <span>
                {landing.consent.text || t('hub.public.consent')}{' '}
                {landing.consent.privacy_url && <a href={landing.consent.privacy_url} target="_blank" rel="noopener noreferrer" className="text-[var(--brand-text)] underline">{t('hub.public.privacy')}</a>}
              </span>
            </label>
            {consentProblem && <p role="alert" className="mt-1 text-[0.875rem] text-[var(--color-status-lost)]" data-testid="landing-consent-error">{t('hub.public.consentRequired')}</p>}
          </div>
        )}

        {failed && <p role="alert" className="text-[0.9375rem] text-[var(--color-status-lost)]" data-testid="landing-failed">{t('hub.public.failed')}</p>}

        <div className="flex gap-2">
          {step > 1 && <Button type="button" variant="secondary" onClick={() => setStep(step - 1)} data-testid="landing-back">{t('hub.public.back')}</Button>}
          <Button type="submit" className="flex-1" loading={sending} data-testid="landing-submit">{step < steps ? t('hub.public.next') : landing.cta_label || t('hub.public.submit')}</Button>
        </div>
      </form>
    </article>
  )
}

/** Only what the visitor could see is sent: an answer to a question that was never shown is not an answer. */
function visibleOnly(fields: FormField[], answers: Answers): Answers {
  return Object.fromEntries(fields.filter((f) => !f.hidden && f.type !== 'hidden' && isVisible(f, answers) && answers[f.key] !== undefined).map((f) => [f.key, answers[f.key]]))
}

export function FieldInput({ field, value, error, onChange }: { field: FormField; value: Answers[string] | undefined; error?: string; onChange: (v: string | string[] | boolean) => void }) {
  const { t } = useTranslation()
  const message = error ? (['required', 'phone', 'email', 'number'].includes(error) ? t(`hub.public.problem.${error as 'required'}`) : t('hub.public.problem.invalid')) : undefined
  const text = typeof value === 'string' ? value : ''
  const common = { label: field.label + (field.required ? ' *' : ''), hint: field.help ?? undefined, error: message, 'data-testid': `field-${field.key}` }

  switch (field.type) {
    case 'textarea':
      return <Textarea {...common} value={text} placeholder={field.placeholder ?? undefined} onChange={(e) => onChange(e.target.value)} />
    case 'select':
      return (
        <Select {...common} value={text} onChange={(e) => onChange(e.target.value)}>
          <option value="">{field.placeholder || t('hub.public.choose')}</option>
          {(field.options ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </Select>
      )
    case 'radio':
      return (
        <fieldset data-testid={`field-${field.key}`}>
          <legend className="mb-1.5 text-[0.9375rem] font-medium">{common.label}</legend>
          <div className="flex flex-wrap gap-2">
            {(field.options ?? []).map((o) => (
              <label key={o.value} className="flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-[var(--color-border)] px-4 text-[0.9375rem] has-[:checked]:border-[var(--brand-text)] has-[:checked]:bg-[var(--brand-soft)]">
                <input type="radio" name={field.key} className="size-4" checked={text === o.value} onChange={() => onChange(o.value)} />{o.label}
              </label>
            ))}
          </div>
          {message && <p role="alert" className="mt-1 text-[0.875rem] text-[var(--color-status-lost)]">{message}</p>}
        </fieldset>
      )
    case 'multi_select': {
      const chosen = Array.isArray(value) ? value : []
      return (
        <fieldset data-testid={`field-${field.key}`}>
          <legend className="mb-1.5 text-[0.9375rem] font-medium">{common.label}</legend>
          <div className="flex flex-wrap gap-2">
            {(field.options ?? []).map((o) => (
              <label key={o.value} className="flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-[var(--color-border)] px-4 text-[0.9375rem] has-[:checked]:border-[var(--brand-text)] has-[:checked]:bg-[var(--brand-soft)]">
                <input type="checkbox" className="size-4" checked={chosen.includes(o.value)} onChange={(e) => onChange(e.target.checked ? [...chosen, o.value] : chosen.filter((v) => v !== o.value))} />{o.label}
              </label>
            ))}
          </div>
          {message && <p role="alert" className="mt-1 text-[0.875rem] text-[var(--color-status-lost)]">{message}</p>}
        </fieldset>
      )
    }
    case 'checkbox':
      return (
        <div data-testid={`field-${field.key}`}>
          <label className="flex items-start gap-2 text-[0.9375rem]"><input type="checkbox" className="mt-1 size-5 shrink-0" checked={value === true} onChange={(e) => onChange(e.target.checked)} />{common.label}</label>
          {message && <p role="alert" className="mt-1 text-[0.875rem] text-[var(--color-status-lost)]">{message}</p>}
        </div>
      )
    default: {
      const htmlType = field.type === 'phone' ? 'tel' : field.type === 'email' ? 'email' : field.type === 'number' ? 'text' : field.type === 'date' ? 'date' : 'text'
      return (
        <Input
          {...common}
          type={htmlType}
          inputMode={field.type === 'number' ? 'decimal' : field.type === 'phone' ? 'tel' : undefined}
          autoComplete={field.type === 'phone' ? 'tel' : field.type === 'email' ? 'email' : field.mapping === 'lead.full_name' ? 'name' : undefined}
          dir={field.type === 'phone' || field.type === 'email' ? 'ltr' : undefined}
          value={text}
          placeholder={field.placeholder ?? undefined}
          onChange={(e) => onChange(e.target.value)}
        />
      )
    }
  }
}
