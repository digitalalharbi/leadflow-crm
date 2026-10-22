import { apiGet } from '@/lib/api'

export interface SearchResults {
  leads: {
    id: string
    name: string
    phone: string | null
    status: string | null
    assignee: string | null
  }[]
  projects: { id: string; name: string }[]
  people: { id: string; name: string; role: string | null }[]
  properties?: { id: string; code: string; title: string | null; project: string | null; project_id: string | null; status: string }[]
  campaigns?: { id: string; name: string; platform: string }[]
  partners?: { id: string; name: string; type: string }[]
}

export function search(q: string) {
  return apiGet<SearchResults>('/search', { q })
}
