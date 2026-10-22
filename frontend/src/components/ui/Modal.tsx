import { useEffect, useLayoutEffect, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'
import { cn } from '@/lib/cn'

interface ModalProps {
  open: boolean
  onClose: () => void
  title: ReactNode
  description?: ReactNode
  children: ReactNode
  footer?: ReactNode
  size?: 'sm' | 'md' | 'lg'
}

const sizes = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl' }

export function Modal({ open, onClose, title, description, children, footer, size = 'md' }: ModalProps) {
  const panelRef = useRef<HTMLDivElement>(null)

  // Callers pass an inline arrow for onClose, so it is a new function on every
  // render. Keeping it in a ref lets the effect below depend only on `open` —
  // otherwise it re-runs on each render and yanks focus out of whichever field
  // the user is typing in, which silently breaks every form in a dialog.
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

    // Stop the page behind from scrolling while a dialog is open.
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    // Move focus into the dialog once, when it opens, for keyboard users.
    panelRef.current?.focus()

    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = previousOverflow
    }
  }, [open])

  if (!open) return null

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4">
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
        className={cn(
          'relative z-10 flex max-h-[92dvh] w-full flex-col overflow-hidden',
          'bg-[var(--color-surface-raised)] shadow-[var(--shadow-pop)] outline-none',
          // A sheet on a phone, a dialog on a desktop: it rises from the bottom
          // edge where a thumb is, and from the centre where a cursor is.
          'animate-rise rounded-t-[var(--radius-panel)] sm:rounded-[var(--radius-panel)]',
          // The home indicator's room, so a sheet's last button is never under it.
          'pb-[env(safe-area-inset-bottom)] sm:pb-0',
          sizes[size],
        )}
      >
        {/* The grab handle a sheet needs; invisible once it is a dialog. */}
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
