import { useEffect, useLayoutEffect, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'
import { cn } from '@/lib/cn'

interface DrawerProps {
  open: boolean
  onClose: () => void
  title: ReactNode
  description?: ReactNode
  children: ReactNode
  footer?: ReactNode
  size?: 'sm' | 'md' | 'lg'
  /** For tests and for the pages that need to find their own panel. */
  testId?: string
}

const sizes = { sm: 'sm:max-w-sm', md: 'sm:max-w-md', lg: 'sm:max-w-xl' }

/**
 * A panel that comes in from the side, for detail and settings.
 *
 * The difference from `Modal` is what each one is for, not how it looks. A
 * modal interrupts: it asks one question — delete this, mark this won — and the
 * screen behind it is irrelevant until it is answered. A drawer is a second
 * place to stand: a record's details, a source's settings, a rule's steps. The
 * list behind it stays visible and keeps its scroll position, so "open this
 * one, look, close, open the next" is one movement instead of three.
 *
 * Centred dialogs were doing both jobs here. The symptom is a dialog tall
 * enough to need its own scrollbar sitting on a page that also scrolls — two
 * scrollbars for one thing to read.
 *
 * Below `sm` it is a bottom sheet, like `Modal`: a side panel on a phone is
 * either the whole screen, in which case it should be a page, or a sliver.
 */
export function Drawer({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = 'md',
  testId,
}: DrawerProps) {
  const panelRef = useRef<HTMLDivElement>(null)

  // See Modal: callers pass an inline arrow, so the effect must not depend on
  // it — otherwise it re-runs on every render and pulls focus out of the field
  // somebody is typing in.
  const onCloseRef = useRef(onClose)

  useLayoutEffect(() => {
    onCloseRef.current = onClose
  })

  useEffect(() => {
    if (!open) return

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onCloseRef.current()
    }

    document.addEventListener('keydown', onKey)

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    panelRef.current?.focus()

    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = previousOverflow
    }
  }, [open])

  if (!open) return null

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-stretch sm:justify-end">
      <button
        type="button"
        aria-label="close"
        tabIndex={-1}
        className={cn(
          'absolute inset-0 animate-fade bg-[color-mix(in_srgb,var(--color-neutral-950)_45%,transparent)]',
          'backdrop-blur-[3px] backdrop-saturate-150',
        )}
        onClick={onClose}
      />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        tabIndex={-1}
        data-testid={testId}
        className={cn(
          'drawer-panel relative z-10 flex w-full flex-col overflow-hidden outline-none',
          'bg-[var(--color-surface-raised)] shadow-[var(--shadow-pop)]',
          // A sheet from the bottom edge on a phone, a full-height panel against
          // the inline edge from `sm` up.
          'max-h-[92dvh] rounded-t-[var(--radius-panel)]',
          'sm:max-h-none sm:h-dvh sm:rounded-none',
          sizes[size],
        )}
      >
        <div className="flex justify-center pt-2 sm:hidden" aria-hidden>
          <span className="h-1 w-9 rounded-full bg-[var(--color-border-strong)]" />
        </div>

        <div className="flex items-start justify-between gap-4 border-b border-[var(--color-line)] px-5 py-4">
          <div className="min-w-0">
            <h2 className="text-[1.0625rem] font-semibold">{title}</h2>
            {description && (
              <p className="mt-0.5 text-[0.9375rem] text-[var(--color-ink-muted)]">{description}</p>
            )}
          </div>
          <button
            type="button"
            onClick={onClose}
            className={cn(
              'pressable -me-1 -mt-1 grid size-8 shrink-0 place-items-center',
              'rounded-[var(--radius-control)] text-[var(--color-ink-muted)]',
              'hover:bg-[var(--color-surface-sunken)] hover:text-[var(--color-ink)]',
            )}
            aria-label="close"
          >
            <X className="size-4" aria-hidden />
          </button>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">{children}</div>

        {footer && (
          <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--color-line)] bg-[var(--color-surface-muted)] px-5 py-3">
            {footer}
          </div>
        )}
      </div>
    </div>,
    document.body,
  )
}
