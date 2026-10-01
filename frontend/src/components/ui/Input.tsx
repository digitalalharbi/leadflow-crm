import type { InputHTMLAttributes, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'
import { useId } from 'react'
import { cn } from '@/lib/cn'

const fieldBase =
  'w-full rounded-lg border bg-[var(--color-surface)] px-3 text-sm transition-colors ' +
  'placeholder:text-[var(--color-ink-subtle)] disabled:bg-[var(--color-surface-sunken)] disabled:opacity-60'

function Label({ htmlFor, children, hint }: { htmlFor: string; children: React.ReactNode; hint?: string }) {
  return (
    <label htmlFor={htmlFor} className="mb-1.5 flex items-baseline gap-2 text-[0.8125rem] font-medium">
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

  // Date/time pickers draw their own format hint; RTL reverses it into gibberish.
  const isDateLike =
    props.type === 'date' || props.type === 'datetime-local' || props.type === 'time'

  return (
    <div className={containerClassName}>
      {label && (
        <Label htmlFor={fieldId} hint={hint}>
          {label}
        </Label>
      )}
      <input
        id={fieldId}
        dir={props.dir ?? (isDateLike ? 'ltr' : undefined)}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        className={cn(
          fieldBase,
          'h-10',
          error
            ? 'border-[var(--color-status-lost)]'
            : 'border-[var(--color-border-strong)] focus:border-[var(--brand)]',
          className,
        )}
        {...props}
      />
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
            : 'border-[var(--color-border-strong)] focus:border-[var(--brand)]',
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
            : 'border-[var(--color-border-strong)] focus:border-[var(--brand)]',
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
