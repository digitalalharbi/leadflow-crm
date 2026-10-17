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

/*
 * The pipeline stages, then the two outcomes a lead exits through. `invalid`
 * and `no_response` are deliberately not board columns: nothing is waiting in
 * them, so offering them as a drop target would invite someone to treat an exit
 * as progress.
 */
export type LeadStatus =
  | 'new' | 'contacted' | 'replied' | 'qualified' | 'appointment' | 'negotiation'
  | 'won' | 'lost'
  | 'invalid' | 'no_response'
export type LeadTemperature = 'hot' | 'warm' | 'cold'
export type UserRole = 'super_admin' | 'admin' | 'manager' | 'sales'

export interface Branding {
  tenant_id: string
  slug: string
  company_name: string
  system_name: string | null
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
  /** When they are back. Null means they are here. */
  away_until?: string | null
  /** Cities this rep sells in. Empty means every city. */
  covers_cities?: string[]
  /** How many leads they take for every one an ordinary rep takes. */
  assignment_weight?: number
  /**
   * Languages this rep can sell in. Empty means every language — it is a claim
   * that narrows, not a permission. Distinct from `locale`, which is their own
   * choice of interface language.
   */
  speaks?: string[]
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
  /**
   * The language to expect on the call, read off the number's country code.
   * Null means the number says nothing — see the backend PhoneLanguage.
   */
  language: string | null
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
  /**
   * The language to expect on the call, read off the number's country code.
   * Null means the number says nothing, which the interface shows as nothing
   * rather than as a guess.
   */
  language: string | null
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
  /** What the deal closed for. Null is "nobody recorded it", not "worth zero". */
  deal_value: number | null
  lost_at: string | null
  lost_note: string | null
  lost_reason: { id: string; label: string } | null
  re_engaged_count: number
  custom_fields: CustomFieldValue[]
  updated_at: string

  qualification_status: QualificationStatus
  qualified_at: string | null
  appointment_interest: boolean | null
  first_contact_at: string | null
  first_response_at: string | null
  whatsapp_opt_out_at: string | null
  email_opt_out_at: string | null
}

export type QualificationStatus = 'unqualified' | 'partially_qualified' | 'qualified'

export type MessageStatus = 'pending' | 'sent' | 'delivered' | 'read' | 'failed' | 'received'

export interface LeadMessage {
  id: string
  direction: 'outbound' | 'inbound'
  channel: 'whatsapp' | 'sms' | 'email'
  message_type: string
  template_name: string | null
  /** Set on email only. */
  subject?: string | null
  body: string | null
  status: MessageStatus
  sent_at: string | null
  delivered_at: string | null
  read_at: string | null
  failed_at: string | null
  failure_code: string | null
  failure_reason: string | null
  sent_by?: { id: string; name: string } | null
  created_at: string
}

export interface MessageTemplate {
  id: string
  name: string
  /** Email templates only. */
  subject?: string | null
  body: string
  channel: string
  is_active: boolean
  sort_order: number
  /** The Meta-approved twin of this message, if the workspace registered one. */
  meta_template_name: string | null
  meta_language: string | null
  /** The lead fields that fill {{1}}, {{2}}, … in the approved template, in order. */
  meta_parameters: string[]
  /** Whether this message may open a conversation, rather than only answer one. */
  can_start_conversation: boolean
}

/** Why a lead scores what it scores — one entry per rule that fired. */
export interface ScoreReason {
  key: string
  label: string
  points: number
}

export interface ScoreBreakdown {
  score: number
  temperature: LeadTemperature
  reasons: ScoreReason[]
}

export interface QualificationOption {
  value: string
  label: string
}

export interface QualificationOptions {
  purchase_purpose: QualificationOption[]
  payment_method: QualificationOption[]
  financing_status: QualificationOption[]
  purchase_timeline: QualificationOption[]
}

export interface QualificationInput {
  purchase_purpose?: string | null
  budget_min?: number | null
  budget_max?: number | null
  payment_method?: string | null
  financing_status?: string | null
  preferred_property_type?: string | null
  preferred_location?: string | null
  purchase_timeline?: string | null
  appointment_interest?: boolean | null
}

/** Status is derived from what is filled in, so the UI never guesses. */
export interface WhatsappSettings {
  status: 'connected' | 'not_connected' | 'configuration_required' | 'error'
  mode: 'live' | 'mock'
  can_send: boolean
  is_active: boolean
  phone_number_id: string | null
  business_account_id: string | null
  has_access_token: boolean
  has_app_secret: boolean
  has_verify_token: boolean
  webhook_url: string
  last_error: string | null
  last_synced_at: string | null
}

export type FollowUpOutcome =
  | 'replied' | 'no_answer' | 'interested' | 'not_interested' | 'callback' | 'appointment'

