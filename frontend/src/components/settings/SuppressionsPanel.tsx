import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, Search, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardBody, CardHeader, EmptyState, Input, Select, Skeleton } from '@/components/ui'
import { addSuppression, fetchSuppressions, removeSuppression, type SuppressionChannel } from '@/features/suppressions'
import { useApiError } from '@/hooks/useApiError'
import { useDebounced } from '@/hooks/useDebounced'
import { formatLastSeen } from '@/lib/format'
import { useUi } from '@/stores/ui'

/**
 * The company's do-not-contact list: every number and address that asked to stop, bounced or was added by hand.
 * Adding one opts out the leads that already carry it; removing one is recorded, and is the only way back.
 */
export function SuppressionsPanel() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [q, setQ] = useState('')
  const search = useDebounced(q.trim(), 300)
  const [channel, setChannel] = useState<SuppressionChannel>('phone')
  const [value, setValue] = useState('')
  const [note, setNote] = useState('')

  const list = useQuery({ queryKey: ['suppressions', search], queryFn: () => fetchSuppressions(search || undefined) })
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['suppressions'] })

  const add = useMutation({
    mutationFn: () => addSuppression({ channel, value: value.trim(), note: note.trim() || undefined }),
    onSuccess: () => { setValue(''); setNote(''); refresh(); toast(t('suppressions.added'), 'success') },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const remove = useMutation({
    mutationFn: removeSuppression,
    onSuccess: () => { refresh(); toast(t('suppressions.removed'), 'success') },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (value.trim() !== '') add.mutate()
  }

  return (
    <Card data-testid="suppressions">
      <CardHeader title={t('suppressions.title')} description={t('suppressions.hint')} />
      <CardBody className="space-y-4">
        <form className="grid gap-2 sm:grid-cols-[8rem_1fr_1fr_auto]" onSubmit={submit} data-testid="suppression-form">
          <Select value={channel} onChange={(e) => setChannel(e.target.value as SuppressionChannel)} aria-label={t('suppressions.channel')} data-testid="suppression-channel">
            <option value="phone">{t('suppressions.channels.phone')}</option>
            <option value="email">{t('suppressions.channels.email')}</option>
          </Select>
          <Input
            dir="ltr"
            value={value}
            maxLength={191}
            onChange={(e) => setValue(e.target.value)}
            placeholder={channel === 'phone' ? '+9665XXXXXXXX' : 'name@example.com'}
            aria-label={t('suppressions.value')}
            data-testid="suppression-value"
          />
          <Input value={note} maxLength={255} onChange={(e) => setNote(e.target.value)} placeholder={t('suppressions.note')} aria-label={t('suppressions.note')} />
          <Button type="submit" icon={<Ban className="size-4" />} loading={add.isPending} disabled={value.trim() === ''} data-testid="suppression-add">
            {t('suppressions.add')}
          </Button>
        </form>

        <div className="relative">
          <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[var(--color-ink-muted)]" aria-hidden />
          <Input className="ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('suppressions.search')} aria-label={t('suppressions.search')} data-testid="suppression-search" />
        </div>

        {list.isLoading ? (
          <Skeleton className="h-40" />
        ) : (list.data?.data.length ?? 0) === 0 ? (
          <EmptyState icon={<Ban className="size-6" />} title={t('suppressions.emptyTitle')} body={t('suppressions.emptyBody')} />
        ) : (
          <>
            <p className="t-caption" data-testid="suppression-total">{t('suppressions.total', { count: list.data?.total ?? 0 })}</p>
            <ul className="divide-y divide-[var(--color-line)] rounded-xl border border-[var(--color-line)]" data-testid="suppression-list">
              {list.data?.data.map((row) => (
                <li key={row.id} className="flex flex-wrap items-center gap-2 px-3 py-2" data-testid="suppression-row">
                  <Badge tone="neutral">{t(`suppressions.channels.${row.channel}`)}</Badge>
                  <span className="min-w-0 flex-1 truncate font-medium tabular" dir="ltr">{row.value}</span>
                  <Badge tone={row.reason === 'manual' ? 'info' : 'warning'}>{t(`suppressions.reasons.${row.reason}`)}</Badge>
                  {row.note && <span className="t-caption max-w-[16rem] truncate">{row.note}</span>}
                  {row.created_at && <span className="t-caption">{formatLastSeen(row.created_at, i18n.language)}</span>}
                  <Button
                    size="sm"
                    variant="ghost"
                    icon={<Trash2 className="size-3.5" />}
                    loading={remove.isPending && remove.variables === row.id}
                    onClick={() => { if (window.confirm(t('suppressions.confirmRemove', { value: row.value }))) remove.mutate(row.id) }}
                    data-testid="suppression-remove"
                  >
                    {t('suppressions.remove')}
                  </Button>
                </li>
              ))}
            </ul>
          </>
        )}
      </CardBody>
    </Card>
  )
}
