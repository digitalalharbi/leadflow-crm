import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Loader2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { FormDesign, FormField, FormLayout, LayoutRow, PublicSettings } from '@/features/hub'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { designVars } from '@/lib/formDesign'
import { visibility } from '@/lib/formLogic'
import { validateAnswers, serializeAnswers, type Answers } from '@/lib/formValidation'
import { FormHeader, type HeaderContent } from './FormHeader'
import { FieldView } from './FormFields'

export interface RenderForm extends HeaderContent {
  cta_label: string | null
  fields: FormField[]
  layout: FormLayout
  design: FormDesign
  settings: PublicSettings
  consent: { required: boolean; text: string | null; privacy_url: string | null }
}

export interface FormEvents {
  /** The visitor touched the form for the first time. */
  onStart?: () => void
  /** The visitor finished a step and went on. */
  onStep?: (step: number) => void
  /** The last field the visitor was in, and the step: where they would stop if they left. */
  onProgress?: (state: { step: number; field: string | null }) => void
  onChange?: (state: { answers: Answers; step: number }) => void
  /** The visitor pressed the button and the answers passed the page's own checks. */
  onAttempt?: () => void
}

const COLS: Record<number, string> = { 1: '', 2: '@md:grid-cols-2', 3: '@md:grid-cols-3', 4: '@md:grid-cols-4' }

/**
 * The form itself — header, sections, rows, columns, fields, steps and the button — for the public page and for the builder's live
 * preview. One implementation, so what the manager sees is what the customer gets.
 *
 * Right-to-left, one column on a phone (the grid answers to the form's own width, not the screen's), a button that waits for the
 * server, errors beside their field, and nothing lost when something is wrong. In preview nothing is sent and nothing is recorded.
 */
