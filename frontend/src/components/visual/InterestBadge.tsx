import { Flame, Heart, KeyRound, MapPinCheck, Snowflake, ThermometerSun, type LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/cn'

export type InterestLevel = 'cold' | 'warm' | 'interested' | 'hot' | 'visit_ready' | 'reservation_ready'

export const INTEREST_LEVELS: InterestLevel[] = ['cold', 'warm', 'interested', 'hot', 'visit_ready', 'reservation_ready']

/** One colour and one icon per level, so the level is read from the shape before the word. */
export const INTEREST: Record<InterestLevel, { Icon: LucideIcon; color: string }> = {
  cold: { Icon: Snowflake, color: 'var(--color-ink-subtle)' },
  warm: { Icon: ThermometerSun, color: 'var(--color-warning)' },
  interested: { Icon: Heart, color: 'var(--color-info)' },
  hot: { Icon: Flame, color: 'var(--color-temp-hot)' },
  visit_ready: { Icon: MapPinCheck, color: 'var(--color-status-appointment)' },
  reservation_ready: { Icon: KeyRound, color: 'var(--color-success)' },
}

/** How interested a lead is, as a pill: the level's icon and word on its own tint. */
export function InterestBadge({ level, className, testId = 'interest-badge' }: { level: string | null | undefined; className?: string; testId?: string }) {
  const { t } = useTranslation()
  const key = (INTEREST_LEVELS as string[]).includes(level ?? '') ? (level as InterestLevel) : 'cold'
  const { Icon, color } = INTEREST[key]

  return (
    <span
      data-testid={testId}
      data-level={key}
      className={cn('inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[0.875rem] font-bold', className)}
      style={{ background: `color-mix(in srgb, ${color} 14%, transparent)`, color }}
    >
      <Icon className="size-3.5 shrink-0" aria-hidden />
      {t(`interest.level.${key}`)}
    </span>
  )
}