export interface CampaignQualityRow {
  platform: string
  campaign: string
  leads: number
  contacted: number
  replied: number
  qualified: number
  hot: number
  appointments: number
  won: number
  lost: number
  contact_rate: number
  reply_rate: number
  qualification_rate: number
  appointment_rate: number
  win_rate: number
  /*
   * Null, not zero, when nobody has entered what this campaign cost. A
   * campaign that has not been costed is not free, and 0.00 beside a real
   * 41.20 invites exactly the wrong conclusion about which one to scale.
   */
  spend: number | null
  cost_per_lead: number | null
  /** Null when the campaign qualified nobody: the cost of nothing is not a rate. */
  cost_per_qualified: number | null
  /** What the won deals closed for. Null when nobody recorded any value. */
  revenue: number | null
  /*
   * Return on ad spend. Zero means the campaign sold nothing, which is a
   * finding. Null means its sales were never valued, which is not — and the
   * two deserve opposite decisions.
   */
  roas: number | null
}

export interface CampaignSpend {
  id: number
  platform: string
  campaign_name: string
  spend_date: string
  amount: number
  currency: string
  source: string
}

export interface SalesPerformance {
  counts: {
    leads: number; contacted: number; replied: number; qualified: number
    hot: number; appointments: number; won: number; lost: number
  }
  rates: {
    contact_rate: number; reply_rate: number; qualification_rate: number
    appointment_rate: number; win_rate: number
  }
  timing: {
    avg_first_contact_seconds: number | null
    avg_first_reply_seconds: number | null
  }
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
  outcome: FollowUpOutcome | null
  outcome_note: string | null
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
  /**
   * The reps who work this project. Empty means anybody — the routing only
   * engages once somebody says a project has a team.
   */
  team?: { id: string; name: string }[]
  leads_count?: number
  created_at: string
}

/** The funnel metrics a project card shows, keyed to the project's uuid. */
export interface ProjectMetrics {
  id: string
  leads: number
  qualified: number
  appointments: number
  won: number
  qualification_rate: number
}

export interface ChartPoint {
  key: string
  count: number
}

export interface DashboardData {
  /** Median hours per funnel step, for the leads that actually made it. */
  velocity: { step: string; median_hours: number | null; count: number }[]
  /** One point per day, each event counted on the day it happened. */
  timeline: { date: string; leads: number; qualified: number; won: number }[]
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
    awaiting_first_contact: number
    first_contact_target_minutes: number
  }
  charts: {
    by_platform: ChartPoint[]
    by_status: ChartPoint[]
    by_campaign: ChartPoint[]
    trend: ChartPoint[]
  }
  funnel: Array<{ key: string; count: number; of_total: number; from_previous: number }>
  quality: Array<{ key: string; count: number; share: number }>
  response_time: {
    buckets: Array<{ key: string; count: number; share: number }>
    contacted: number
    uncontacted: number
    average_seconds: number | null
    median_seconds: number | null
  }
  /** Absent when no date range is set — "everything" has no previous period. */
  comparison: {
    from: string
    to: string
    total: number
    qualified: number
    hot: number
    appointments: number
    won: number
  } | null
  hot_leads: LeadListItem[]
  overdue_leads: LeadListItem[]
  awaiting_contact_leads: LeadListItem[]
  filters: { projects: Ref[]; users: Ref[] }
}

export interface ReportRow {
  key: string
  label: string
  total: number
  share: number
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
  /** Polled by us, rather than calling our webhook. */
  pulls_leads: boolean
  status: Integration['status']
  integration: Integration | null
}

/** What one poll of a source actually did. */
export interface SyncResult {
  fetched: number
  created: number
  duplicates: number
  skipped: number
  rejected: number
  failed: number
  error: string | null
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
  /** Inbound over the last 30 days — size tells you nothing about activity. */
  leads_last_30_days: number | null
  last_lead_at: string | null
  created_at: string
}

export interface AppNotification {
  id: string
  type: string
  data: Record<string, unknown> & { title?: string; body?: string; lead_id?: string }
  read_at: string | null
  created_at: string
}

export interface TenantSettings {
  locale: 'ar' | 'en'
  currency: string
  timezone: string
  default_country_code: string
  assignment_mode: 'manual' | 'round_robin'
  score_warm_threshold: number
  score_hot_threshold: number
  first_contact_target_minutes: number
  whatsapp_template: string | null
  /**
   * When the business is open, in its own timezone. Null means always, which
   * is what the response-time clock assumed before anybody could say otherwise.
   * Days are ISO weekday numbers — 7 is Sunday.
   */
  work_days: number[] | null
  work_starts_at: string | null
  work_ends_at: string | null
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

/** What the settings screen may know about the workspace's mail server — never the password. */
export interface EmailSettings {
  configured: boolean
  /** Where a message would actually go: its own server, ours, or nowhere (log). */
  mode: 'smtp' | 'platform' | 'mock'
  host: string | null
  port: number | null
  username: string | null
  encryption: 'tls' | 'ssl'
  from_address: string | null
  from_name: string | null
  has_password: boolean
  status: string
  last_error: string | null
}
