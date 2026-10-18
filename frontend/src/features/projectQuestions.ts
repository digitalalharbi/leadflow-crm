import { apiDelete, apiGet, apiPatch, apiPost, apiPut } from '@/lib/api'

export type QuestionType = 'select' | 'boolean' | 'number' | 'text'
export type RuleOp = 'equals' | 'in' | 'is_true' | 'is_false' | 'gte' | 'lte' | 'between' | 'filled'
export type RuleEffect = 'priority' | 'wrong_fit'

export interface QuestionOption {
  value: string
  label: string
}

export interface QuestionRule {
  op: RuleOp
  value?: string | number | string[] | [number, number] | null
  points?: number
  effect?: RuleEffect | null
}

export interface ProjectQuestion {
  id: string
  key: string
  label: string
  type: QuestionType
  options: QuestionOption[]
  rules: QuestionRule[]
  is_required: boolean
  is_active: boolean
  sort_order: number
}

export interface QuestionsResponse {
  questions: ProjectQuestion[]
  operators: Record<QuestionType, RuleOp[]>
  effects: RuleEffect[]
  cap: number
}

export type QuestionPayload = Partial<Pick<ProjectQuestion, 'label' | 'options' | 'rules' | 'is_required' | 'is_active'>> & {
  type?: QuestionType
}

export function fetchProjectQuestions(projectId: string) {
  return apiGet<QuestionsResponse>(`/projects/${projectId}/questions`)
}

export function createProjectQuestion(projectId: string, payload: QuestionPayload) {
  return apiPost<{ question: ProjectQuestion }>(`/projects/${projectId}/questions`, payload)
}

export function updateProjectQuestion(id: string, payload: QuestionPayload) {
  return apiPatch<{ question: ProjectQuestion }>(`/project-questions/${id}`, payload)
}

export function deleteProjectQuestion(id: string) {
  return apiDelete(`/project-questions/${id}`)
}

export interface LeadQuestion {
  id: string
  key: string
  label: string
  type: QuestionType
  options: QuestionOption[]
  is_required: boolean
  answer: string | number | boolean | null
}

export interface LeadAnswers {
  questions: LeadQuestion[]
  is_priority: boolean
  wrong_fit: boolean
  score: number
  qualification_status: string | null
}

export function fetchLeadAnswers(leadId: string) {
  return apiGet<LeadAnswers>(`/leads/${leadId}/answers`)
}

export function saveLeadAnswers(leadId: string, answers: Record<string, unknown>) {
  return apiPut<LeadAnswers>(`/leads/${leadId}/answers`, { answers })
}
