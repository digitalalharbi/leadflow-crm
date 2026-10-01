import { CheckCircle2, Info, X, XCircle } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { cn } from '@/lib/cn'

const tone = {
  success: { cls: 'border-emerald-200 bg-emerald-50 text-emerald-900', Icon: CheckCircle2 },
  error: { cls: 'border-red-200 bg-red-50 text-red-900', Icon: XCircle },
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
      className="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:inset-x-auto sm:end-4 sm:items-end"
    >
      {toasts.map(({ id, message, variant }) => {
        const { cls, Icon } = tone[variant]

        return (
          <div
            key={id}
            className={cn(
              'pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-lg border px-3.5 py-3',
              'text-[0.8125rem] shadow-[var(--shadow-pop)]',
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
