export interface ApiEnvelope<T> {
  success: boolean
  data: T
  message: string | null
  errors: Record<string, string[]> | null
  meta?: PaginationMeta
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export type LeadStatus = 'new' | 'contacted' | 'qualified' | 'appointment' | 'won' | 'lost'
export type LeadTemperature = 'hot' | 'warm' | 'cold'
export type UserRole = 'super_admin' | 'admin' | 'manager' | 'sales'

export interface Branding {
  tenant_id: string
  slug: string
  company_name: string
  system_name: string
  logo_url: string | null
  login_logo_url: string | null
  login_background_url: string | null
  favicon_url: string | null
  primary_color: string
  secondary_color: string
  support_email: string | null
  support_phone: string | null
  locale: 'ar' | 'en'
  currency: string
  timezone: string
}

export interface User {
  id: string
  name: string
  email: string
  role: UserRole
  phone: string | null
  avatar_url: string | null
  is_active: boolean
  locale: string | null
  last_login_at: string | null
  created_at: string
  leads_count?: number
}

export interface Ref {
  id: string
  name: string
}

export interface LeadListItem {
  id: string
  full_name: string
  phone: string | null
  whatsapp: string | null
  email: string | null
  city: string | null
  status: LeadStatus
  temperature: LeadTemperature
  score: number
  platform: string | null
  campaign_name: string | null
  project: Ref | null
  assignee: Ref | null
  next_follow_up_at: string | null
  is_overdue: boolean
  created_at: string
}

export interface Lead extends LeadListItem {
  first_name: string | null
  last_name: string | null
  phone_normalized: string | null
  source: string | null
  campaign_id: string | null
  adset_name: string | null
  ad_name: string | null
  form_name: string | null
  provider: string | null
  budget_min: number | null
  budget_max: number | null
  purchase_purpose: string | null
  payment_method: string | null
  financing_status: string | null
  preferred_property_type: string | null
  preferred_location: string | null
  purchase_timeline: string | null
  last_contact_at: string | null
  won_at: string | null
  lost_at: string | null
  lost_note: string | null
  lost_reason: { id: string; label: string } | null
  re_engaged_count: number
  custom_fields: CustomFieldValue[]
  updated_at: string
}

export interface CustomFieldValue {
  key: string
  label: string
  type: string
  value: string | null
}

export interface LeadActivity {
  id: string
  type: string
  description: string | null
  meta: Record<string, unknown> | null
  user: Ref | null
  created_at: string
}

export interface LeadNote {
  id: string
  body: string
  user: Ref | null
  created_at: string
}

export interface FollowUp {
  id: string
  due_at: string
  note: string | null
  status: 'pending' | 'completed' | 'cancelled'
  outcome: string | null
  completed_at: string | null
  is_overdue: boolean
  lead: {
    id: string
    full_name: string
    phone: string | null
    whatsapp: string | null
    status: LeadStatus
    temperature: LeadTemperature
  } | null
  assignee: Ref | null
  created_at: string
}

export interface Project {
  id: string
  name: string
  developer_name: string | null
  location: string | null
  starting_price: number | null
  status: 'active' | 'paused' | 'sold_out'
  notes: string | null
  leads_count?: number
  created_at: string
}

export interface ChartPoint {
  key: string
  count: number
}

export interface DashboardData {
  summary: {
    total_leads: number
    new: number
    contacted: number
    qualified: number
    appointments: number
    won: number
    lost: number
    conversion_rate: number
    hot_leads: number
    unassigned: number
  }
  today: {
    new_leads: number
    follow_ups_today: number
    follow_ups_overdue: number
    appointments: number
  }
  charts: {
    by_platform: ChartPoint[]
    by_status: ChartPoint[]
    by_campaign: ChartPoint[]
    trend: ChartPoint[]
  }
  hot_leads: LeadListItem[]
  overdue_leads: LeadListItem[]
  filters: { projects: Ref[]; users: Ref[] }
}

export interface ReportRow {
  key: string
  label: string
  total: number
  qualified: number
  appointments: number
  won: number
  lost: number
  conversion_rate: number
}

export interface Integration {
  id: string
  provider: string
  name: string | null
  status: 'connected' | 'not_connected' | 'configuration_required' | 'error'
  is_active: boolean
  config: Record<string, unknown> | null
  field_mapping: Record<string, string> | null
  defaults: Record<string, string> | null
  configured_credentials: string[]
  last_synced_at: string | null
  last_error: string | null
  leads_received: number
  webhook_endpoints?: WebhookEndpoint[]
  created_at: string
}

export interface WebhookEndpoint {
  id: string
  key: string
  label: string | null
  url: string
  is_active: boolean
  last_received_at: string | null
  received_count: number
}

export interface IntegrationCard {
  provider: string
  required_credentials: string[]
  status: Integration['status']
  integration: Integration | null
}

export interface TenantSummary {
  id: string
  name: string
  slug: string
  system_name: string | null
  logo_url: string | null
  primary_color: string | null
  plan: string
  subscription_status: string
  trial_ends_at: string | null
  subscription_ends_at: string | null
  is_active: boolean
  users_count: number | null
  leads_count: number | null
  created_at: string
}

export interface AppNotification {
  id: string
  type: string
  data: Record<string, unknown> & { title?: string; body?: string; lead_id?: string }
  read_at: string | null
  created_at: string
}

export interface OnboardingState {
  branding: boolean
  team: boolean
  project: boolean
  lead_source: boolean
  first_lead: boolean
}

export interface TenantSettings {
  locale: 'ar' | 'en'
  currency: string
  timezone: string
  default_country_code: string
  assignment_mode: 'manual' | 'round_robin'
  score_warm_threshold: number
  score_hot_threshold: number
  whatsapp_template: string | null
  onboarding: OnboardingState | null
}

export interface LostReason {
  id: string
  label: string
  is_active: boolean
}

export interface CustomField {
  id: string
  key: string
  label: string
  type: 'text' | 'number' | 'select' | 'date' | 'boolean'
  options: string[] | null
  is_required: boolean
  is_active: boolean
}
