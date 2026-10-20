import { Copy, Eye, MousePointerClick, TrendingUp, Users } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { platformColor } from '@/components/charts'
import { formatMoneyCompact, formatNumber } from '@/lib/format'
import type { TrackingLink } from '@/features/tracking'
import { CHANNEL_ICON, CHANNEL_PLATFORM } from './channels'
import { cn } from '@/lib/cn'

/**
 * One link: what it is for, how many people it drew, how many became leads and sales.
 * The three numbers that matter are large; the URL is one press from the clipboard.
 */
export function LinkCard({ link, onOpen, onCopy }: { link: TrackingLink; onOpen: () => void; onCopy: () => void }) {
  const { t, i18n } = useTranslation()
  const Icon = CHANNEL_ICON[link.channel]
  const color = CHANNEL_PLATFORM[link.channel] ? platformColor(CHANNEL_PLATFORM[link.channel]) : 'var(--color-ink-muted)'
  const s = link.stats

  return (
    <li data-testid={`link-${link.token}`} data-live={link.is_live} className="rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]">
      <div className="flex items-start justify-between gap-2">
        <button type="button" onClick={onOpen} className="pressable flex min-w-0 items-center gap-2 text-start" data-testid={`open-link-${link.token}`}>
          <span className="grid size-9 shrink-0 place-items-center rounded-full" style={{ background: `color-mix(in srgb, ${color} 16%, transparent)`, color }}>
            <Icon className="size-[1.125rem]" aria-hidden />
          </span>
          <span className="min-w-0">
            <span className="block truncate text-[1.0625rem] font-extrabold">{link.name}</span>
            <span className="block truncate text-[0.875rem] text-[var(--color-ink-subtle)]">
              {[t(`tracking.channels.${link.channel}`), link.project?.name, link.campaign?.name].filter(Boolean).join(' · ')}
            </span>
          </span>
        </button>
        <span
          className={cn('shrink-0 rounded-full px-2 py-0.5 text-[0.8125rem] font-bold', link.is_live ? 'bg-[color-mix(in_srgb,var(--color-success)_16%,transparent)] text-[var(--color-success)]' : 'bg-[var(--color-surface-muted)] text-[var(--color-ink-muted)]')}
          data-testid={`link-state-${link.token}`}
        >
          {link.is_live ? t('tracking.live') : t('tracking.off')}
        </span>
      </div>

      <dl className="mt-3 grid grid-cols-4 gap-2 text-center">
        {([
          [MousePointerClick, 'clicks', s.clicks], [Eye, 'visitors', s.visitors], [Users, 'leads', s.leads], [TrendingUp, 'won', s.won],
        ] as const).map(([StatIcon, key, value]) => (
          <div key={key} className="rounded-xl bg-[var(--color-surface-muted)] px-1 py-1.5" data-testid={`link-${link.token}-${key}`}>
            <StatIcon className="mx-auto size-4 text-[var(--color-ink-subtle)]" aria-hidden />
            <dd className="text-[1.125rem] font-extrabold tabular-nums">{formatNumber(value, i18n.language)}</dd>
            <dt className="text-[0.8125rem] text-[var(--color-ink-subtle)]">{t(`tracking.stat.${key}`)}</dt>
          </div>
        ))}
      </dl>

      <div className="mt-3 flex items-center justify-between gap-2">
        <button type="button" onClick={onCopy} data-testid={`copy-link-${link.token}`}
          className="pressable flex min-w-0 items-center gap-1.5 rounded-full bg-[var(--color-surface-muted)] px-3 py-1 text-[0.875rem] font-semibold">
          <Copy className="size-4 shrink-0" aria-hidden />
          <span className="truncate" dir="ltr">/t/{link.token}</span>
        </button>
        {s.revenue > 0 && <span className="text-[0.9375rem] font-extrabold tabular-nums" dir="ltr">{formatMoneyCompact(s.revenue, 'SAR', i18n.language)}</span>}
      </div>
    </li>
  )
}
