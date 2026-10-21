import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { FileText, HandCoins, Link2, Pencil, Plus } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardBody, EmptyState, ErrorState, PageHeader, Select, Skeleton } from '@/components/ui'
import { RingProgress } from '@/components/visual'
import { LinkDrawer } from '@/components/tracking/LinkDrawer'
import { PartnerDrawer } from '@/components/partners/PartnerDrawer'
import { PartnerStatementDrawer } from '@/components/partners/PartnerStatementDrawer'
import { fetchPartners, PARTNER_TYPES, type Partner, type PartnerType } from '@/features/partners'
import { formatLastSeen, formatMoneyCompact, formatNumber } from '@/lib/format'
import { can, useAuth } from '@/stores/auth'

/**
 * Partners: the people outside the team who bring customers — brokers, influencers, affiliates,
 * outside agents. Each has links of their own (and QR codes), and each is credited with the leads
 * their link reached first, all the way down the funnel to what a sale was worth and what is owed.
 */
export function PartnersPage() {
  const { t, i18n } = useTranslation()
  const user = useAuth((s) => s.user)
  const canEdit = can.has(user, 'partners.edit')
  const canCreate = can.has(user, 'partners.create')
  const seesRevenue = can.has(user, 'revenue.view')

  const [type, setType] = useState<'' | PartnerType>('')
  const [days, setDays] = useState(90)
  const [editing, setEditing] = useState<Partner | 'new' | null>(null)
  const [linkFor, setLinkFor] = useState<Partner | null>(null)
  const [statementFor, setStatementFor] = useState<Partner | null>(null)

  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['partners', days], queryFn: () => fetchPartners(days) })
  const partners = (data?.partners ?? []).filter((p) => !type || p.type === type)

  const money = (v: number | null | undefined) => formatMoneyCompact(v ?? null, 'SAR', i18n.language)

  return (
    <>
      <PageHeader
        title={t('partners.title')}
        subtitle={t('partners.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Select value={type} onChange={(e) => setType(e.target.value as '' | PartnerType)} className="filter-pill min-w-32" aria-label={t('partners.type')} data-testid="partner-type-filter">
              <option value="">{t('partners.allTypes')}</option>
              {PARTNER_TYPES.map((p) => <option key={p} value={p}>{t(`partners.types.${p}`)}</option>)}
            </Select>
            <Select value={String(days)} onChange={(e) => setDays(Number(e.target.value))} className="filter-pill min-w-32" aria-label={t('common.date')}>
              {[30, 90, 365].map((d) => <option key={d} value={d}>{t('dashboard.lastDays', { count: d })}</option>)}
            </Select>
            {canCreate && <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')} data-testid="new-partner">{t('partners.new')}</Button>}
          </div>
        }
      />

      {isError ? (
        <Card><ErrorState onRetry={() => void refetch()} /></Card>
      ) : isLoading ? (
        <Skeleton className="h-48" />
      ) : partners.length === 0 ? (
        <Card><CardBody><EmptyState icon={<HandCoins className="size-6" />} title={t('partners.emptyTitle')} body={t('partners.emptyBody')} /></CardBody></Card>
      ) : (
        <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid="partner-list">
          {partners.map((partner) => {
            const s = partner.stats
            const rate = s.leads > 0 ? Math.round((s.won / s.leads) * 100) : 0

            return (
              <li key={partner.id} className="space-y-2.5 rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]" data-testid={`partner-${partner.id}`}>
                <div className="flex items-start justify-between gap-2">
                  <div className="flex min-w-0 items-center gap-2.5">
                    <RingProgress value={rate} size={46} thickness={5} tone={rate >= 10 ? 'good' : 'neutral'} label={t('partners.wonRate')}>
                      <span className="text-[0.8125rem] font-extrabold tabular-nums" dir="ltr">{rate}%</span>
                    </RingProgress>
                    <div className="min-w-0">
                      <h3 className="t-section-title truncate" data-testid="partner-name-text">{partner.name}</h3>
                      <p className="t-caption truncate">{[partner.company, partner.contact_name].filter(Boolean).join(' · ')}</p>
                    </div>
                  </div>
                  <div className="flex flex-col items-end gap-1">
                    <Badge tone="info">{t(`partners.types.${partner.type}`)}</Badge>
                    {!partner.is_active && <Badge tone="neutral">{t('common.inactive')}</Badge>}
                  </div>
                </div>

                {/* The funnel in numbers, the way it reads: clicked, arrived, interested, saw it, held it, bought. */}
                <dl className="grid grid-cols-6 gap-1 text-center" data-testid="partner-funnel">
                  {([['clicks', s.clicks], ['leads', s.leads], ['interested', s.interested], ['visits', s.visits], ['reservations', s.reservations], ['won', s.won]] as const).map(([key, value]) => (
                    <div key={key} className="rounded-lg bg-[var(--color-surface-muted)] px-0.5 py-1" data-testid={`partner-stat-${key}`}>
                      <dd className="text-[1.0625rem] font-extrabold tabular-nums">{formatNumber(value, i18n.language)}</dd>
                      <dt className="text-[0.75rem] text-[var(--color-ink-subtle)]">{t(`partners.stat.${key}`)}</dt>
                    </div>
                  ))}
                </dl>

                {seesRevenue && (
                  <div className="space-y-1.5" data-testid="partner-money">
                    <div className="flex items-center justify-between gap-2 text-[0.9375rem]">
                      <span className="tabular-nums" dir="ltr">{t('partners.revenue')}: <strong>{money(s.revenue)}</strong></span>
                      <span className="tabular-nums" dir="ltr">
                        {partner.commission_type === 'fixed' ? (partner.commission_fixed !== null ? `${money(partner.commission_fixed)} × ${s.won} = ` : '') : partner.commission_percent !== null ? `${partner.commission_percent}% = ` : ''}
                        <strong data-testid="partner-commission-value">{s.commission === null || s.commission === undefined ? t('partners.noCommission') : money(s.commission)}</strong>
                      </span>
                    </div>
                    {partner.ledger && partner.ledger.due !== null && (
                      <dl className="grid grid-cols-3 gap-1 text-center" data-testid="partner-ledger">
                        {([['due', partner.ledger.due], ['paid', partner.ledger.paid], ['balance', partner.ledger.balance]] as const).map(([key, value]) => (
                          <div key={key} className="rounded-lg bg-[var(--color-surface-muted)] px-0.5 py-1" data-testid={`partner-ledger-${key}`}>
                            <dd className="text-[0.9375rem] font-extrabold tabular-nums" dir="ltr">{money(value)}</dd>
                            <dt className="text-[0.75rem] text-[var(--color-ink-subtle)]">{t(`partners.statement.${key}`)}</dt>
                          </div>
                        ))}
                      </dl>
                    )}
                  </div>
                )}

                <div className="flex flex-wrap items-center gap-1.5">
                  {canEdit && <Button size="sm" variant="secondary" icon={<Pencil className="size-3.5" />} onClick={() => setEditing(partner)} data-testid="edit-partner">{t('common.edit')}</Button>}
                  <Button size="sm" variant="outline" icon={<Link2 className="size-3.5" />} onClick={() => setLinkFor(partner)} data-testid="partner-new-link">{t('partners.newLink')}</Button>
                  {seesRevenue && canEdit && <Button size="sm" variant="outline" icon={<FileText className="size-3.5" />} onClick={() => setStatementFor(partner)} data-testid="partner-statement-open">{t('partners.statement.open')}</Button>}
                  <span className="t-caption ms-auto">{s.last_lead_at ? t('partners.lastLead', { when: formatLastSeen(s.last_lead_at, i18n.language) }) : t('partners.noLeads')} · {t('partners.links', { count: s.links })}</span>
                </div>
              </li>
            )
          })}
        </ul>
      )}

      <PartnerStatementDrawer partner={statementFor} onClose={() => setStatementFor(null)} />
      <PartnerDrawer open={editing !== null} partner={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />
      <LinkDrawer
        open={linkFor !== null}
        linkId={null}
        canEdit
        preset={linkFor ? { name: linkFor.name, partner_id: linkFor.id, channel: linkFor.type === 'influencer' ? 'influencer' : 'broker' } : undefined}
        onClose={() => setLinkFor(null)}
      />
    </>
  )
}
