import { CheckCircle2, Info, X, XCircle } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { cn } from '@/lib/cn'

const tone = {
  success: { cls: 'border-[color-mix(in_srgb,var(--color-status-won)_25%,transparent)] bg-[color-mix(in_srgb,var(--color-status-won)_12%,transparent)] text-[var(--color-status-won)]', Icon: CheckCircle2 },
  error: { cls: 'border-[color-mix(in_srgb,var(--color-status-lost)_25%,transparent)] bg-[color-mix(in_srgb,var(--color-status-lost)_12%,transparent)] text-[var(--color-status-lost)]', Icon: XCircle },
  info: { cls: 'border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink)]', Icon: Info },
}

export function Toaster() {
  const toasts = useUi((s) => s.toasts)
  const dismiss = useUi((s) => s.dismissToast)

  if (toasts.length === 0) return null

  return (
    <div
      role="status"
      aria-live="polite"
      className="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:inset-x-auto sm:end-4 sm:items-end"
    >
      {toasts.map(({ id, message, variant }) => {
        const { cls, Icon } = tone[variant]

        return (
          <div
            key={id}
            className={cn(
              'pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-[var(--radius-card)] border px-3.5 py-3',
              'text-[0.9375rem] shadow-[var(--shadow-pop)]',
              cls,
            )}
          >
            <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
            <p className="min-w-0 flex-1 leading-snug">{message}</p>
            <button type="button" onClick={() => dismiss(id)} className="shrink-0 opacity-60 hover:opacity-100">
              <X className="size-3.5" aria-hidden />
            </button>
          </div>
        )
      })}
    </div>
  )
}
