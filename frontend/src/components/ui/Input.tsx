import type { InputHTMLAttributes, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'
import { useId } from 'react'
import { AlertCircle } from 'lucide-react'
import { cn } from '@/lib/cn'

/* Every state of a field lives in one place: `.field` in index.css. */
const fieldBase = 'field'

function Label({ htmlFor, children, hint }: { htmlFor: string; children: React.ReactNode; hint?: string }) {
  return (
    // `flex-wrap`: a long hint beside a long label squeezed the label onto two
    // lines on a phone and left the pair looking collided. It drops below instead.
    <label htmlFor={htmlFor} className="mb-1.5 flex flex-wrap items-baseline gap-x-2 text-[0.9375rem] font-semibold text-[var(--color-ink)]">
      <span>{children}</span>
      {hint && <span className="text-[0.875rem] font-normal text-[var(--color-ink-subtle)]">{hint}</span>}
    </label>
  )
}

function ErrorText({ id, children }: { id: string; children: React.ReactNode }) {
  return (
    <p id={id} role="alert" className="mt-1.5 flex items-start gap-1.5 text-[0.875rem] font-medium text-[var(--color-status-lost)]">
      <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden />
      <span>{children}</span>
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
  startSlot,
  endSlot,
  ...props
}: FieldProps & { startSlot?: React.ReactNode; endSlot?: React.ReactNode } & InputHTMLAttributes<HTMLInputElement>) {
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
      data-filled={props.value ? '' : undefined}
      aria-invalid={error ? true : undefined}
      aria-describedby={error ? errorId : undefined}
      className={cn(
        fieldBase,
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
      ) : startSlot || endSlot ? (
        <div className="relative">
          {field}
          {startSlot && <span aria-hidden className="pointer-events-none absolute inset-y-0 start-3.5 flex items-center text-[var(--color-ink-muted)]">{startSlot}</span>}
          {endSlot && <span className="absolute inset-y-0 end-0 flex items-center">{endSlot}</span>}
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
        data-filled={props.value ? '' : undefined}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        className={cn(
          fieldBase,
          'h-auto py-2.5 leading-relaxed',
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
        data-filled={props.value ? '' : undefined}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        className={cn(
          fieldBase,
          'cursor-pointer appearance-none bg-no-repeat pe-9',
          "bg-[url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%2364748b'%3E%3Cpath d='M5.2 7.5 10 12.3l4.8-4.8'  stroke='%2364748b' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E\")]",
          '[background-position:left_0.65rem_center] [background-size:1.1rem] rtl:[background-position:left_0.65rem_center] ltr:[background-position:right_0.65rem_center]',
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

/** A code from an authenticator app or a recovery code: the same field, read digit by digit. */
export function OtpInput({ className, ...props }: FieldProps & { endSlot?: React.ReactNode } & InputHTMLAttributes<HTMLInputElement>) {
  return <Input dir="ltr" autoComplete="one-time-code" spellCheck={false} placeholder="123456" {...props} className={cn('field-otp', className)} />
}
