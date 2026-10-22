import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'

export interface Recommendation {
  key: string
  /** `insight` says what is going on; `recommendation` also says what to do. */
  kind: 'insight' | 'recommendation'
  severity: 'info' | 'warning' | 'critical'
  values: Record<string, string | number | null>
  /** Where in the product to act on it. */
  link: string | null
}

export function useRecommendations(days = 30) {
  return useQuery({
    queryKey: ['recommendations', days],
    queryFn: () => apiGet<{ items: Recommendation[]; days: number }>('/insights/recommendations', { days }),
    staleTime: 5 * 60_000,
  })
}
