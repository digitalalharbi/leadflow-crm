import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarDays, Copy, RefreshCw, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, CardHeader, Input } from '@/components/ui'
import { useApiError } from '@/hooks/useApiError'
import { apiDelete, apiGet, apiPost } from '@/lib/api'
import { formatLastSeen } from '@/lib/format'
import { useUi } from '@/stores/ui'

/**
 * The person's visits in the calendar on their phone: a private address to subscribe to in Google, Apple or Outlook.
 * The address is shown once, when it is made; making a new one retires the old.
 */
export function CalendarFeedCard() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [url, setUrl] = useState<string | null>(null)

  const feed = useQuery({ queryKey: ['calendar-feed'], queryFn: () => apiGet<{ enabled: boolean; last_fetched_at: string | null }>('/auth/calendar-feed') })

  const rotate = useMutation({
    mutationFn: () => apiPost<{ url: string }>('/auth/calendar-feed/rotate'),
    onSuccess: (data) => { setUrl(data.url); void queryClient.invalidateQueries({ queryKey: ['calendar-feed'] }) },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const disable = useMutation({
    mutationFn: () => apiDelete('/auth/calendar-feed'),
    onSuccess: () => { setUrl(null); void queryClient.invalidateQueries({ queryKey: ['calendar-feed'] }) },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const enabled = feed.data?.enabled ?? false

  return (
    <Card data-testid="calendar-feed">
      <CardHeader title={t('calendar.title')} description={t('calendar.hint')} />
      <CardBody className="space-y-3">
        {url && (
          <div className="flex gap-2">
            <Input readOnly value={url} dir="ltr" aria-label={t('calendar.address')} data-testid="calendar-feed-url" onFocus={(e) => e.target.select()} />
            <Button
              variant="outline"
              icon={<Copy className="size-4" />}
              onClick={() => void navigator.clipboard?.writeText(url).then(() => toast(t('calendar.copied'), 'success'))}
            >
              {t('calendar.copy')}
            </Button>
          </div>
        )}
        {url && <p className="t-caption">{t('calendar.onceOnly')}</p>}
        {enabled && !url && (
          <p className="t-caption" data-testid="calendar-feed-state">
            {feed.data?.last_fetched_at ? t('calendar.lastRead', { when: formatLastSeen(feed.data.last_fetched_at, i18n.language) }) : t('calendar.notReadYet')}
          </p>
        )}
        <div className="flex flex-wrap gap-2">
          <Button icon={enabled ? <RefreshCw className="size-4" /> : <CalendarDays className="size-4" />} loading={rotate.isPending} onClick={() => rotate.mutate()} data-testid="calendar-feed-create">
            {enabled ? t('calendar.newAddress') : t('calendar.create')}
          </Button>
          {enabled && (
            <Button variant="ghost" icon={<X className="size-4" />} loading={disable.isPending} onClick={() => disable.mutate()} data-testid="calendar-feed-disable">
              {t('calendar.disable')}
            </Button>
          )}
        </div>
      </CardBody>
    </Card>
  )
}
