import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Filter, Search, X } from 'lucide-react'
import { Button, Input, Select } from '@/components/ui'
import { cn } from '@/lib/cn'
import type { LeadFilters as Filters } from '@/features/leads'
import type { Project, Ref } from '@/types/api'

const STATUSES = ['new', 'contacted', 'qualified', 'appointment', 'won', 'lost'] as const
const TEMPERATURES = ['hot', 'warm', 'cold'] as const
const PLATFORMS = ['meta', 'tiktok', 'snapchat', 'google_ads', 'linkedin', 'website', 'webhook', 'manual'] as const
const FOLLOW_UPS = ['today', 'overdue', 'upcoming'] as const

/** Each select is single-value; the API accepts arrays. */
function single(value: string): string[] | undefined {
  return value ? [value] : undefined
}

/**
 * Search, then chips, then the controls behind a toggle.
 *
 * A row of seven always-open dropdowns is the default admin-template answer and
 * it costs the list a third of its vertical space before anyone has filtered
 * anything. What matters at rest is *what is currently filtered* — so that is
 * what stays visible, as removable chips, and the controls fold away.
 */
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
  const [open, setOpen] = useState(false)

  // Each active filter, with the way to remove just that one.
  const chips: Array<{ key: string; label: string; clear: () => void }> = []

  if (filters.status?.[0]) {
    chips.push({
      key: 'status',
      label: t(`status.${filters.status[0]}`),
      clear: () => onChange({ status: undefined }),
    })
  }

  if (filters.temperature?.[0]) {
    chips.push({
      key: 'temperature',
      label: t(`temperature.${filters.temperature[0]}`),
      clear: () => onChange({ temperature: undefined }),
    })
  }

  if (filters.platform?.[0]) {
    chips.push({
      key: 'platform',
      label: t(`platforms.${filters.platform[0]}`, { defaultValue: filters.platform[0] }),
      clear: () => onChange({ platform: undefined }),
    })
  }

  if (filters.project_id) {
    chips.push({
      key: 'project',
      label: projects.find((p) => p.id === filters.project_id)?.name ?? t('leads.project'),
      clear: () => onChange({ project_id: undefined }),
    })
  }

  if (filters.assigned_to) {
    chips.push({
      key: 'assignee',
      label: users.find((u) => u.id === filters.assigned_to)?.name ?? t('leads.assignedTo'),
      clear: () => onChange({ assigned_to: undefined }),
    })
  }

  if (filters.follow_up) {
    chips.push({
      key: 'follow_up',
      label: t(`followUps.${filters.follow_up}`),
      clear: () => onChange({ follow_up: undefined }),
    })
  }

  if (filters.campaign_name) {
    chips.push({
      key: 'campaign',
      label: filters.campaign_name,
      clear: () => onChange({ campaign_name: undefined }),
    })
  }

  if (filters.awaiting_contact) {
    chips.push({
      key: 'awaiting',
      label: t('dashboard.awaitingContact'),
      clear: () => onChange({ awaiting_contact: undefined }),
    })
  }

  if (filters.replied) {
    chips.push({
      key: 'replied',
      label: t('campaignQuality.replied'),
      clear: () => onChange({ replied: undefined }),
    })
  }

  const hasAny = chips.length > 0 || Boolean(search)

  return (
    <div className="border-b border-[var(--color-line)]">
      <div className="flex flex-wrap items-center gap-2 p-3">
        <div className="relative w-full min-w-0 sm:w-auto sm:max-w-sm sm:flex-1">
          <Search
            className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[var(--color-ink-subtle)]"
            aria-hidden
          />
          <Input
            value={search}
            onChange={(e) => onSearchChange(e.target.value)}
            placeholder={t('leads.searchPlaceholder')}
            className="ps-9"
            aria-label={t('common.search')}
            data-testid="lead-search"
          />
        </div>

        <Button
          variant={open ? 'secondary' : 'outline'}
          size="md"
          onClick={() => setOpen((v) => !v)}
          aria-expanded={open}
          data-testid="toggle-filters"
        >
          <Filter className="size-4" />
          {t('common.filter')}
          {chips.length > 0 && (
            <span className="tabular ms-0.5 rounded-full bg-[var(--brand)] px-1.5 text-[0.6875rem] text-[var(--brand-contrast)]">
              {chips.length}
            </span>
          )}
        </Button>

        {hasAny && (
          <Button variant="ghost" size="md" onClick={onReset} data-testid="clear-filters">
            {t('common.clearFilters')}
          </Button>
        )}
      </div>

      {/* What is currently filtered, always visible, each removable on its own. */}
      {chips.length > 0 && (
        <ul className="flex flex-wrap items-center gap-1.5 px-3 pb-3" data-testid="filter-chips">
          {chips.map((chip) => (
            <li key={chip.key}>
              <button
                type="button"
                onClick={chip.clear}
                className={cn(
                  'inline-flex items-center gap-1.5 rounded-full py-1 ps-2.5 pe-1.5',
                  'bg-[var(--brand-soft)] text-[0.75rem] font-medium text-[var(--brand)]',
                  'transition-colors hover:bg-[color-mix(in_srgb,var(--brand)_18%,transparent)]',
                )}
                data-testid={`chip-${chip.key}`}
              >
                {chip.label}
                <X className="size-3" aria-hidden />
                <span className="sr-only">{t('common.clearFilters')}</span>
              </button>
            </li>
          ))}
        </ul>
      )}

      {open && (
        <div
          className="grid gap-2 border-t border-[var(--color-line)] bg-[var(--color-surface-muted)] p-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
          data-testid="filter-controls"
        >
          <Select
            value={filters.status?.[0] ?? ''}
            onChange={(e) => onChange({ status: single(e.target.value) })}
            aria-label={t('leads.status')}
            data-testid="filter-status"
          >
            <option value="">{t('leads.status')}</option>
            {STATUSES.map((status) => (
              <option key={status} value={status}>{t(`status.${status}`)}</option>
            ))}
          </Select>

          <Select
            value={filters.temperature?.[0] ?? ''}
            onChange={(e) => onChange({ temperature: single(e.target.value) })}
            aria-label={t('leads.temperature')}
            data-testid="filter-temperature"
          >
            <option value="">{t('leads.temperature')}</option>
            {TEMPERATURES.map((temp) => (
              <option key={temp} value={temp}>{t(`temperature.${temp}`)}</option>
            ))}
          </Select>

          <Select
            value={filters.platform?.[0] ?? ''}
            onChange={(e) => onChange({ platform: single(e.target.value) })}
            aria-label={t('leads.platform')}
            data-testid="filter-platform"
          >
            <option value="">{t('leads.platform')}</option>
            {PLATFORMS.map((platform) => (
              <option key={platform} value={platform}>
                {t(`platforms.${platform}`, { defaultValue: platform })}
              </option>
            ))}
          </Select>

          <Select
            value={filters.project_id ?? ''}
            onChange={(e) => onChange({ project_id: e.target.value || undefined })}
            aria-label={t('leads.project')}
            data-testid="filter-project"
          >
            <option value="">{t('leads.project')}</option>
            {projects.map((project) => (
              <option key={project.id} value={project.id}>{project.name}</option>
            ))}
          </Select>

          {showAssignee && (
            <Select
              value={filters.assigned_to ?? ''}
              onChange={(e) => onChange({ assigned_to: e.target.value || undefined })}
              aria-label={t('leads.assignedTo')}
              data-testid="filter-assignee"
            >
              <option value="">{t('leads.assignedTo')}</option>
              {users.map((user) => (
                <option key={user.id} value={user.id}>{user.name}</option>
              ))}
            </Select>
          )}

          <Select
            value={filters.follow_up ?? ''}
            onChange={(e) => onChange({ follow_up: e.target.value || undefined })}
            aria-label={t('leads.nextFollowUp')}
            data-testid="filter-followup"
          >
            <option value="">{t('leads.nextFollowUp')}</option>
            {FOLLOW_UPS.map((bucket) => (
              <option key={bucket} value={bucket}>{t(`followUps.${bucket}`)}</option>
            ))}
          </Select>

          <Input
            type="date"
            value={filters.date_from ?? ''}
            onChange={(e) => onChange({ date_from: e.target.value || undefined })}
            aria-label={t('common.from')}
            data-testid="filter-date-from"
          />

          <Input
            type="date"
            value={filters.date_to ?? ''}
            onChange={(e) => onChange({ date_to: e.target.value || undefined })}
            aria-label={t('common.to')}
            data-testid="filter-date-to"
          />
        </div>
      )}
    </div>
  )
}
