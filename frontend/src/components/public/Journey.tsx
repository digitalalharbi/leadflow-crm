import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/cn'

/**
 * وصل ← تواصل ← تأهّل ← موعد ← صفقة, as one line.
 *
 * The last word is the one that matters, so it alone wears the copper. The arrow points the way the line is
 * read: left in Arabic, right in English. `tone` is the surface it sits on — copper is the light shade on
 * pine and the dark shade on a light page, so the text always clears 4.5:1.
 */
export function Journey({ steps, tone = 'light', className, testId = 'journey', compact = false }: { steps: readonly string[]; tone?: 'light' | 'dark'; className?: string; testId?: string; compact?: boolean }) {
  const { i18n } = useTranslation()
  const arrow = i18n.dir() === 'rtl' ? '←' : '→'

  return (
    <ol className={cn('flex flex-wrap items-center gap-y-1 font-semibold', compact ? 'gap-x-1.5 text-[0.9375rem]' : 'gap-x-2.5 text-[1.0625rem]', className)} data-testid={testId}>
      {steps.map((step, index) => (
        <li key={step} className={cn('flex items-center', compact ? 'gap-1.5' : 'gap-2.5')}>
          <span className={index === steps.length - 1 ? (tone === 'dark' ? 'text-[var(--accent-on-dark)]' : 'text-[var(--accent-text)]') : undefined} data-step={index}>{step}</span>
          {index < steps.length - 1 && <span aria-hidden className={tone === 'dark' ? 'text-[var(--chrome-muted)]' : 'text-[var(--color-ink-subtle)]'}>{arrow}</span>}
        </li>
      ))}
    </ol>
  )
}
