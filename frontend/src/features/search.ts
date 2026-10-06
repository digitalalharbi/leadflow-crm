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
}

export function search(q: string) {
  return apiGet<SearchResults>('/search', { q })
}
