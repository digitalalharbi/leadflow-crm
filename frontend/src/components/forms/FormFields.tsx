import { useId, type ReactNode } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { FormField, RepeaterSubfield } from '@/features/hub'
import { cn } from '@/lib/cn'
import { COUNTRY_NAMES, DIAL } from '@/lib/phoneCountries'
import { phoneOf } from '@/lib/formValidation'

/**
 * The fields of a form, drawn once for the public page and for the builder's preview. Every control reads its size, radius and colour from
 * the form's design variables (`--f-*`), is a real labelled control, and keeps its error beside it with `aria-describedby`.
 */
export const INPUT = [
  'w-full h-[var(--f-input-h)] rounded-[var(--f-radius)] border border-[var(--f-border)] bg-white px-3 text-[1rem] text-[var(--f-ink)]',
  'placeholder:text-[#6B7A76] focus:outline-none focus:border-[var(--f-primary)] focus-visible:ring-[3px] focus-visible:ring-[color-mix(in_srgb,var(--f-primary)_35%,transparent)]',
  'aria-[invalid=true]:border-[#B42318] disabled:opacity-60',
].join(' ')

export const PILL = [
  'flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-[var(--f-border)] bg-white px-4 text-[0.9375rem] text-[var(--f-ink)]',
  'has-[:checked]:border-[var(--f-primary)] has-[:checked]:bg-[var(--f-primary)] has-[:checked]:text-[var(--f-primary-ink)]',
  'has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-[color-mix(in_srgb,var(--f-primary)_45%,transparent)]',
].join(' ')

export interface FieldProps {
  field: FormField
  value: unknown
  error?: string
  /** For a repeater: the errors of its own cells, `row.column`. */
  cellErrors?: Record<string, string>
  required: boolean
  defaultCountry: string
  disabled?: boolean
  onChange: (value: unknown) => void
  onFocus?: () => void
}

/** The sentence for a problem: the manager's own words when the field has them, ours otherwise. */
export function useProblem() {
  const { t } = useTranslation()

  return (field: Pick<FormField, 'validation'>, code?: string) =>
    code ? (code !== 'required' && field.validation?.message ? field.validation.message : t(`hub.public.problem.${code}`, { defaultValue: t('hub.public.problem.invalid') })) : undefined
}

function Frame({ field, id, error, required, children, label = true }: { field: FormField; id: string; error?: string; required: boolean; children: ReactNode; label?: boolean }) {
  const message = useProblem()(field, error)

  return (
    <div data-testid={`field-${field.key}`} data-invalid={error ? 'true' : undefined}>
      {label && (
        <label htmlFor={id} className="mb-1.5 block text-[0.9375rem] font-semibold text-[var(--f-ink)]">
          {field.label}{required && <span aria-hidden className="ms-1 text-[#B42318]">*</span>}
        </label>
      )}
      {children}
      {field.help && <p id={`${id}-help`} className="mt-1 text-[0.8125rem] text-[var(--f-muted)]">{field.help}</p>}
      {message && <p id={`${id}-err`} role="alert" className="mt-1.5 text-[0.875rem] font-medium text-[#B42318]" data-testid={`error-${field.key}`}>{message}</p>}
    </div>
  )
}

const describe = (id: string, field: FormField, error?: string) => [field.help ? `${id}-help` : null, error ? `${id}-err` : null].filter(Boolean).join(' ') || undefined

function Group({ field, error, required, children, legendId }: { field: FormField; error?: string; required: boolean; children: ReactNode; legendId: string }) {
  const message = useProblem()(field, error)

  return (
    <fieldset data-testid={`field-${field.key}`} data-invalid={error ? 'true' : undefined} aria-describedby={error ? `${legendId}-err` : undefined} className="min-w-0 border-0 p-0">
      <legend className="mb-1.5 text-[0.9375rem] font-semibold text-[var(--f-ink)]" id={legendId}>{field.label}{required && <span aria-hidden className="ms-1 text-[#B42318]">*</span>}</legend>
      {children}
      {field.help && <p className="mt-1 text-[0.8125rem] text-[var(--f-muted)]">{field.help}</p>}
      {message && <p id={`${legendId}-err`} role="alert" className="mt-1.5 text-[0.875rem] font-medium text-[#B42318]" data-testid={`error-${field.key}`}>{message}</p>}
    </fieldset>
  )
}

