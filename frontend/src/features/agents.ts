import { useQuery } from '@tanstack/react-query'
import { apiGet, apiPost } from '@/lib/api'

export interface AgentFinding {
  key: string
  severity: 'bad' | 'warn' | 'good'
  campaign: { id: string; name: string; platform: string }
  figures: Record<string, number | null>
}

export interface AgentsData {
  agents: { key: string; name: string; role: string; is_active: boolean }[]
  routines: { key: string; agent: string; cron: string; is_active: boolean; last_run_at: string | null; last_status: 'ok' | 'failed' | null; last_summary: string | null }[]
  findings: AgentFinding[]
  analysed_at: string | null
  threads: { id: string; routine: string | null; agent: string; created_at: string | null }[]
}

export function useAgents() {
  return useQuery({ queryKey: ['agents'], queryFn: () => apiGet<AgentsData>('/agents') })
}

export const runRoutine = (key: string) => apiPost<{ thread: { id: string } }>(`/agents/routines/${key}/run`)
