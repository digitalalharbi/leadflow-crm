import type { Feature, Subscription } from '@/types/api'

export interface PlatformPlan {
  id: string
  key: string
  name: string
  description: string | null
  price_monthly: string | null
  price_yearly: string | null
  extra_seat_price: string | null
  currency: string
  included_seats: number
  max_seats: number | null
  limits: Record<'projects' | 'leads_per_month' | 'storage_mb', number | null>
  features: Feature[]
  is_active: boolean
  is_priced: boolean
  sort_order: number
  tenants_count: number | null
}

export interface PlatformInvoice {
  id: string
  number: string
  amount: string
  currency: string
  period_start: string | null
  period_end: string | null
  status: 'draft' | 'issued' | 'paid' | 'void'
  provider_ref: string | null
  issued_at: string | null
  paid_at: string | null
  notes: string | null
}

export interface PlatformSubscription extends Subscription {
  plan_id: string | null
  billing_cycle: 'monthly' | 'yearly'
  extra_seats: number
  seat_limit_override: number | null
  limit_overrides: Partial<Record<'projects' | 'leads_per_month' | 'storage_mb', number | null>> | null
  addons: Feature[]
  billing_provider: string
  billing_customer_ref: string | null
  billing_subscription_ref: string | null
  suspended_at: string | null
  cancelled_at: string | null
  status_reason: string | null
  monthly_value: { amount: number; currency: string } | null
}

export const ALL_FEATURES: Feature[] = [
  'tracking_hub', 'advanced_forms', 'automations', 'attribution', 'exports',
  'api', 'advanced_reports', 'whatsapp', 'custom_branding', 'multiple_projects',
]

export const LIMIT_KEYS = ['projects', 'leads_per_month', 'storage_mb'] as const
