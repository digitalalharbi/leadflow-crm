import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { PaginationMeta } from '@/types/api'
import { Button } from './Button'

/**
 * Server-side pagination controls. Arrows are logical (start/end), so they
 * flip automatically in RTL without any extra code.
 */
export function Pagination({
  meta,
  onPageChange,
}: {
  meta: PaginationMeta | undefined
  onPageChange: (page: number) => void
}) {
  const { t, i18n } = useTranslation()

  if (!meta || meta.last_page <= 1) return null

  const isRtl = i18n.dir() === 'rtl'
  const Prev = isRtl ? ChevronRight : ChevronLeft
  const Next = isRtl ? ChevronLeft : ChevronRight

  return (
    <div
      className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--color-border)] px-5 py-3"
      data-testid="pagination"
    >
      <p className="tabular text-[0.8125rem] text-[var(--color-ink-muted)]">
        {meta.from ?? 0}–{meta.to ?? 0} {t('common.of')} {meta.total} {t('common.results')}
      </p>

      <div className="flex items-center gap-1.5">
        <Button
          variant="outline"
          size="sm"
          disabled={meta.current_page <= 1}
          onClick={() => onPageChange(meta.current_page - 1)}
          icon={<Prev className="size-4" />}
        >
          {t('common.previous')}
        </Button>

        <span className="tabular px-2 text-[0.8125rem] text-[var(--color-ink-muted)]">
          {meta.current_page} / {meta.last_page}
        </span>

        <Button
          variant="outline"
          size="sm"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPageChange(meta.current_page + 1)}
        >
          {t('common.next')}
          <Next className="size-4" aria-hidden />
        </Button>
      </div>
    </div>
  )
}