export function PhoneField({ field, value, error, required, defaultCountry, disabled, onChange, onFocus }: FieldProps) {
  const { t, i18n } = useTranslation()
  const id = useId()
  const phone = phoneOf(value, defaultCountry)
  const lang = i18n.language.startsWith('ar') ? 'ar' : 'en'

  return (
    <Frame field={field} id={id} error={error} required={required}>
      <div className="flex gap-2" dir="ltr">
        <select aria-label={t('hub.public.country')} value={phone.country} disabled={disabled} onFocus={onFocus} onChange={(e) => onChange({ ...phone, country: e.target.value })}
          className={cn(INPUT, 'w-[7.5rem] shrink-0 px-2')} data-testid={`country-${field.key}`}>
          {Object.keys(DIAL).map((c) => <option key={c} value={c}>{`${c} +${DIAL[c]} — ${COUNTRY_NAMES[c][lang]}`}</option>)}
        </select>
        <input id={id} type="tel" inputMode="tel" autoComplete="tel-national" dir="ltr" value={phone.number} disabled={disabled} placeholder={field.placeholder ?? undefined}
          aria-invalid={error ? true : undefined} aria-required={required || undefined} aria-describedby={describe(id, field, error)} onFocus={onFocus}
          onChange={(e) => onChange({ ...phone, number: e.target.value })} className={cn(INPUT, 'min-w-0 flex-1')} data-testid={`input-${field.key}`} />
      </div>
    </Frame>
  )
}

export function MatrixField({ field, value, error, required, disabled, onChange, onFocus }: FieldProps) {
  const m = field.matrix!
  const legendId = useId()
  const answers = (value && typeof value === 'object' ? value : {}) as Record<string, string | string[]>
  const multiple = m.multiple

  const pick = (row: string, col: string) => {
    if (!multiple) return onChange({ ...answers, [row]: col })
    const current = Array.isArray(answers[row]) ? (answers[row] as string[]) : []
    onChange({ ...answers, [row]: current.includes(col) ? current.filter((c) => c !== col) : [...current, col] })
  }

  const cols = m.columns.length

  return (
    <Group field={field} error={error} required={required} legendId={legendId}>
      {/* A table where there is room, a card for each row where there is not: the page itself never scrolls sideways. */}
      <div className="@container" data-testid={`matrix-${field.key}`}>
        <div className="hidden @md:grid items-end gap-x-2 pb-1 text-center text-[0.8125rem] font-semibold text-[var(--f-muted)]" aria-hidden style={{ gridTemplateColumns: `minmax(7rem,1.4fr) repeat(${cols}, minmax(3.5rem,1fr))` }}>
          <span />
          {m.columns.map((c) => <span key={c.value}>{c.label}</span>)}
        </div>
        <div className="space-y-2">
          {m.rows.map((row) => {
            const chosen = answers[row.value]

            return (
              <div key={row.value} role={multiple ? 'group' : 'radiogroup'} aria-label={row.label} data-testid={`matrix-row-${field.key}-${row.value}`}
                className="rounded-[var(--f-radius)] border border-[var(--f-border)] bg-white p-3 @md:grid @md:items-center @md:gap-x-2 @md:border-0 @md:border-b @md:rounded-none @md:bg-transparent @md:px-0 @md:py-2"
                style={{ gridTemplateColumns: `minmax(7rem,1.4fr) repeat(${cols}, minmax(3.5rem,1fr))` }}>
                <span className="mb-2 block text-[0.9375rem] font-medium text-[var(--f-ink)] @md:mb-0">{row.label}</span>
                <div className="flex flex-wrap gap-2 @md:contents">
                  {m.columns.map((c) => {
                    const checked = multiple ? Array.isArray(chosen) && chosen.includes(c.value) : chosen === c.value

                    return (
                      <label key={c.value} className={cn(PILL, '@md:justify-center @md:border-0 @md:bg-transparent @md:px-0 @md:has-[:checked]:bg-transparent @md:has-[:checked]:text-[var(--f-ink)]')}>
                        <input type={multiple ? 'checkbox' : 'radio'} name={`${field.key}-${row.value}`} checked={checked} disabled={disabled} onFocus={onFocus} onChange={() => pick(row.value, c.value)}
                          className="size-5 accent-[var(--f-primary)]" aria-label={`${row.label}: ${c.label}`} data-testid={`matrix-cell-${field.key}-${row.value}-${c.value}`} />
                        <span className="@md:sr-only">{c.label}</span>
                      </label>
                    )
                  })}
                </div>
              </div>
            )
          })}
        </div>
      </div>
    </Group>
  )
}

