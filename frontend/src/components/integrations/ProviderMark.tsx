import {
  FileUp, Megaphone, MessageCircle, Search, Share2, Table2, Webhook,
} from 'lucide-react'
import { platformColor } from '@/components/charts'
import { cn } from '@/lib/cn'

/**
 * A provider's mark.
 *
 * Deliberately not the real logos: shipping Meta's or Google's trademarks
 * inside a product we sell is someone else's decision, and a wall of borrowed
 * brands makes the page look like a directory rather than part of this product.
 *
 * Each provider gets an icon for what it *is* — an ad platform, a search
 * network, a messaging channel, a spreadsheet — tinted with the colour it
 * carries in every chart, so a row in the leads table, a bar in a report and
 * this card all agree about what "Snapchat" looks like.
 *
 * The tint comes from the chart palette, never from the tenant's brand: a
 * customer's colour identifies them, not the networks they buy from.
 */
const ICON = {
  meta: Megaphone,
  facebook: Megaphone,
  instagram: Megaphone,
  snapchat: Megaphone,
  tiktok: Megaphone,
  linkedin: Share2,
  google_ads: Search,
  google: Search,
  google_sheets: Table2,
  whatsapp: MessageCircle,
  generic_webhook: Webhook,
  webhook: Webhook,
  csv_import: FileUp,
} as const

export function ProviderMark({
  provider,
  size = 'md',
  className,
}: {
  provider: string
  size?: 'sm' | 'md'
  className?: string
}) {
  const Icon = ICON[provider as keyof typeof ICON] ?? Webhook
  const color = platformColor(provider)

  return (
    <span
      className={cn(
        'grid shrink-0 place-items-center rounded-[var(--radius-control)]',
        size === 'sm' ? 'size-8' : 'size-10',
        className,
      )}
      style={{
        background: `color-mix(in srgb, ${color} 14%, transparent)`,
        color,
      }}
      aria-hidden
    >
      <Icon className={size === 'sm' ? 'size-4' : 'size-[1.125rem]'} />
    </span>
  )
}
