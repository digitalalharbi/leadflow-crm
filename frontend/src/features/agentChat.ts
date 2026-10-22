import { useQuery } from '@tanstack/react-query'
import { apiDelete, apiGet, apiPost, apiPut } from '@/lib/api'
import type { Proposal } from '@/features/proposals'
import type { AgentFinding } from '@/features/agents'

export type AgentKey = 'orchestrator' | 'campaign' | 'analyst' | 'creative' | 'leads' | 'reports'

export const AGENT_KEYS: AgentKey[] = ['orchestrator', 'campaign', 'analyst', 'creative', 'leads', 'reports']

export interface DraftView {
  id: string
  ad: { id: string; name: string } | null
  kind: 'copy' | 'hook' | 'variation'
  items: string[]
  rationale: string | null
  source?: 'agent' | 'user'
  status: string
  flags: string[]
}

export type Block =
  | { type: 'kpis'; items: { key: string; value: number | null; format: 'money' | 'number' | 'percent' | 'ratio' }[] }
  | { type: 'table'; columns: string[]; rows: Record<string, unknown>[] }
  | { type: 'ranking'; title?: string; items: { label: string; value: number; note?: string | null }[] }
  | { type: 'findings'; items: AgentFinding[] }
  | { type: 'proposal'; proposal: Proposal }
  | { type: 'drafts'; items: DraftView[] }
  | { type: 'report'; report_id: string }

export interface ChatMessage {
  role: 'user' | 'agent' | 'tool'
  agent: AgentKey | null
  content: string
  data: { blocks?: Block[]; mode?: 'ai' | 'rules'; blocked_reason?: string | null; detail?: string | null; model?: string | null; tool?: string } | null
  at: string | null
}

export interface ChatThread {
  id: string
  agent: AgentKey
  title: string
  messages: ChatMessage[]
}

export interface AiStatus {
  status: { state: 'ready' | 'blocked'; reason: string | null }
  source: 'workspace' | 'platform' | null
  verified_at: string | null
  last_error: string | null
}

export function useConversations() {
  return useQuery({
    queryKey: ['agent-conversations'],
    queryFn: () => apiGet<{ threads: { id: string; agent: AgentKey; title: string; created_at: string | null }[] }>('/agents/conversations').then((r) => r.threads),
  })
}

export function useThread(id: string | null) {
  return useQuery({
    queryKey: ['agent-thread', id],
    queryFn: () => apiGet<{ thread: ChatThread }>(`/agents/threads/${id}`).then((r) => r.thread),
    enabled: id !== null,
  })
}

export function useAiStatus() {
  return useQuery({ queryKey: ['agent-ai'], queryFn: () => apiGet<AiStatus>('/agents/ai') })
}

export const startConversation = (message: string, agent?: AgentKey) =>
  apiPost<{ thread: ChatThread }>('/agents/conversations', { message, agent }).then((r) => r.thread)

export const sendMessage = (id: string, message: string, agent?: AgentKey) =>
  apiPost<{ thread: ChatThread }>(`/agents/conversations/${id}/messages`, { message, agent }).then((r) => r.thread)

export const saveAiKey = (apiKey: string) => apiPut<AiStatus>('/agents/ai', { api_key: apiKey })
export const forgetAiKey = () => apiDelete<AiStatus>('/agents/ai')
