import { useEffect, useRef, useState, type ReactNode } from 'react'
import { cn } from '@/lib/cn'

interface DropdownProps {
  trigger: (props: { open: boolean; toggle: () => void }) => ReactNode
  children: (props: { close: () => void }) => ReactNode
  align?: 'start' | 'end'
  className?: string
}

export function Dropdown({ trigger, children, align = 'end', className }: DropdownProps) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return

    const onPointerDown = (event: MouseEvent) => {
      if (ref.current && !ref.current.contains(event.target as Node)) setOpen(false)
    }

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKey)

    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  return (
    <div ref={ref} className="relative">
      {trigger({ open, toggle: () => setOpen((v) => !v) })}
      {open && (
        <div
          className={cn(
            'absolute z-40 mt-1 min-w-48 overflow-hidden rounded-[var(--radius-card)] border border-[var(--color-border)]',
            'bg-[var(--color-surface)] py-1 shadow-[var(--shadow-pop)]',
            align === 'end' ? 'end-0' : 'start-0',
            className,
          )}
        >
          {children({ close: () => setOpen(false) })}
        </div>
      )}
    </div>
  )
}

export function DropdownItem({
  children,
  onClick,
  danger,
  icon,
  testId,
}: {
  children: ReactNode
  onClick?: () => void
  danger?: boolean
  icon?: ReactNode
  testId?: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      data-testid={testId}
      className={cn(
        'flex w-full items-center gap-2.5 px-3 py-2 text-start text-[0.8125rem]',
        'hover:bg-[var(--color-surface-sunken)]',
        danger ? 'text-[var(--color-status-lost)]' : 'text-[var(--color-ink)]',
      )}
    >
      {icon}
      {children}
    </button>
  )
}
