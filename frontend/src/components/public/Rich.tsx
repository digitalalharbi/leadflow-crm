import { Fragment } from 'react'
import { useTranslation } from 'react-i18next'
import { PUBLIC_INFO } from '@/content/publicInfo'

const TOKENS = /(\{company\}|\{email\}|\{address\}|\{processors\})/g

const MARKERS: Record<string, { ar: string; en: string }> = {
  '{company}': { ar: 'اسم الجهة القانونية', en: 'legal name' },
  '{email}': { ar: 'البريد الرسمي', en: 'official email' },
  '{address}': { ar: 'العنوان', en: 'address' },
  '{processors}': { ar: 'قائمة المزوّدين', en: 'list of providers' },
}

const VALUE: Record<string, string | null> = {
  '{company}': PUBLIC_INFO.companyName,
  '{email}': PUBLIC_INFO.email,
  '{address}': PUBLIC_INFO.address,
  '{processors}': PUBLIC_INFO.processors,
}

/**
 * A sentence with the deployment's facts in it.
 *
 * `{company}`, `{email}`, `{address}` and `{processors}` are replaced by the value the deployment supplies. Until it
 * does, a visible marker stands there ("to be added before launch"), never an invented name or a mailbox
 * nobody reads — so an unfinished page cannot be mistaken for a finished one.
 */
export function Rich({ text }: { text: string }) {
  const { i18n } = useTranslation()
  const lang = i18n.language.startsWith('ar') ? 'ar' : 'en'

  return (
    <>
      {text.split(TOKENS).map((part, index) => {
        const marker = MARKERS[part]

        if (!marker) return <Fragment key={index}>{part}</Fragment>

        const value = VALUE[part]

        if (value) {
          return part === '{email}'
            ? <a key={index} href={`mailto:${value}`} className="font-semibold text-[var(--brand-text)] underline underline-offset-2" dir="ltr">{value}</a>
            : <Fragment key={index}>{value}</Fragment>
        }

        return (
          <span key={index} data-testid="to-add" className="rounded-md bg-[var(--status-warn-bg)] px-1.5 py-0.5 text-[0.9em] font-medium text-[var(--status-warn-ink)]">
            {lang === 'ar' ? `[يُضاف قبل الإطلاق: ${marker.ar}]` : `[to be added before launch: ${marker.en}]`}
          </span>
        )
      })}
    </>
  )
}
