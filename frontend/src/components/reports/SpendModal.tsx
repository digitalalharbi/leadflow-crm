import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Modal } from '@/components/ui'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { formatCurrency, formatDate } from '@/lib/format'
import { fetchSpend, removeSpend, saveSpend } from '@/features/spend'
import type { CampaignQualityRow } from '@/types/api'

/**
 * Where somebody types what the ads cost.
 *
 * The reports have always counted leads, qualified leads and sales per campaign
 * and never known what any of it cost — so "which campaign is worth scaling"
 * could only be answered by volume, which is the question that flatters the
 * cheapest traffic.
 *
 * Deliberately not waiting for ad-platform credentials. A manager with the
 * figures in front of them can enter a week in a minute, and the same rows are
 * what an insights sync writes later.
 */
export function SpendModal({
  open,
  onClose,
  campaigns,
}: {
  open: boolean
  onClose: () => void
  /** The campaigns the reports already know about, so nobody retypes a name. */
  campaigns: CampaignQualityRow[]
}) {
  const { t, i18n } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const queryClient = useQueryClient()

  const [campaign, setCampaign] = useState('')
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [amount, setAmount] = useState('')

  const { data } = useQuery({
    queryKey: ['campaign-spend'],
    queryFn: () => fetchSpend(),
    enabled: open,
  })

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['campaign-spend'] })
    // The costs live on the campaign report, so it has to be refetched too or
    // the figure somebody just entered is not the figure they see.
    void queryClient.invalidateQueries({ queryKey: ['reports-campaigns'] })
  }

  const save = useMutation({
    mutationFn: () => {
      const [platform, name] = campaign.split('|')

      return saveSpend([{
        platform,
        campaign_name: name,
        spend_date: date,
        amount: Number(amount),
      }])
    },
    onSuccess: () => {
      setAmount('')
      toast(t('campaignQuality.spendSaved'), 'success')
      invalidate()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const remove = useMutation({
    mutationFn: (id: number) => removeSpend(id),
    onSuccess: invalidate,
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const currency = data?.currency ?? 'SAR'
  const ready = campaign !== '' && amount !== '' && Number(amount) >= 0

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('campaignQuality.spendTitle')}
      description={t('campaignQuality.spendHint')}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose}>{t('common.close')}</Button>
          <Button loading={save.isPending} disabled={!ready} onClick={() => save.mutate()} data-testid="save-spend">
            {t('common.save')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-3">
          <label className="block">
            <span className="t-small mb-1 block font-medium">{t('campaignQuality.campaign')}</span>
            {/*
              Picked from the campaigns the reports already know about rather
              than typed. A spend row whose name is one character off the lead's
              never matches it, and the screen shows a dash with no hint why.
            */}
            <select
              value={campaign}
              onChange={(event) => setCampaign(event.target.value)}
              data-testid="spend-campaign"
              className="w-full rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-[0.875rem]"
            >
              <option value="">—</option>
              {campaigns.map((row) => (
                <option key={`${row.platform}|${row.campaign}`} value={`${row.platform}|${row.campaign}`}>
                  {row.campaign} · {t(`platforms.${row.platform}`, { defaultValue: row.platform })}
                </option>
              ))}
            </select>
          </label>

          <label className="block">
            <span className="t-small mb-1 block font-medium">{t('campaignQuality.spendDate')}</span>
            <input
              type="date"
              value={date}
              onChange={(event) => setDate(event.target.value)}
              data-testid="spend-date"
              className="w-full rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-[0.875rem]"
            />
          </label>

          <label className="block">
            <span className="t-small mb-1 block font-medium">
              {t('campaignQuality.spendAmount')} ({currency})
            </span>
            <input
              type="number"
              min="0"
              step="0.01"
              dir="ltr"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              data-testid="spend-amount"
              className="tabular w-full rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-[0.875rem]"
            />
          </label>
        </div>

        {data && data.spends.length === 0 ? (
          <p className="t-caption">{t('campaignQuality.spendEmpty')}</p>
        ) : (
          <ul className="divide-y divide-[var(--color-line)]" data-testid="spend-list">
            {data?.spends.map((row) => (
              <li key={row.id} className="flex items-center justify-between gap-3 py-2">
                <div className="min-w-0">
                  <p className="t-small truncate font-medium">{row.campaign_name}</p>
                  <p className="t-caption">
                    {formatDate(row.spend_date, i18n.language)} ·{' '}
                    {t(`platforms.${row.platform}`, { defaultValue: row.platform })}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  <span className="tabular text-[0.875rem]" dir="ltr">
                    {formatCurrency(row.amount, row.currency, i18n.language)}
                  </span>
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('campaignQuality.spendRemove')}
                    onClick={() => remove.mutate(row.id)}
                  >
                    <Trash2 className="size-4" />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Modal>
  )
}
