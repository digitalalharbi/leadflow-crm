import { apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'

export type ScheduleFrequency = 'daily' | 'weekly' | 'monthly'

export interface ReportSchedule {
  id: string
  frequency: ScheduleFrequency
  /** 1 (Monday) … 7 (Sunday), for a weekly one. */
  weekday: number | null
  /** 1 … 28, for a monthly one. */
  month_day: number | null
  send_hour: number
  recipients: string[]
  message: string | null
  enabled: boolean
  next_run_at: string | null
  last_run_at: string | null
  /** sent · stopped (the report was closed) */
  last_status: 'sent' | 'stopped' | null
}

export interface ScheduleInput {
  frequency: ScheduleFrequency
  weekday?: number | null
  month_day?: number | null
  send_hour?: number
  recipients: string[]
  message?: string | null
  enabled?: boolean
}

export const fetchSchedules = (shareId: string) => apiGet<{ schedules: ReportSchedule[] }>(`/report-shares/${shareId}/schedules`)
export const createSchedule = (shareId: string, body: ScheduleInput) => apiPost<{ schedule: ReportSchedule }>(`/report-shares/${shareId}/schedules`, body)
export const updateSchedule = (id: string, body: Partial<ScheduleInput>) => apiPatch<{ schedule: ReportSchedule }>(`/report-schedules/${id}`, body)
export const deleteSchedule = (id: string) => apiDelete<{ deleted: boolean }>(`/report-schedules/${id}`)
