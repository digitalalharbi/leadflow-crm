import { useQuery } from '@tanstack/react-query'
import { BarChart3, Download } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchCampaignQuality, fetchSalesPerformance } from '@/features/conversations'
import { apiDownload } from '@/lib/api'
import { useApiError } from '@/hooks/useApiError'
import { formatNumber } from '@/lib/format'
import {
  Button, Card, EmptyState, ErrorState, SkeletonTable, StatCard,
  Table, TableWrap, Td, Th, Tr,
} from '@/components/ui'
import { cn } from '@/lib/cn'

/**
 * Which campaigns bring buyers.
 *
 * The lead count is deliberately not the headline: a campaign with 500 leads
 * and one viewing is worse than one with 300 and fifteen, and the only way to
 * see that is to put the rates next to the totals.
 */
export function CampaignQuality({ filters }: { filters: Record<string, string | undefined> }) {
  const { t, i18n } = useTranslation()
  const translateError = useApiError()

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['campaign-quality', filters],
    queryFn: () => fetchCampaignQuality(filters),
    placeholderData: (previous) => previous,
  })

  const { data: performance } = useQuery({
    queryKey: ['sales-performance', filters],
    queryFn: () => fetchSalesPerformance(filters),
    placeholderData: (previous) => previous,
  })

  const rows = data?.rows ?? []

  return (
    <div className="space-y-4" data-testid="campaign-quality">
      {performance && (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
          <StatCard label={t('campaignQuality.leads')} value={formatNumber(performance.counts.leads, i18n.language)} />
          <StatCard label={t('campaignQuality.contactRate')} value={`${performance.rates.contact_rate}%`} />
          <StatCard label={t('campaignQuality.replyRate')} value={`${performance.rates.reply_rate}%`} />
          <StatCard label={t('campaignQuality.qualificationRate')} value={`${performance.rates.qualification_rate}%`} />
          <StatCard label={t('campaignQuality.appointmentRate')} value={`${performance.rates.appointment_rate}%`} />
          <StatCard
            label={t('campaignQuality.avgFirstContact')}
            value={formatDuration(performance.timing.avg_first_contact_seconds, t)}
            testId="avg-first-contact"
          />
        </div>
      )}

      <Card className="overflow-hidden">
        <div className="flex items-center justify-between gap-3 border-b border-[var(--color-border)] p-3">
          <div>
            <p className="text-[0.875rem] font-semibold">{t('campaignQuality.title')}</p>
            <p className="text-[0.8125rem] text-[var(--color-ink-muted)]">{t('campaignQuality.subtitle')}</p>
          </div>
          <Button
            variant="outline"
            size="sm"
            icon={<Download className="size-4" />}
            disabled={rows.length === 0}
            onClick={() => void apiDownload('/reports/campaign-quality/export', filters, 'campaign-quality.csv')}
          >
            {t('reports.exportCsv')}
          </Button>
        </div>

        {isError ? (
          <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
        ) : isLoading ? (
          <SkeletonTable rows={6} cols={7} />
        ) : rows.length === 0 ? (
          <EmptyState icon={<BarChart3 className="size-6" />} title={t('campaignQuality.empty')} />
        ) : (
          <TableWrap>
            <Table>
              <thead>
                <tr>
                  <Th>{t('campaignQuality.campaign')}</Th>
                  <Th className="hidden sm:table-cell">{t('campaignQuality.platform')}</Th>
                  <Th>{t('campaignQuality.leads')}</Th>
                  <Th className="hidden md:table-cell">{t('campaignQuality.contacted')}</Th>
                  <Th className="hidden md:table-cell">{t('campaignQuality.replied')}</Th>
                  <Th>{t('campaignQuality.qualified')}</Th>
                  <Th className="hidden sm:table-cell">{t('campaignQuality.hot')}</Th>
                  <Th>{t('campaignQuality.appointments')}</Th>
                  <Th>{t('campaignQuality.won')}</Th>
                  <Th>{t('campaignQuality.qualificationRate')}</Th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <Tr key={`${row.platform}-${row.campaign}`}>
                    <Td className="font-medium">{row.campaign}</Td>
                    <Td className="hidden text-[var(--color-ink-muted)] sm:table-cell">
                      {t(`platforms.${row.platform}`, { defaultValue: row.platform })}
                    </Td>
                    <Td className="tabular">{formatNumber(row.leads, i18n.language)}</Td>
                    <Td className="tabular hidden md:table-cell">{formatNumber(row.contacted, i18n.language)}</Td>
                    <Td className="tabular hidden md:table-cell">{formatNumber(row.replied, i18n.language)}</Td>
                    <Td className="tabular">{formatNumber(row.qualified, i18n.language)}</Td>
                    <Td className="tabular hidden sm:table-cell">{formatNumber(row.hot, i18n.language)}</Td>
                    <Td className="tabular">{formatNumber(row.appointments, i18n.language)}</Td>
                    <Td className="tabular font-medium">{formatNumber(row.won, i18n.language)}</Td>
                    <Td>
                      {/* The share, not the count: this column is the whole
                          point of the report. */}
                      <span
                        className={cn(
                          'tabular font-medium',
                          row.qualification_rate >= 30 && 'text-[var(--color-status-won)]',
                          row.qualification_rate < 10 && 'text-[var(--color-status-lost)]',
                        )}
                      >
                        {row.qualification_rate}%
                      </span>
                    </Td>
                  </Tr>
                ))}
              </tbody>
            </Table>
          </TableWrap>
        )}
      </Card>
    </div>
  )
}

/** Seconds as something a person reads: "12 دقيقة" rather than 720. */
function formatDuration(seconds: number | null, t: (key: string) => string): string {
  if (seconds === null) return '—'
  if (seconds < 90) return `${Math.round(seconds)} ${t('units.seconds')}`
  if (seconds < 5400) return `${Math.round(seconds / 60)} ${t('units.minutes')}`
  if (seconds < 172800) return `${Math.round(seconds / 3600)} ${t('units.hours')}`

  return `${Math.round(seconds / 86400)} ${t('units.days')}`
}
