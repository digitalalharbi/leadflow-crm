import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ClipboardCheck, Sparkles } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchQualificationOptions, fetchScoreBreakdown, saveQualification } from '@/features/conversations'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatBudgetRange } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, CardHeader, Input, Modal, Select, TemperatureBadge,
} from '@/components/ui'
import type { Lead, QualificationInput } from '@/types/api'
import { cn } from '@/lib/cn'

const STATUS_TONE = {
  unqualified: 'neutral',
  partially_qualified: 'warning',
  qualified: 'success',
} as const

/**
 * What we know about the buyer, and why they score what they score.
 *
 * The score is never a bare number here: every rule that fired is listed with
 * its points, so a rep who disagrees can see which answer to change rather
 * than distrusting the whole thing.
 */
export function QualificationCard({ lead }: { lead: Lead }) {
  const { t, i18n } = useTranslation()
  const [open, setOpen] = useState(false)

  const { data: breakdown } = useQuery({
    queryKey: ['lead-score', lead.id],
    queryFn: () => fetchScoreBreakdown(lead.id),
  })

  const { data: options } = useQuery({
    queryKey: ['qualification-options'],
    queryFn: fetchQualificationOptions,
    staleTime: 60 * 60 * 1000,
  })

  const label = (field: keyof NonNullable<typeof options>, value: string | null) =>
    options?.[field]?.find((option) => option.value === value)?.label ?? null

  const rows: Array<[string, string | null]> = [
    [t('qualification.purpose'), label('purchase_purpose', lead.purchase_purpose)],
    [t('qualification.budget'), formatBudgetRange(lead.budget_min, lead.budget_max, 'SAR', i18n.language)],
    [t('qualification.paymentMethod'), label('payment_method', lead.payment_method)],
    [t('qualification.financingStatus'), label('financing_status', lead.financing_status)],
    [t('qualification.propertyType'), lead.preferred_property_type],
    [t('qualification.location'), lead.preferred_location],
    [t('qualification.transaction'), label('transaction_type', lead.transaction_type ?? null)],
    [t('qualification.district'), lead.preferred_district ?? null],
    [t('qualification.bedrooms'), lead.bedrooms != null ? String(lead.bedrooms) : null],
    [t('qualification.area'), lead.area_sqm != null ? `${lead.area_sqm} m²` : null],
    [t('qualification.nationality'), lead.nationality ?? null],
    [t('qualification.channel'), label('preferred_channel', lead.preferred_channel ?? null)],
    [t('qualification.timeline'), label('purchase_timeline', lead.purchase_timeline)],
    [
      t('qualification.appointmentInterest'),
      lead.appointment_interest === null
        ? null
        : lead.appointment_interest
          ? t('qualification.appointmentYes')
          : t('qualification.appointmentNo'),
    ],
    [t('qualification.reservationIntent'), lead.reservation_intent ? t('qualification.appointmentYes') : null],
  ]

  const known = rows.filter(([, value]) => Boolean(value))

  return (
    <>
      <Card data-testid="qualification-card">
        <CardHeader
          title={t('qualification.title')}
          action={
            <div className="flex items-center gap-2">
              <Badge tone={STATUS_TONE[lead.qualification_status]} testId="qualification-status">
                {t(`qualification.status.${lead.qualification_status}`)}
              </Badge>
              <Button size="sm" variant="secondary" onClick={() => setOpen(true)} data-testid="qualify-open">
                <ClipboardCheck className="size-4" />
                {t('common.edit')}
              </Button>
            </div>
          }
        />
        <CardBody className="space-y-4">
          {known.length === 0 ? (
            <div className="py-2">
              <p className="text-sm text-[var(--color-ink-muted)]">{t('qualification.empty')}</p>
              <Button className="mt-3" onClick={() => setOpen(true)} data-testid="qualify-empty-cta">
                {t('qualification.action')}
              </Button>
            </div>
          ) : (
            <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
              {known.map(([key, value]) => (
                <div key={key} className="flex justify-between gap-3 text-sm">
                  <dt className="text-[var(--color-ink-muted)]">{key}</dt>
                  <dd className="text-end font-medium">{value}</dd>
                </div>
              ))}
            </dl>
          )}

          {breakdown && breakdown.reasons.length > 0 && (
            <div className="rounded-[var(--radius-card)] border border-[var(--color-line)] p-3" data-testid="score-reasons">
              <div className="mb-2 flex items-center justify-between">
                <p className="flex items-center gap-1.5 text-[0.9375rem] font-medium">
                  <Sparkles className="size-3.5 text-[var(--color-ink-muted)]" />
                  {t('qualification.scoreTitle')}
                </p>
                <div className="flex items-center gap-2">
                  <TemperatureBadge temperature={breakdown.temperature} />
                  <span className="tabular text-sm font-semibold">{breakdown.score}</span>
                </div>
              </div>

              <ul className="space-y-1">
                {breakdown.reasons.map((reason) => (
                  <li key={reason.key} className="flex items-center justify-between gap-3 text-[0.9375rem]">
                    <span className="text-[var(--color-ink-muted)]">{reason.label}</span>
                    {/*
                      Forced LTR: a signed number in an RTL paragraph is
                      reordered by the bidi algorithm, so "+25" renders as
                      "25+" and a penalty reads like a bonus.
                    */}
                    <span
                      dir="ltr"
                      className={cn(
                        'tabular font-medium',
                        reason.points > 0
                          ? 'text-[var(--color-status-won)]'
                          : 'text-[var(--color-status-lost)]',
                      )}
                    >
                      {reason.points > 0 ? `+${reason.points}` : reason.points}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </CardBody>
      </Card>

      <QualificationDrawer lead={lead} open={open} onClose={() => setOpen(false)} />
    </>
  )
}

/**
 * One modal, eight fields, one save. A rep on a call must not have to navigate
 * anywhere to record what they just heard.
 */
export function QualificationDrawer({
  lead,
  open,
  onClose,
}: {
  lead: Lead
  open: boolean
  onClose: () => void
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const { data: options } = useQuery({
    queryKey: ['qualification-options'],
    queryFn: fetchQualificationOptions,
    staleTime: 60 * 60 * 1000,
  })

  const [form, setForm] = useState<QualificationInput>({})

  // Reset from the lead each time it opens, so a cancelled edit leaves nothing
  // behind and a lead updated elsewhere shows its current answers.
  useEffect(() => {
    if (open) {
      setForm({
        purchase_purpose: lead.purchase_purpose ?? '',
        budget_min: lead.budget_min,
        budget_max: lead.budget_max,
        payment_method: lead.payment_method ?? '',
        financing_status: lead.financing_status ?? '',
        preferred_property_type: lead.preferred_property_type ?? '',
        preferred_location: lead.preferred_location ?? '',
        purchase_timeline: lead.purchase_timeline ?? '',
        appointment_interest: lead.appointment_interest,
        transaction_type: lead.transaction_type ?? '',
        preferred_district: lead.preferred_district ?? '',
        bedrooms: lead.bedrooms ?? null,
        area_sqm: lead.area_sqm ?? null,
        nationality: lead.nationality ?? '',
        preferred_channel: lead.preferred_channel ?? '',
        reservation_intent: lead.reservation_intent ?? false,
      })
    }
  }, [open, lead])

  const save = useMutation({
    mutationFn: () =>
      saveQualification(lead.id, {
        ...form,
        purchase_purpose: form.purchase_purpose || null,
        payment_method: form.payment_method || null,
        financing_status: form.financing_status || null,
        purchase_timeline: form.purchase_timeline || null,
        preferred_property_type: form.preferred_property_type || null,
        preferred_location: form.preferred_location || null,
        transaction_type: form.transaction_type || null,
        preferred_district: form.preferred_district || null,
        nationality: form.nationality || null,
        preferred_channel: form.preferred_channel || null,
        bedrooms: form.bedrooms === null || form.bedrooms === undefined || form.bedrooms === ('' as unknown) ? null : Number(form.bedrooms),
        area_sqm: form.area_sqm === null || form.area_sqm === undefined || form.area_sqm === ('' as unknown) ? null : Number(form.area_sqm),
        budget_min: form.budget_min === null || form.budget_min === undefined ? null : Number(form.budget_min),
        budget_max: form.budget_max === null || form.budget_max === undefined ? null : Number(form.budget_max),
      }),
    onSuccess: (result) => {
      onClose()
      void queryClient.invalidateQueries({ queryKey: ['lead', lead.id] })
      void queryClient.invalidateQueries({ queryKey: ['lead-score', lead.id] })

      // Say what changed, not just that something was saved: the score moving
      // is the reason to fill the form in at all.
      toast(
        result.changed.score
          ? t('qualification.scoreChanged', { score: result.scoring.score })
          : t('qualification.saved'),
        'success',
      )
    },
    onError: (e) => toast(translateError(e), 'error'),
  })

  const field = (name: keyof QualificationInput) => ({
    value: (form[name] ?? '') as string,
    onChange: (e: { target: { value: string } }) => setForm((p) => ({ ...p, [name]: e.target.value })),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('qualification.title')}
      description={t('qualification.subtitle')}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button onClick={() => save.mutate()} loading={save.isPending} data-testid="qualify-save">
            {t('common.save')}
          </Button>
        </>
      }
    >
      <div className="space-y-4" data-testid="qualification-form">
        <div className="grid gap-4 sm:grid-cols-2">
          <Select label={t('qualification.purpose')} data-testid="q-purpose" {...field('purchase_purpose')}>
            <option value="">{t('common.none')}</option>
            {options?.purchase_purpose.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>

          <Select label={t('qualification.timeline')} data-testid="q-timeline" {...field('purchase_timeline')}>
            <option value="">{t('common.none')}</option>
            {options?.purchase_timeline.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            type="number"
            inputMode="numeric"
            label={`${t('qualification.budget')} — ${t('qualification.budgetFrom')}`}
            value={form.budget_min ?? ''}
            data-testid="q-budget-min"
            onChange={(e) => setForm((p) => ({ ...p, budget_min: e.target.value === '' ? null : Number(e.target.value) }))}
          />
          <Input
            type="number"
            inputMode="numeric"
            label={`${t('qualification.budget')} — ${t('qualification.budgetTo')}`}
            value={form.budget_max ?? ''}
            data-testid="q-budget-max"
            onChange={(e) => setForm((p) => ({ ...p, budget_max: e.target.value === '' ? null : Number(e.target.value) }))}
          />
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Select label={t('qualification.paymentMethod')} data-testid="q-payment" {...field('payment_method')}>
            <option value="">{t('common.none')}</option>
            {options?.payment_method.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>

          <Select label={t('qualification.financingStatus')} data-testid="q-financing" {...field('financing_status')}>
            <option value="">{t('common.none')}</option>
            {options?.financing_status.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Input label={t('qualification.propertyType')} data-testid="q-property-type" {...field('preferred_property_type')} />
          <Input label={t('qualification.location')} data-testid="q-location" {...field('preferred_location')} />
        </div>

        {/* The real estate facts: what they want to do, where, how big, and how to reach them. */}
        <div className="grid gap-4 sm:grid-cols-2" data-testid="q-realestate">
          <Select label={t('qualification.transaction')} data-testid="q-transaction" {...field('transaction_type')}>
            <option value="">{t('qualification.appointmentUnknown')}</option>
            {options?.transaction_type?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>
          <Input label={t('qualification.district')} data-testid="q-district" {...field('preferred_district')} />
          <Input label={t('qualification.bedrooms')} type="number" dir="ltr" data-testid="q-bedrooms" {...field('bedrooms')} />
          <Input label={t('qualification.area')} type="number" dir="ltr" data-testid="q-area" {...field('area_sqm')} />
          <Input label={t('qualification.nationality')} data-testid="q-nationality" {...field('nationality')} />
          <Select label={t('qualification.channel')} data-testid="q-channel" {...field('preferred_channel')}>
            <option value="">{t('qualification.appointmentUnknown')}</option>
            {options?.preferred_channel?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>
        </div>

        <Select
          label={t('qualification.reservationIntent')}
          data-testid="q-reservation"
          value={form.reservation_intent ? '1' : '0'}
          onChange={(e) => setForm((p) => ({ ...p, reservation_intent: e.target.value === '1' }))}
        >
          <option value="0">{t('qualification.appointmentNo')}</option>
          <option value="1">{t('qualification.appointmentYes')}</option>
        </Select>

        <Select
          label={t('qualification.appointmentInterest')}
          data-testid="q-appointment"
          value={form.appointment_interest === null || form.appointment_interest === undefined ? '' : form.appointment_interest ? '1' : '0'}
          onChange={(e) =>
            setForm((p) => ({
              ...p,
              appointment_interest: e.target.value === '' ? null : e.target.value === '1',
            }))
          }
        >
          <option value="">{t('qualification.appointmentUnknown')}</option>
          <option value="1">{t('qualification.appointmentYes')}</option>
          <option value="0">{t('qualification.appointmentNo')}</option>
        </Select>
      </div>
    </Modal>
  )
}
