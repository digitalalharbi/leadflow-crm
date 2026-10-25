import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Drawer, Skeleton } from '@/components/ui'
import { fetchActivity, fetchRevisions } from '@/features/reportLibrary'
import type { ReportShare } from '@/features/reportShares'
import { formatLastSeen } from '@/lib/format'

/** How the report was set up over time, and what happened to it. No analytics are kept in the history — a live report shows today’s numbers. */
export function HistoryPanel({ share, onClose }: { share: ReportShare | null; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const revisions = useQuery({ queryKey: ['report-revisions', share?.id], queryFn: () => fetchRevisions(share!.id), enabled: share !== null })
  const activity = useQuery({ queryKey: ['report-activity', share?.id], queryFn: () => fetchActivity(share!.id), enabled: share !== null })

  return (
    <Drawer open={share !== null} onClose={onClose} title={t('historyPanel.title', { name: share?.name ?? '' })} size="lg" testId="history-panel">
      <div className="space-y-6">
        <section>
          <h3 className="t-small mb-2 font-semibold">{t('historyPanel.revisions')}</h3>
          {revisions.isLoading || !revisions.data ? <Skeleton className="h-24" /> : (
            <ol className="space-y-1.5" data-testid="revision-list">
              {revisions.data.revisions.map((r) => (
                <li key={r.revision} className="rounded-lg bg-[var(--color-surface-muted)] px-3 py-2 text-[0.875rem]" data-testid="revision-item">
                  <span className="font-semibold tabular-nums">{t('library.revision', { n: r.revision })}</span>
                  <span className="t-caption block">{r.changed.length ? t('historyPanel.changed', { what: r.changed.map((k) => t(`historyPanel.field.${k}`, { defaultValue: k })).join('، ') }) : ''}</span>
                  <span className="t-caption block">{r.by ? t('historyPanel.by', { name: r.by }) : t('historyPanel.system')} · {formatLastSeen(r.at, i18n.language)}</span>
                </li>
              ))}
            </ol>
          )}
        </section>
        <section>
          <h3 className="t-small mb-2 font-semibold">{t('historyPanel.activity')}</h3>
          {activity.isLoading || !activity.data ? <Skeleton className="h-24" /> : (
            <ul className="space-y-1" data-testid="activity-list">
              {activity.data.activity.map((a, i) => (
                <li key={i} className="flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-1.5 text-[0.875rem] odd:bg-[var(--color-surface-muted)]" data-testid="activity-item" data-action={a.action}>
                  <span>{t(`historyPanel.action.${a.action}`, { defaultValue: a.action })}</span>
                  <span className="t-caption">{a.by ?? t('historyPanel.system')} · {formatLastSeen(a.at, i18n.language)}</span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </Drawer>
  )
}