function Cell({ sub, value, onChange, defaultCountry, error, id }: { sub: RepeaterSubfield; value: unknown; onChange: (v: unknown) => void; defaultCountry: string; error?: string; id: string }) {
  const { t } = useTranslation()
  const message = error ? t(`hub.public.problem.${error}`, { defaultValue: t('hub.public.problem.invalid') }) : undefined
  const common = { id, 'aria-invalid': error ? true : undefined, 'aria-describedby': error ? `${id}-err` : undefined, placeholder: sub.placeholder ?? undefined }
  const phone = phoneOf(value, defaultCountry)

  return (
    <div className="min-w-0">
      <label htmlFor={id} className="mb-1 block text-[0.875rem] font-semibold text-[var(--f-ink)]">{sub.label}{sub.required && <span aria-hidden className="ms-1 text-[#B42318]">*</span>}</label>
      {sub.type === 'select' ? (
        <select {...common} value={typeof value === 'string' ? value : ''} onChange={(e) => onChange(e.target.value)} className={INPUT}>
          <option value="">{t('hub.public.choose')}</option>
          {(sub.options ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
      ) : sub.type === 'phone' ? (
        <div className="flex gap-2" dir="ltr">
          <select aria-label={t('hub.public.country')} value={phone.country} onChange={(e) => onChange({ ...phone, country: e.target.value })} className={cn(INPUT, 'w-[5.5rem] shrink-0 px-1')}>
            {Object.keys(DIAL).map((c) => <option key={c} value={c}>{`${c} +${DIAL[c]}`}</option>)}
          </select>
          <input {...common} type="tel" inputMode="tel" dir="ltr" value={phone.number} onChange={(e) => onChange({ ...phone, number: e.target.value })} className={cn(INPUT, 'min-w-0 flex-1')} />
        </div>
      ) : sub.type === 'textarea' ? (
        <textarea {...common} value={typeof value === 'string' ? value : ''} onChange={(e) => onChange(e.target.value)} rows={2} className={cn(INPUT, 'h-auto py-2')} />
      ) : (
        <input {...common} type={sub.type === 'email' ? 'email' : sub.type === 'date' ? 'date' : sub.type === 'time' ? 'time' : 'text'} inputMode={sub.type === 'number' ? 'decimal' : undefined}
          dir={sub.type === 'email' ? 'ltr' : undefined} value={typeof value === 'string' || typeof value === 'number' ? String(value) : ''} onChange={(e) => onChange(e.target.value)} className={INPUT} />
      )}
      {message && <p id={`${id}-err`} role="alert" className="mt-1 text-[0.8125rem] font-medium text-[#B42318]">{message}</p>}
    </div>
  )
}

export function RepeaterField({ field, value, error, cellErrors = {} as Record<string, string>, required, defaultCountry, disabled, onChange, onFocus }: FieldProps) {
  const { t } = useTranslation()
  const r = field.repeater!
  const legendId = useId()
  const rows = (Array.isArray(value) ? value : []) as Record<string, unknown>[]
  const shown: Record<string, unknown>[] = rows.length > 0 ? rows : Array.from({ length: Math.max(r.default_rows, r.min_rows) }, () => ({}))

  const set = (i: number, key: string, v: unknown) => onChange(shown.map((row, n) => (n === i ? { ...row, [key]: v } : row)))

  return (
    <Group field={field} error={error} required={required} legendId={legendId}>
      <div className="space-y-3" data-testid={`repeater-${field.key}`} onFocus={onFocus}>
        {shown.map((row, i) => (
          <div key={i} className="@container rounded-[var(--f-radius)] border border-[var(--f-border)] bg-white p-3" data-testid={`repeater-row-${field.key}-${i}`} role="group" aria-label={`${field.label} ${i + 1}`}>
            <div className="grid gap-3 @md:grid-cols-[repeat(auto-fit,minmax(10rem,1fr))]">
              {r.subfields.map((s) => <Cell key={s.key} id={`${legendId}-${i}-${s.key}`} sub={s} value={row[s.key]} defaultCountry={defaultCountry} error={cellErrors[`${i}.${s.key}`]} onChange={(v) => set(i, s.key, v)} />)}
            </div>
            {shown.length > Math.max(1, r.min_rows) && !disabled && (
              <button type="button" onClick={() => onChange(shown.filter((_, n) => n !== i))} className="mt-2 inline-flex min-h-9 items-center gap-1 text-[0.875rem] font-semibold text-[#B42318]" data-testid={`repeater-remove-${field.key}-${i}`}>
                <Trash2 className="size-4" aria-hidden />{t('hub.public.removeRow')}
              </button>
            )}
          </div>
        ))}
        {shown.length < r.max_rows && !disabled && (
          <button type="button" onClick={() => onChange([...shown, {}])} data-testid={`repeater-add-${field.key}`}
            className="inline-flex min-h-11 items-center gap-1.5 rounded-full border border-dashed border-[var(--f-primary)] px-4 text-[0.9375rem] font-semibold text-[var(--f-primary)]">
            <Plus className="size-4" aria-hidden />{r.add_label || t('hub.public.addRow')}
          </button>
        )}
      </div>
    </Group>
  )
}

/** Any field, by its type. A display element (heading, paragraph, divider) draws and asks nothing. */
export function FieldView(props: FieldProps) {
  const { t } = useTranslation()
  const { field, value, error, required, disabled, onChange, onFocus } = props
  const id = useId()
  const text = typeof value === 'string' || typeof value === 'number' ? String(value) : ''

  switch (field.type) {
    case 'heading': return <h3 className="text-[1.125rem] font-extrabold text-[var(--f-ink)]" data-testid={`field-${field.key}`}>{field.label}</h3>
    case 'paragraph': return <p className="text-[0.9375rem] leading-relaxed text-[var(--f-muted)]" data-testid={`field-${field.key}`}>{field.label}</p>
    case 'divider': return <hr className="border-[var(--f-border)]" data-testid={`field-${field.key}`} />
    case 'phone': return <PhoneField {...props} />
    case 'matrix': return <MatrixField {...props} />
    case 'repeater': return <RepeaterField {...props} />
    case 'checkbox':
    case 'consent':
      return (
        <div data-testid={`field-${field.key}`} data-invalid={error ? 'true' : undefined}>
          <label className="flex items-start gap-3 text-[0.9375rem] text-[var(--f-ink)]">
            <input type="checkbox" className="mt-0.5 size-5 shrink-0 accent-[var(--f-primary)]" checked={value === true} disabled={disabled} onFocus={onFocus} onChange={(e) => onChange(e.target.checked)}
              aria-invalid={error ? true : undefined} aria-required={required || undefined} aria-describedby={error ? `${id}-err` : undefined} data-testid={`input-${field.key}`} />
            <span>{field.label}{required && <span aria-hidden className="ms-1 text-[#B42318]">*</span>}</span>
          </label>
          {error && <p id={`${id}-err`} role="alert" className="mt-1.5 text-[0.875rem] font-medium text-[#B42318]" data-testid={`error-${field.key}`}>{t('hub.public.problem.required')}</p>}
        </div>
      )
    case 'radio':
      return (
        <Group field={field} error={error} required={required} legendId={id}>
          <div className="flex flex-wrap gap-2">
            {(field.options ?? []).map((o) => (
              <label key={o.value} className={PILL}>
                <input type="radio" name={field.key} className="size-4 accent-[var(--f-primary-ink)]" checked={value === o.value} disabled={disabled} onFocus={onFocus} onChange={() => onChange(o.value)} />{o.label}
              </label>
            ))}
          </div>
        </Group>
      )
    case 'multi_select': {
      const chosen = Array.isArray(value) ? (value as string[]) : []

      return (
        <Group field={field} error={error} required={required} legendId={id}>
          <div className="flex flex-wrap gap-2">
            {(field.options ?? []).map((o) => (
              <label key={o.value} className={PILL}>
                <input type="checkbox" className="size-4 accent-[var(--f-primary-ink)]" checked={chosen.includes(o.value)} disabled={disabled} onFocus={onFocus}
                  onChange={(e) => onChange(e.target.checked ? [...chosen, o.value] : chosen.filter((v) => v !== o.value))} />{o.label}
              </label>
            ))}
          </div>
        </Group>
      )
    }
    case 'select':
      return (
        <Frame field={field} id={id} error={error} required={required}>
          <select id={id} value={text} disabled={disabled} onFocus={onFocus} onChange={(e) => onChange(e.target.value)} aria-invalid={error ? true : undefined} aria-required={required || undefined} aria-describedby={describe(id, field, error)}
            className={INPUT} data-testid={`input-${field.key}`}>
            <option value="">{field.placeholder || t('hub.public.choose')}</option>
            {(field.options ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </Frame>
      )
    case 'textarea':
      return (
        <Frame field={field} id={id} error={error} required={required}>
          <textarea id={id} value={text} rows={4} disabled={disabled} onFocus={onFocus} placeholder={field.placeholder ?? undefined} onChange={(e) => onChange(e.target.value)} aria-invalid={error ? true : undefined}
            aria-required={required || undefined} aria-describedby={describe(id, field, error)} className={cn(INPUT, 'h-auto py-2')} data-testid={`input-${field.key}`} />
        </Frame>
      )
    default: {
      const type = field.type === 'email' ? 'email' : field.type === 'date' ? 'date' : field.type === 'time' ? 'time' : 'text'

      return (
        <Frame field={field} id={id} error={error} required={required}>
          <input id={id} type={type} inputMode={field.type === 'number' ? 'decimal' : undefined} dir={field.type === 'email' ? 'ltr' : undefined} value={text} disabled={disabled} onFocus={onFocus}
            autoComplete={field.mapping === 'lead.full_name' ? 'name' : field.type === 'email' ? 'email' : undefined} placeholder={field.placeholder ?? undefined}
            onChange={(e) => onChange(e.target.value)} aria-invalid={error ? true : undefined} aria-required={required || undefined} aria-describedby={describe(id, field, error)} className={INPUT} data-testid={`input-${field.key}`} />
        </Frame>
      )
    }
  }
}
