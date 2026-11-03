import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { apiGet, apiPost, ApiError } from '@/lib/api'
import { Button, Input, Textarea } from '@/components/ui'

/** A demo request or an insights signup. It appears only when the server has somewhere to put it, and asks for consent in words. */
export function ContactForm({ kind }: { kind: 'demo' | 'insights' }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [sent, setSent] = useState(false)
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [form, setForm] = useState({ name: '', email: '', phone: '', company: '', message: '', website: '' })
  const [consent, setConsent] = useState(false)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((prev) => ({ ...prev, [key]: e.target.value }))

  useEffect(() => {
    apiGet<{ form: boolean }>('/public/contact').then((data) => setOpen(Boolean(data?.form))).catch(() => setOpen(false))
  }, [])

  if (!open) return null

  if (sent) return <p role="status" className="notice notice-info my-4" data-testid="contact-sent">{t(`contactForm.sent.${kind}`)}</p>

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setErrors({})

    try {
      const query = new URLSearchParams(window.location.search)
      await apiPost('/public/contact', { kind, ...form, consent, utm_source: query.get('utm_source') ?? undefined, utm_medium: query.get('utm_medium') ?? undefined, utm_campaign: query.get('utm_campaign') ?? undefined })
      setSent(true)
    } catch (caught) {
      setErrors(caught instanceof ApiError && caught.errors ? Object.fromEntries(Object.entries(caught.errors).map(([k, v]) => [k, v[0] ?? ''])) : { form: t('errors.unknown') })
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} noValidate className="my-6 space-y-3 rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] p-5" data-testid={`contact-form-${kind}`}>
      <h2 className="text-[1.25rem] font-bold">{t(`contactForm.title.${kind}`)}</h2>
      {kind === 'demo' && <Input label={t('contactForm.name')} value={form.name} error={errors.name} onChange={set('name')} autoComplete="name" />}
      <Input dir="ltr" type="email" label={t('contactForm.email')} value={form.email} error={errors.email} onChange={set('email')} autoComplete="email" placeholder="name@company.com" />
      {kind === 'demo' && (
        <>
          <Input dir="ltr" type="tel" label={t('contactForm.phone')} value={form.phone} error={errors.phone} onChange={set('phone')} autoComplete="tel" />
          <Input label={t('contactForm.company')} hint={t('common.optional')} value={form.company} onChange={set('company')} autoComplete="organization" />
          <Textarea label={t('contactForm.message')} hint={t('common.optional')} value={form.message} onChange={set('message')} />
        </>
      )}
      {/* For bots only: no person sees or tabs into it. */}
      <div aria-hidden className="absolute -start-[9999px] h-0 w-0 overflow-hidden"><label>Website<input tabIndex={-1} autoComplete="off" value={form.website} onChange={set('website')} /></label></div>
      <label className="flex min-h-11 cursor-pointer items-start gap-2 text-[0.9375rem]">
        <input type="checkbox" className="mt-1 size-[1.125rem] accent-[var(--brand)]" checked={consent} onChange={(e) => setConsent(e.target.checked)} data-testid="contact-consent" />
        <span>{t(`contactForm.consent.${kind}`)}</span>
      </label>
      {errors.consent && <p role="alert" className="text-[0.875rem] text-[var(--color-status-lost)]">{errors.consent}</p>}
      {errors.form && <p role="alert" className="text-[0.875rem] text-[var(--color-status-lost)]">{errors.form}</p>}
      <Button type="submit" className="h-12 w-full !rounded-lg font-semibold" loading={busy} disabled={!consent} data-testid="contact-submit">{t(`contactForm.submit.${kind}`)}</Button>
    </form>
  )
}
