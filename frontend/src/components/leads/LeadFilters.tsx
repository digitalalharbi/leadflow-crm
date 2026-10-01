import { useTranslation } from 'react-i18next'
import { Search, X } from 'lucide-react'
import { Button, Input, Select } from '@/components/ui'
import type { LeadFilters as Filters } from '@/features/leads'
import type { Project, Ref } from '@/types/api'

const STATUSES = ['new', 'contacted', 'qualified', 'appointment', 'won', 'lost'] as const
const TEMPERATURES = ['hot', 'warm', 'cold'] as const
const PLATFORMS = ['meta', 'tiktok', 'snapchat', 'google_ads', 'linkedin', 'website', 'webhook', 'manual'] as const

/** Each select is single-value for simplicity; the API accepts arrays. */
function single(value: string): string[] | undefined {
  return value ? [value] : undefined
}

export function LeadFiltersBar({
  filters,
  onChange,
  onReset,
  projects,
  users,
  showAssignee,
  search,
  onSearchChange,
}: {
  filters: Filters
  onChange: (next: Partial<Filters>) => void
  onReset: () => void
  projects: Project[]
  users: Ref[]
  showAssignee: boolean
  search: string
  onSearchChange: (value: string) => void
}) {
  const { t } = useTranslation()

  const hasFilters =
    Boolean(search) ||
    Boolean(filters.status?.length) ||
    Boolean(filters.temperature?.length) ||
    Boolean(filters.platform?.length) ||
    Boolean(filters.project_id) ||
    Boolean(filters.assigned_to) ||
    Boolean(filters.follow_up)

  return (
    <div className="flex flex-wrap items-center gap-2 border-b border-[var(--color-border)] p-3">
      <div className="relative w-full min-w-0 sm:w-auto sm:flex-1 sm:max-w-xs">
        <Search
          className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[var(--color-ink-subtle)]"
          aria-hidden
        />
        <Input
          value={search}
          onChange={(e) => onSearchChange(e.target.value)}
          placeholder={`${t('common.search')}…`}
          className="ps-9"
          aria-label={t('common.search')}
        />
      </div>

      <Select
        value={filters.status?.[0] ?? ''}
        onChange={(e) => onChange({ status: single(e.target.value), page: 1 })}
        className="h-10 w-auto min-w-32"
        aria-label={t('leads.status')}
      >
        <option value="">{t('leads.status')}</option>
        {STATUSES.map((s) => (
          <option key={s} value={s}>{t(`status.${s}`)}</option>
        ))}
      </Select>

      <Select
        value={filters.temperature?.[0] ?? ''}
        onChange={(e) => onChange({ temperature: single(e.target.value), page: 1 })}
        className="h-10 w-auto min-w-28"
        aria-label={t('leads.temperature')}
      >
        <option value="">{t('leads.temperature')}</option>
        {TEMPERATURES.map((s) => (
          <option key={s} value={s}>{t(`temperature.${s}`)}</option>
        ))}
      </Select>

      <Select
        value={filters.platform?.[0] ?? ''}
        onChange={(e) => onChange({ platform: single(e.target.value), page: 1 })}
        className="h-10 w-auto min-w-32"
        aria-label={t('leads.platform')}
      >
        <option value="">{t('leads.platform')}</option>
        {PLATFORMS.map((s) => (
          <option key={s} value={s}>{t(`platforms.${s}`)}</option>
        ))}
      </Select>

      {projects.length > 0 && (
        <Select
          value={filters.project_id ?? ''}
          onChange={(e) => onChange({ project_id: e.target.value || undefined, page: 1 })}
          className="h-10 w-auto min-w-36"
          aria-label={t('leads.project')}
        >
          <option value="">{t('leads.project')}</option>
          {projects.map((p) => (
            <option key={p.id} value={p.id}>{p.name}</option>
          ))}
        </Select>
      )}

      {showAssignee && users.length > 0 && (
        <Select
          value={filters.assigned_to ?? ''}
          onChange={(e) => onChange({ assigned_to: e.target.value || undefined, page: 1 })}
          className="h-10 w-auto min-w-36"
          aria-label={t('leads.assignedTo')}
        >
          <option value="">{t('leads.assignedTo')}</option>
          <option value="unassigned">{t('common.unassigned')}</option>
          {users.map((u) => (
            <option key={u.id} value={u.id}>{u.name}</option>
          ))}
        </Select>
      )}

      <Select
        value={filters.follow_up ?? ''}
        onChange={(e) => onChange({ follow_up: e.target.value || undefined, page: 1 })}
        className="h-10 w-auto min-w-32"
        aria-label={t('leads.nextFollowUp')}
      >
        <option value="">{t('leads.nextFollowUp')}</option>
        <option value="overdue">{t('followUps.overdue')}</option>
        <option value="today">{t('followUps.today')}</option>
        <option value="upcoming">{t('followUps.upcoming')}</option>
      </Select>

      {hasFilters && (
        <Button variant="ghost" size="sm" onClick={onReset} icon={<X className="size-4" />}>
          {t('common.clearFilters')}
        </Button>
      )}
    </div>
  )
}
