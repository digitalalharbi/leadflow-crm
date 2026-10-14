import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { formatLastSeen } from '@/lib/format'
import { Badge, Card, CardBody, CardHeader, Skeleton, Table, TableWrap, Td, Th, Tr } from '@/components/ui'

/**
 * Which integrations have ever actually worked.
 *
 * The distinction this screen exists for: **implemented** and **externally
 * verified** are not the same claim. Every adapter has tests, and tests prove
 * the code does what we think when the other end behaves as we think — they
 * cannot prove the other end exists.
 *
 * Shown to the customer rather than kept in a release note, because the person
 * who needs to know a channel has never carried a real message is the person
 * about to rely on it.
 */
interface HealthRow {
  key: string
  kind: string
  connected: boolean
  configured: boolean
  last_event_at: string | null
  last_error: string | null
  events: number
  verification: 'implemented' | 'externally_verified' | 'manual_only'
}

export function IntegrationHealth() {
  const { t, i18n } = useTranslation()

  const { data, isLoading } = useQuery({
    queryKey: ['integration-health'],
    queryFn: () => apiGet<{ integrations: HealthRow[] }>('/integrations/health'),
  })

  if (isLoading) return <Skeleton className="h-40" />

  return (
    <Card>
      <CardHeader title={t('health.title')} description={t('health.hint')} />
      <CardBody className="p-0">
        <TableWrap>
          <Table>
            <thead>
              <tr>
                <Th>{t('health.integration')}</Th>
                <Th>{t('health.state')}</Th>
                <Th className="hidden md:table-cell">{t('health.lastEvent')}</Th>
                <Th className="hidden lg:table-cell">{t('health.events')}</Th>
              </tr>
            </thead>
            <tbody>
              {data?.integrations.map((row) => (
                <Tr key={row.key}>
                  <Td className="font-medium">{t(`health.name.${row.key}`, { defaultValue: row.key })}</Td>
                  <Td>
                    <Badge tone={TONE[row.verification]} testId={`health-${row.key}`}>
                      {t(`health.verification.${row.verification}`)}
                    </Badge>
                    {row.last_error && (
                      <span className="t-caption ms-2 text-[var(--color-danger)]">{row.last_error}</span>
                    )}
                  </Td>
                  <Td className="t-caption hidden md:table-cell">
                    {row.last_event_at ? formatLastSeen(row.last_event_at, i18n.language) : t('health.never')}
                  </Td>
                  <Td className="tabular hidden lg:table-cell">{row.events}</Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        </TableWrap>
      </CardBody>
    </Card>
  )
}

const TONE: Record<string, 'success' | 'warning' | 'neutral'> = {
  externally_verified: 'success',
  // Not a failure — the code is written and tested. It is a claim nobody has
  // checked against the real thing, and saying so is the point.
  implemented: 'warning',
  manual_only: 'neutral',
}
