import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, Link2, Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Drawer, Input, Skeleton } from '@/components/ui'
import { RingProgress } from '@/components/visual'
import { fetchStatement, recordPayment, removePayment, statementLink, type Partner } from '@/features/partners'
import { useApiError } from '@/hooks/useApiError'
import { formatDate, formatMoneyCompact, formatNumber } from '@/lib/format'
import { useUi } from '@/stores/ui'

/**
 * A partner's statement: what they brought, what they are owed on the terms agreed, what has been
 * paid and what is left — the deals and payments behind each number one fold down — and the link
 * the partner themselves can open, which carries their own figures and nobody else's.
 */
export function PartnerStatementDrawer({ partner, onClose }: { partner: Partner | null; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [amount, setAmount] = useState('')
  const [reference, setReference] = useState('')
  const [paidOn, setPaidOn] = useState('')
  const [link, setLink] = useState<string | null>(null)

  const { data, isLoading } = useQuery({ queryKey: ['partner-statement', partner?.id], queryFn: () => fetchStatement(partner!.id), enabled: partner !== null })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['partner-statement', partner?.id] })
    void queryClient.invalidateQueries({ queryKey: ['partners'] })
  }

  const pay = useMutation({
    mutationFn: () => recordPayment(partner!.id, { amount: Number(amount), reference: reference || undefined, paid_on: paidOn || undefined }),
    onSuccess: () => { setAmount(''); setReference(''); setPaidOn(''); toast(t('partners.statement.paymentSaved'), 'success'); refresh() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const remove = useMutation({
    mutationFn: (id: string) => removePayment(partner!.id, id),
    onSuccess: () => { toast(t('partners.statement.paymentRemoved'), 'success'); refresh() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const share = useMutation({
    mutationFn: () => statementLink(partner!.id),
    onSuccess: async ({ share: s }) => {
      setLink(s.url)
      try {
        await navigator.clipboard.writeText(s.url)
        toast(t('share.copied'), 'success')
      } catch {
        toast(s.url, 'info')
      }
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const money = (v: number | null | undefined) => formatMoneyCompact(v ?? null, 'SAR', i18n.language)
  const ledger = data?.ledger
  const paidShare = ledger && ledger.due ? Math.min(100, Math.round((ledger.paid / ledger.due) * 100)) : 0

  return (
    <Drawer open={partner !== null} onClose={onClose} title={partner ? `${t('partners.statement.title')} — ${partner.name}` : ''} size="lg" testId="partner-statement">
      {isLoading || !data || !ledger ? <Skeleton className="h-64" /> : (
        <div className="space-y-5">
          <section className="flex flex-wrap items-center gap-5" data-testid="statement-ledger">
            <RingProgress size={92} thickness={10} value={paidShare} tone="good" label={t('partners.statement.paidShare')}>
              <span className="text-[1rem] font-bold tabular-nums" dir="ltr">{paidShare}%</span>
            </RingProgress>
            <div className="grid flex-1 grid-cols-3 gap-2 text-center">
              {([['due', ledger.due], ['paid', ledger.paid], ['balance', ledger.balance]] as const).map(([key, value]) => (
                <div key={key} className="rounded-xl bg-[var(--color-surface-muted)] p-2" data-testid={`statement-${key}`}>
                  <p className="t-metric" dir="ltr">{value === null ? '—' : money(value)}</p>
                  <p className="t-caption">{t(`partners.statement.${key}`)}</p>
                </div>
              ))}
            </div>
          </section>
          <p className="t-caption" data-testid="statement-terms">
            {data.terms.agreed
              ? data.terms.type === 'fixed' ? t('partners.statement.termsFixed', { amount: money(data.terms.fixed) }) : t('partners.statement.termsPercent', { percent: data.terms.percent })
              : t('partners.noCommission')}
          </p>

          <dl className="grid grid-cols-3 gap-1.5 text-center sm:grid-cols-6" data-testid="statement-funnel">
            {([['leads', data.stats.leads], ['qualified', data.stats.qualified], ['interested', data.stats.interested], ['visits', data.stats.visits], ['reservations', data.stats.reservations], ['won', data.stats.won]] as const).map(([key, value]) => (
              <div key={key} className="rounded-lg bg-[var(--color-surface-muted)] px-0.5 py-1">
                <dd className="text-[1.0625rem] font-extrabold tabular-nums">{formatNumber(value, i18n.language)}</dd>
                <dt className="text-[0.75rem] text-[var(--color-ink-subtle)]">{t(`partners.stat.${key}`)}</dt>
              </div>
            ))}
          </dl>

          <section className="space-y-2">
            <h3 className="t-small font-semibold">{t('partners.statement.record')}</h3>
            <div className="flex flex-wrap items-end gap-2">
              <Input label={t('partners.statement.amount')} type="number" min="0" dir="ltr" value={amount} onChange={(e) => setAmount(e.target.value)} data-testid="payment-amount" className="w-36" />
              <Input label={t('partners.statement.reference')} value={reference} onChange={(e) => setReference(e.target.value)} data-testid="payment-reference" className="w-44" />
              <Input label={t('partners.statement.paidOn')} type="date" value={paidOn} onChange={(e) => setPaidOn(e.target.value)} className="w-40" />
              <Button icon={<Plus className="size-4" />} disabled={!(Number(amount) > 0)} loading={pay.isPending} onClick={() => pay.mutate()} data-testid="payment-save">{t('partners.statement.add')}</Button>
            </div>
            <ul className="space-y-1" data-testid="payments">
              {data.commission_payments.length === 0 && <li className="t-caption">{t('partners.statement.noPayments')}</li>}
              {data.commission_payments.map((p) => (
                <li key={p.id} className="flex items-center justify-between gap-2 rounded-lg bg-[var(--color-surface-muted)] px-3 py-1.5 text-[0.9375rem]" data-testid="payment-row">
                  <span className="tabular-nums" dir="ltr">{money(p.amount)}</span>
                  <span className="t-caption min-w-0 flex-1 truncate">{[formatDate(p.paid_on, i18n.language), p.reference].filter(Boolean).join(' · ')}</span>
                  <Button variant="ghost" size="icon" aria-label={t('common.delete')} icon={<Trash2 className="size-4" />} onClick={() => remove.mutate(p.id)} />
                </li>
              ))}
            </ul>
          </section>

          <details className="rounded-xl border border-[var(--color-line)] px-3 py-2" data-testid="statement-deals">
            <summary className="t-small cursor-pointer font-semibold">{t('partners.statement.deals', { count: data.deals.length })}</summary>
            <ul className="mt-2 space-y-1">
              {data.deals.map((d) => (
                <li key={d.lead_id} className="flex items-center justify-between gap-2 text-[0.9375rem]">
                  <span className="min-w-0 flex-1 truncate">{d.lead}</span>
                  <span className="t-caption">{d.won_at ? formatDate(d.won_at, i18n.language) : ''}</span>
                  <span className="tabular-nums" dir="ltr">{money(d.value)}</span>
                  <span className="tabular-nums font-semibold" dir="ltr">{d.commission === null ? '—' : money(d.commission)}</span>
                </li>
              ))}
            </ul>
          </details>

          <section className="space-y-2 rounded-xl bg-[var(--brand-soft)] p-3">
            <h3 className="t-small font-semibold">{t('partners.statement.linkTitle')}</h3>
            <p className="t-caption">{t('partners.statement.linkHint')}</p>
            <div className="flex flex-wrap items-center gap-2">
              <Button icon={<Link2 className="size-4" />} loading={share.isPending} onClick={() => share.mutate()} data-testid="statement-link">{t('partners.statement.linkButton')}</Button>
              {link && (
                <button type="button" onClick={() => void navigator.clipboard?.writeText(link)} className="pressable inline-flex min-w-0 items-center gap-1.5 rounded-full bg-[var(--color-surface)] px-3 py-1 text-[0.875rem]" data-testid="statement-link-url">
                  <Copy className="size-4 shrink-0" aria-hidden /><span className="truncate" dir="ltr">{link}</span>
                </button>
              )}
            </div>
          </section>
        </div>
      )}
    </Drawer>
  )
}
