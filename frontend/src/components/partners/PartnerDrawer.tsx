import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '@/lib/api'
import { Button, Drawer, Input, Select, Textarea } from '@/components/ui'
import { createPartner, deletePartner, PARTNER_TYPES, updatePartner, type Partner, type PartnerInput, type PartnerType } from '@/features/partners'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'

const BLANK: PartnerInput = { name: '', type: 'broker', company: '', contact_name: '', email: '', phone: '', commission_percent: null, show_revenue: false, notes: '' }

/** Make or change a partner. The two things worth a sentence: the commission, and whether they may see what a sale was worth. */
export function PartnerDrawer({ open, partner, onClose }: { open: boolean; partner: Partner | null; onClose: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [form, setForm] = useState<PartnerInput>(BLANK)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!open) return
    setError(null)
    setForm(partner ? { name: partner.name, type: partner.type, company: partner.company ?? '', contact_name: partner.contact_name ?? '', email: partner.email ?? '', phone: partner.phone ?? '', commission_percent: partner.commission_percent, show_revenue: partner.show_revenue, notes: partner.notes ?? '' } : BLANK)
  }, [open, partner])

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['partners'] })
    void queryClient.invalidateQueries({ queryKey: ['partners-simple'] })
  }

  const clean = (): PartnerInput => ({
    ...form,
    company: form.company || null, contact_name: form.contact_name || null, email: form.email || null, phone: form.phone || null, notes: form.notes || null,
    commission_percent: form.commission_percent === null || form.commission_percent === undefined || Number.isNaN(Number(form.commission_percent)) ? null : Number(form.commission_percent),
  })

  const save = useMutation({
    mutationFn: () => (partner ? updatePartner(partner.id, clean()) : createPartner(clean())),
    onSuccess: () => { refresh(); toast(t('common.saved'), 'success'); onClose() },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.errors) setError(Object.values(caught.errors)[0]?.[0] ?? t('errors.validation'))
      else toast(translateError(caught), 'error')
    },
  })

  const remove = useMutation({
    mutationFn: () => deletePartner(partner!.id),
    onSuccess: () => { refresh(); onClose() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const text = (key: 'name' | 'company' | 'contact_name' | 'email' | 'phone') => ({ value: (form[key] ?? '') as string, onChange: (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value })) })

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title={partner ? t('partners.edit') : t('partners.new')}
      testId="partner-drawer"
      size="lg"
      footer={
        <>
          {partner && <Button variant="ghost" icon={<Trash2 className="size-4" />} loading={remove.isPending} onClick={() => remove.mutate()} data-testid="delete-partner">{t('common.delete')}</Button>}
          <Button onClick={() => save.mutate()} loading={save.isPending} disabled={!form.name} data-testid="save-partner">{t('common.save')}</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2" data-testid="partner-form">
        <Input label={t('partners.name')} data-testid="partner-name" {...text('name')} />
        <Select label={t('partners.type')} data-testid="partner-type" value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value as PartnerType }))}>
          {PARTNER_TYPES.map((type) => <option key={type} value={type}>{t(`partners.types.${type}`)}</option>)}
        </Select>
        <Input label={t('partners.company')} {...text('company')} />
        <Input label={t('partners.contact')} {...text('contact_name')} />
        <Input label={t('auth.email')} dir="ltr" type="email" {...text('email')} />
        <Input label={t('leads.phone')} dir="ltr" {...text('phone')} />
        <Input
          label={t('partners.commission')}
          hint={t('partners.commissionHint')}
          dir="ltr"
          type="number"
          min={0}
          max={100}
          step="0.1"
          data-testid="partner-commission"
          value={form.commission_percent ?? ''}
          onChange={(e) => setForm((f) => ({ ...f, commission_percent: e.target.value === '' ? null : Number(e.target.value) }))}
        />
        <label className="flex cursor-pointer items-center justify-between gap-3 self-end rounded-xl bg-[var(--color-surface-muted)] px-3 py-2">
          <span>
            <span className="t-small block font-medium">{t('partners.showRevenue')}</span>
            <span className="t-caption block">{t('partners.showRevenueHint')}</span>
          </span>
          <input type="checkbox" role="switch" checked={form.show_revenue ?? false} data-testid="partner-show-revenue" onChange={(e) => setForm((f) => ({ ...f, show_revenue: e.target.checked }))} className="size-4 accent-[var(--brand)]" />
        </label>
        <div className="sm:col-span-2">
          <Textarea label={t('partners.notes')} rows={2} value={form.notes ?? ''} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} />
        </div>
      </div>
      {error && <p role="alert" className="mt-3 text-[0.9375rem] font-semibold text-[var(--color-danger)]" data-testid="partner-error">{error}</p>}
    </Drawer>
  )
}
