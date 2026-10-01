import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BarChart3, Download } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiDownload, apiGet } from '@/lib/api'
import { useApiError } from '@/hooks/useApiError'
import { formatNumber } from '@/lib/format'
import {
  Button, Card, EmptyState, ErrorState, Input, PageHeader, SkeletonTable,
  Table, TableWrap, Td, Th, Tr,
} from '@/components/ui'
import type { ReportRow } from '@/types/api'
import { cn } from '@/lib/cn'

const TYPES = [
  { key: 'platform', labelKey: 'reports.byPlatform' },
  { key: 'campaign', labelKey: 'reports.byCampaign' },
  { key: 'project', labelKey: 'reports.byProject' },
  { key: 'sales_rep', labelKey: 'reports.bySalesRep' },
  { key: 'status', labelKey: 'reports.byStatus' },
  { key: 'lost_reason', labelKey: 'reports.byLostReason' },
  { key: 'source', labelKey: 'reports.bySource' },
] as const

export function ReportsPage() {
  const { t, i18n } = useTranslation()
  const translateError = useApiError()

  const [type, setType] = useState<string>('platform')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  const params = {
    type,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
  }

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['reports', params],
    queryFn: () =>
      apiGet<{ type: string; rows: ReportRow[]; totals: Omit<ReportRow, 'key' | 'label'> }>('/reports', params),
    placeholderData: (previous) => previous,
  })

  const rows = data?.rows ?? []
  const max = Math.max(...rows.map((row) => row.total), 1)

  return (
    <>
      <PageHeader
        title={t('reports.title')}
        subtitle={t('reports.subtitle')}
        actions={
          <Button
            variant="outline"
            icon={<Download className="size-4" />}
            disabled={rows.length === 0}
            onClick={() => void apiDownload('/reports/export', params, `report-${type}.csv`)}
          >
            {t('reports.exportCsv')}
          </Button>
        }
      />

      <Card className="overflow-hidden">
        <div className="scroll-x flex items-center gap-1 border-b border-[var(--color-border)] p-2">
          {TYPES.map(({ key, labelKey }) => (
            <button
              key={key}
              type="button"
              onClick={() => setType(key)}
              className={cn(
                'rounded-lg px-3 py-1.5 text-[0.8125rem] font-medium whitespace-nowrap transition-colors',
                type === key
                  ? 'bg-[var(--brand-soft)] text-[var(--brand)]'
                  : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)]',
              )}
            >
              {t(labelKey)}
            </button>
          ))}
        </div>

        <div className="flex flex-wrap items-end gap-3 border-b border-[var(--color-border)] p-3">
          <Input
            type="date"
            label={t('common.from')}
            value={dateFrom}
            onChange={(e) => setDateFrom(e.target.value)}
            containerClassName="w-auto"
            className="w-auto"
          />
          <Input
            type="date"
            label={t('common.to')}
            value={dateTo}
            onChange={(e) => setDateTo(e.target.value)}
            containerClassName="w-auto"
            className="w-auto"
          />
          {(dateFrom || dateTo) && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setDateFrom('')
                setDateTo('')
              }}
            >
              {t('common.clearFilters')}
            </Button>
          )}
        </div>

        {isError ? (
          <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
        ) : isLoading ? (
          <SkeletonTable rows={6} cols={6} />
        ) : rows.length === 0 ? (
          <EmptyState icon={<BarChart3 className="size-6" />} title={t('reports.empty')} />
        ) : (
          <TableWrap>
            <Table>
              <thead>
                <tr>
                  <Th>{t('reports.label')}</Th>
                  <Th className="w-32">{t('reports.total')}</Th>
                  <Th className="hidden sm:table-cell">{t('reports.qualifiedCol')}</Th>
                  <Th className="hidden md:table-cell">{t('reports.appointmentsCol')}</Th>
                  <Th>{t('reports.wonCol')}</Th>
                  <Th className="hidden sm:table-cell">{t('reports.lostCol')}</Th>
                  <Th>{t('reports.conversion')}</Th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <Tr key={row.key}>
                    <Td className="max-w-56 truncate font-medium">
                      {type === 'platform'
                        ? t(`platforms.${row.key}`, { defaultValue: row.label })
                        : type === 'status'
                          ? t(`status.${row.key}`, { defaultValue: row.label })
                          : row.label}
                    </Td>
                    <Td>
                      {/* Inline bar makes the distribution readable without a chart. */}
                      <div className="flex items-center gap-2">
                        <span className="tabular w-10 shrink-0 font-medium">
                          {formatNumber(row.total, i18n.language)}
                        </span>
                        <span className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-[var(--color-surface-sunken)]">
                          <span
                            className="block h-full rounded-full"
                            style={{ width: `${(row.total / max) * 100}%`, background: 'var(--brand)' }}
                          />
                        </span>
                      </div>
                    </Td>
                    <Td className="tabular hidden sm:table-cell">{formatNumber(row.qualified, i18n.language)}</Td>
                    <Td className="tabular hidden md:table-cell">{formatNumber(row.appointments, i18n.language)}</Td>
                    <Td className="tabular font-medium text-emerald-700">{formatNumber(row.won, i18n.language)}</Td>
                    <Td className="tabular hidden sm:table-cell">{formatNumber(row.lost, i18n.language)}</Td>
                    <Td className="tabular font-medium">{row.conversion_rate}%</Td>
                  </Tr>
                ))}
              </tbody>
              {data?.totals && (
                <tfoot>
                  <tr className="bg-[var(--color-surface-muted)] font-semibold">
                    <Td>{t('reports.total')}</Td>
                    <Td className="tabular">{formatNumber(data.totals.total, i18n.language)}</Td>
                    <Td className="tabular hidden sm:table-cell">{formatNumber(data.totals.qualified, i18n.language)}</Td>
                    <Td className="tabular hidden md:table-cell">{formatNumber(data.totals.appointments, i18n.language)}</Td>
                    <Td className="tabular text-emerald-700">{formatNumber(data.totals.won, i18n.language)}</Td>
                    <Td className="tabular hidden sm:table-cell">{formatNumber(data.totals.lost, i18n.language)}</Td>
                    <Td className="tabular">{data.totals.conversion_rate}%</Td>
                  </tr>
                </tfoot>
              )}
            </Table>
          </TableWrap>
        )}
      </Card>
    </>
  )
}
