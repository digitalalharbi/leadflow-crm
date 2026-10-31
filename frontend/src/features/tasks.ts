import { apiGet, apiPatch, apiPost } from '@/lib/api'

export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent'

export interface TaskRow {
  id: string
  title: string
  notes: string | null
  due_at: string | null
  priority: TaskPriority
  status: 'open' | 'done' | 'cancelled'
  overdue: boolean
  completed_at: string | null
  lead: { id: string; name: string } | null
  project: { id: string; name: string } | null
  assignee: { id: string; name: string } | null
}

export const fetchTasks = (params: { bucket?: 'overdue' | 'today' | 'upcoming' | 'open' | 'done'; mine?: boolean; lead?: string }) =>
  apiGet<TaskRow[]>('/tasks', params)

export const createTask = (body: { title: string; lead?: string; due_at?: string | null; priority?: TaskPriority }) => apiPost<TaskRow>('/tasks', body)

export const completeTask = (id: string) => apiPatch<TaskRow>(`/tasks/${id}`, { status: 'done' })
