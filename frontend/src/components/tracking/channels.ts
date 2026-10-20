import { Globe, Link2, Mail, MessageCircle, MessageSquare, Megaphone, QrCode, Radio, Share2, UserRound, type LucideIcon } from 'lucide-react'
import type { Channel } from '@/features/tracking'

export const CHANNEL_ICON: Record<Channel, LucideIcon> = {
  meta: Megaphone, tiktok: Megaphone, snapchat: Megaphone, google: Megaphone, x: Share2, linkedin: Share2,
  whatsapp: MessageCircle, landing_page: Globe, portal: Globe, email: Mail, sms: MessageSquare,
  influencer: Radio, broker: UserRound, qr: QrCode, other: Link2,
}

/** Channels that map onto an ad platform keep that platform's colour; the rest stay neutral. */
export const CHANNEL_PLATFORM: Partial<Record<Channel, string>> = {
  meta: 'meta', tiktok: 'tiktok', snapchat: 'snapchat', google: 'google_ads', linkedin: 'linkedin', landing_page: 'website',
}