export function FormRenderer({ form, preview = false, initial, events, onSubmit, bare = false }: {
  form: RenderForm
  preview?: boolean
  /** On a campaign page the page itself says who the project is: the form is only the questions. */
  bare?: boolean
  initial?: { answers: Answers; step: number }
  events?: FormEvents
  /** Resolves when the lead is safely kept; rejects (an ApiError with `errors`) to be shown beside the fields. */
  onSubmit?: (answers: Answers, consent: boolean) => Promise<void>
}) {
  const { t } = useTranslation()
  const { fields, layout, design, settings } = form
  const [answers, setAnswers] = useState<Answers>(initial?.answers ?? {})
  const [step, setStep] = useState(initial?.step ?? 0)
  const [consent, setConsent] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [consentError, setConsentError] = useState(false)
  const [sending, setSending] = useState(false)
  const [failed, setFailed] = useState(false)
  const [website, setWebsite] = useState('')
  const started = useRef(false)
  const stepHeading = useRef<HTMLHeadingElement>(null)
  const byKey = useMemo(() => new Map(fields.map((f) => [f.key, f])), [fields])

  const state = useMemo(() => visibility(fields, layout, answers), [fields, layout, answers])
  const multi = settings.mode === 'multi'
  // A step whose every field is hidden is skipped: nobody is shown an empty page.
  const stepIndexes = useMemo(() => (multi ? layout.sections.flatMap((s, i) => (sectionHasVisible(s, state.visible) ? [i] : [])) : [0]), [multi, layout, state.visible])
  const position = Math.max(0, stepIndexes.indexOf(step) === -1 ? 0 : stepIndexes.indexOf(step))
  const currentIndex = stepIndexes[position] ?? 0
  const last = position >= stepIndexes.length - 1

  useEffect(() => { events?.onChange?.({ answers, step: currentIndex }) }, [answers, currentIndex]) // eslint-disable-line react-hooks/exhaustive-deps

  const touch = useCallback((key: string | null) => {
    if (preview) return
    if (!started.current) { started.current = true; events?.onStart?.() }
    events?.onProgress?.({ step: position + 1, field: key })
  }, [preview, events, position])

  const set = (key: string, value: unknown) => {
    touch(key)
    setAnswers((a) => ({ ...a, [key]: value }))
    setErrors((e) => Object.fromEntries(Object.entries(e).filter(([k]) => k !== key && !k.startsWith(`${key}.`))))
  }

  const sectionKeys = (i: number) => layout.sections[i].rows.flatMap((r) => r.columns.flatMap((c) => c.fields))

  const focusFirstError = (found: Record<string, string>) => {
    const first = fields.find((f) => found[f.key] || Object.keys(found).some((k) => k.startsWith(`${f.key}.`)))
    if (first) requestAnimationFrame(() => document.querySelector<HTMLElement>(`[data-testid="field-${first.key}"] input, [data-testid="field-${first.key}"] select, [data-testid="field-${first.key}"] textarea, [data-testid="input-${first.key}"]`)?.focus())
  }

  const next = () => {
    const found = validateAnswers(fields, layout, answers, settings.default_country, sectionKeys(currentIndex))
    setErrors(found)
    if (Object.keys(found).length > 0) return focusFirstError(found)
    events?.onStep?.(position + 1)
    setStep(stepIndexes[position + 1])
    requestAnimationFrame(() => stepHeading.current?.focus())
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!last) return next()

    const found = validateAnswers(fields, layout, answers, settings.default_country)
    setErrors(found)
    if (Object.keys(found).length > 0) {
      // A refusal on an earlier step takes the visitor back to it.
      const bad = layout.sections.findIndex((_, i) => sectionKeys(i).some((k) => found[k] || Object.keys(found).some((x) => x.startsWith(`${k}.`))))
      if (bad >= 0 && stepIndexes.includes(bad)) setStep(bad)

      return focusFirstError(found)
    }
    if (form.consent.required && !consent) return setConsentError(true)
    if (preview || !onSubmit) return

    setSending(true)
    setFailed(false)
    events?.onAttempt?.()
    try {
      await onSubmit({ ...serializeAnswers(fields, layout, answers), ...(website ? { website } : {}) }, consent)
    } catch (caught) {
      // A refusal that names fields is shown beside them; anything else is the network and may be tried again — with the same answers.
      if (caught instanceof ApiError && caught.errors) {
        const mapped = Object.fromEntries(Object.entries(caught.errors).map(([k, v]) => [k.replace(/^answers\./, ''), Array.isArray(v) ? v[0] : String(v)]))
        setErrors(mapped)
        const bad = layout.sections.findIndex((_, i) => sectionKeys(i).some((k) => mapped[k] || Object.keys(mapped).some((x) => x.startsWith(`${k}.`))))
        if (bad >= 0 && stepIndexes.includes(bad)) setStep(bad)
        focusFirstError(mapped)
      }
      setFailed(true)
    } finally {
      setSending(false)
    }
  }

  const cellErrors = (key: string) => Object.fromEntries(Object.entries(errors).filter(([k]) => k.startsWith(`${key}.`)).map(([k, v]) => [k.slice(key.length + 1), v]))

  const sections = multi ? [layout.sections[currentIndex]] : layout.sections

  return (
    <div className="@container" style={designVars(design)} data-testid="form-root" data-preview={preview || undefined}>
      <div className="space-y-[var(--f-section-gap)] text-[var(--f-ink)]">
        {!bare && <FormHeader content={form} design={design} />}

        <form onSubmit={(e) => void submit(e)} noValidate data-testid="landing-form" aria-label={form.headline ?? undefined}
          className={cn('space-y-[var(--f-section-gap)]', design.style.surface === 'card' ? 'rounded-[var(--f-radius)] bg-[var(--f-surface)] p-4 shadow-[0_1px_2px_rgb(0_0_0/0.06),0_4px_14px_rgb(0_0_0/0.06)]' : '')}>
          {multi && (
            <div data-testid="landing-step" role="group" aria-label={t('hub.public.step', { current: position + 1, total: stepIndexes.length })}>
              <div className="flex items-center justify-between text-[0.8125rem] font-semibold text-[var(--f-muted)]">
                <span>{t('hub.public.step', { current: position + 1, total: stepIndexes.length })}</span>
                <span>{layout.sections[currentIndex].step_title ?? layout.sections[currentIndex].title ?? ''}</span>
              </div>
              <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-[color-mix(in_srgb,var(--f-primary)_14%,white)]" role="progressbar" aria-valuemin={1} aria-valuemax={stepIndexes.length} aria-valuenow={position + 1} data-testid="form-progress">
                <div className="h-full rounded-full bg-[var(--f-primary)] transition-[width]" style={{ width: `${((position + 1) / stepIndexes.length) * 100}%` }} />
              </div>
            </div>
          )}

          {/* A honeypot: a person never sees it, so whatever fills it is not one. */}
          <div aria-hidden className="absolute -start-[9999px] h-0 w-0 overflow-hidden"><label>Website<input tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} /></label></div>

          {sections.map((s) => {
            if (!sectionHasVisible(s, state.visible)) return null
            return (
              <section key={s.id} aria-labelledby={s.title ? `sec-${s.id}` : undefined} className="space-y-[var(--f-gap)]" data-testid={`section-${s.id}`}>
                {s.title && <h2 id={`sec-${s.id}`} ref={multi ? stepHeading : undefined} tabIndex={-1} className="text-[1.125rem] font-extrabold outline-none">{s.title}</h2>}
                {s.description && <p className="text-[0.9375rem] text-[var(--f-muted)]">{s.description}</p>}
                {s.rows.map((r) => renderRow(r))}
              </section>
            )
          })}

          {last && form.consent.required && (
            <div data-invalid={consentError || undefined}>
              <label className="flex items-start gap-3 text-[0.9375rem]">
                <input type="checkbox" className="mt-0.5 size-5 shrink-0 accent-[var(--f-primary)]" checked={consent} onChange={(e) => { setConsent(e.target.checked); setConsentError(false) }}
                  aria-invalid={consentError || undefined} aria-describedby={consentError ? 'landing-consent-error' : undefined} data-testid="landing-consent" />
                <span>
                  {form.consent.text || t('hub.public.consent')}{' '}
                  {form.consent.privacy_url && <a href={form.consent.privacy_url} target="_blank" rel="noopener noreferrer" className="font-semibold text-[var(--f-primary)] underline">{t('hub.public.privacy')}</a>}
                </span>
              </label>
              {consentError && <p id="landing-consent-error" role="alert" className="mt-1.5 text-[0.875rem] font-medium text-[#B42318]" data-testid="landing-consent-error">{t('hub.public.consentRequired')}</p>}
            </div>
          )}

          {failed && <p role="alert" className="text-[0.9375rem] font-medium text-[#B42318]" data-testid="landing-failed">{t('hub.public.failed')}</p>}

          <div className="flex gap-2">
            {position > 0 && <button type="button" onClick={() => { setStep(stepIndexes[position - 1]); requestAnimationFrame(() => stepHeading.current?.focus()) }} data-testid="landing-back"
              className="min-h-[var(--f-input-h)] rounded-[var(--f-radius)] border border-[var(--f-border)] bg-white px-5 text-[1rem] font-semibold">{t('hub.public.back')}</button>}
            <button type="submit" disabled={sending} aria-busy={sending || undefined} data-testid="landing-submit"
              className={cn('inline-flex min-h-[var(--f-input-h)] items-center justify-center gap-2 rounded-[var(--f-radius)] bg-[var(--f-primary)] px-6 text-[1rem] font-bold text-[var(--f-primary-ink)] focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-[color-mix(in_srgb,var(--f-primary)_45%,transparent)] disabled:opacity-70', design.style.button === 'full' ? 'flex-1' : 'min-w-40')}>
              {sending && <Loader2 className="size-4 animate-spin" aria-hidden />}
              {last ? form.cta_label || t('hub.public.submit') : t('hub.public.next')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )

  // A plain function, not a component: a component declared here would be a new type on every keystroke and the field would lose focus.
  function renderRow(row: LayoutRow) {
    const visibleColumns = row.columns.filter((c) => c.fields.some((k) => state.visible.includes(k)))
    if (visibleColumns.length === 0) return null

    return (
      <div key={row.id} className={cn('grid gap-[var(--f-gap)]', COLS[Math.min(4, visibleColumns.length)])} data-testid={`row-${row.id}`}>
        {visibleColumns.map((c) => (
          <div key={c.id} className="min-w-0 space-y-[var(--f-gap)]">
            {c.fields.filter((k) => state.visible.includes(k)).map((k) => {
              const f = byKey.get(k)!

              return <FieldView key={k} field={f} value={answers[k]} error={errors[k]} cellErrors={cellErrors(k)} required={state.required.includes(k)} defaultCountry={settings.default_country} onChange={(v) => set(k, v)} onFocus={() => touch(k)} />
            })}
          </div>
        ))}
      </div>
    )
  }
}

function sectionHasVisible(s: FormLayout['sections'][number], visible: string[]): boolean {
  return s.rows.some((r) => r.columns.some((c) => c.fields.some((k) => visible.includes(k))))
}
