import type { InputHTMLAttributes, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'
import { useId } from 'react'
import { cn } from '@/lib/cn'

/*
 * A field is a recess, so it sits a half-step below the card it is on and
 * carries a faint inner shadow. On focus it comes up to the surface colour and
 * takes the brand ring — the control moving toward you, rather than just
 * changing its border.
 */
const fieldBase = [
  'w-full rounded-[var(--radius-control)] border px-3 text-sm',
  'bg-[var(--color-surface-muted)] shadow-[inset_0_1px_1px_0_color-mix(in_srgb,var(--color-neutral-900)_4%,transparent)]',
  'transition-[background-color,border-color,box-shadow] duration-[var(--duration-fast)]',
  'placeholder:text-[var(--color-ink-subtle)]',
  'focus:bg-[var(--color-surface)] focus:shadow-[0_0_0_3px_var(--brand-ring)] focus:outline-none',
  'disabled:bg-[var(--color-surface-sunken)] disabled:opacity-60',
].join(' ')

function Label({ htmlFor, children, hint }: { htmlFor: string; children: React.ReactNode; hint?: string }) {
  return (
    // `flex-wrap`: a long hint beside a long label squeezed the label onto two
    // lines on a phone and left the pair looking collided. It drops below instead.
    <label htmlFor={htmlFor} className="mb-1.5 flex flex-wrap items-baseline gap-x-2 text-[0.8125rem] font-medium text-[var(--color-ink)]">
      <span>{children}</span>
      {hint && <span className="text-[0.75rem] font-normal text-[var(--color-ink-subtle)]">{hint}</span>}
    </label>
  )
}

function ErrorText({ id, children }: { id: string; children: React.ReactNode }) {
  return (
    <p id={id} role="alert" className="mt-1.5 text-[0.75rem] text-[var(--color-status-lost)]">
      {children}
    </p>
  )
}

interface FieldProps {
  label?: string
  error?: string
  hint?: string
  containerClassName?: string
}

export function Input({
  label,
  error,
  hint,
  containerClassName,
  className,
  id,
  ...props
}: FieldProps & InputHTMLAttributes<HTMLInputElement>) {
  const generated = useId()
  const fieldId = id ?? generated
  const errorId = `${fieldId}-error`

  const isDateLike =
    props.type === 'date' || props.type === 'datetime-local' || props.type === 'time'

  /*
   * An empty date field draws its own format hint, and Chromium lays that hint
   * out as one unshaped left-to-right run per segment — so in Arabic
   * "سنة/شهر/يوم" renders as "موي/رهش/ةنس": letters disconnected and the
   * segments in reverse. Neither `dir` nor `lang` nor `direction: ltr` reaches
   * it, because it lives in the field's shadow tree.
   *
   * So the hint is hidden while the field is empty and ours is drawn over it.
   * A filled field is digits and renders correctly, and the native picker —
   * the only reason to use `type="date"` rather than a text box — is untouched.
   */
  const placeholder = props.placeholder ?? props['aria-label']
  const showDateHint = isDateLike && !props.value && Boolean(placeholder)

  const field = (
    <input
      id={fieldId}
      dir={props.dir ?? (isDateLike ? 'ltr' : undefined)}
      data-empty={showDateHint ? '' : undefined}
      aria-invalid={error ? true : undefined}
      aria-describedby={error ? errorId : undefined}
      className={cn(
        fieldBase,
        'h-10',
        error
          ? 'border-[var(--color-status-lost)]'
          : 'border-[var(--color-border)] focus:border-[var(--brand)]',
        className,
      )}
      {...props}
      placeholder={isDateLike ? undefined : props.placeholder}
    />
  )

  return (
    <div className={containerClassName}>
      {label && (
        <Label htmlFor={fieldId} hint={hint}>
          {label}
        </Label>
      )}
      {showDateHint ? (
        <div className="relative">
          {field}
          <span
            aria-hidden
            className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-sm text-[var(--color-ink-subtle)]"
          >
            {placeholder}
          </span>
        </div>
      ) : (
        field
      )}
      {error && <ErrorText id={errorId}>{error}</ErrorText>}
    </div>
  )
}

export function Textarea({
  label,
  error,
  hint,
  containerClassName,
  className,
  id,
  ...props
}: FieldProps & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  const generated = useId()
  const fieldId = id ?? generated
  const errorId = `${fieldId}-error`

  return (
    <div className={containerClassName}>
      {label && (
        <Label htmlFor={fieldId} hint={hint}>
          {label}
        </Label>
      )}
      <textarea
        id={fieldId}
        rows={3}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        className={cn(
          fieldBase,
          'py-2 leading-relaxed',
          error
            ? 'border-[var(--color-status-lost)]'
            : 'border-[var(--color-border)] focus:border-[var(--brand)]',
          className,
        )}
        {...props}
      />
      {error && <ErrorText id={errorId}>{error}</ErrorText>}
    </div>
  )
}

export function Select({
  label,
  error,
  hint,
  containerClassName,
  className,
  id,
  children,
  ...props
}: FieldProps & SelectHTMLAttributes<HTMLSelectElement>) {
  const generated = useId()
  const fieldId = id ?? generated
  const errorId = `${fieldId}-error`

  return (
    <div className={containerClassName}>
      {label && (
        <Label htmlFor={fieldId} hint={hint}>
          {label}
        </Label>
      )}
      <select
        id={fieldId}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        className={cn(
          fieldBase,
          'h-10 cursor-pointer appearance-none bg-no-repeat pe-9',
          "bg-[url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%2364748b'%3E%3Cpath d='M5.2 7.5 10 12.3l4.8-4.8'  stroke='%2364748b' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E\")]",
          '[background-position:left_0.65rem_center] [background-size:1.1rem] rtl:[background-position:left_0.65rem_center] ltr:[background-position:right_0.65rem_center]',
          error
            ? 'border-[var(--color-status-lost)]'
            : 'border-[var(--color-border)] focus:border-[var(--brand)]',
          className,
        )}
        {...props}
      >
        {children}
      </select>
      {error && <ErrorText id={errorId}>{error}</ErrorText>}
    </div>
  )
}
