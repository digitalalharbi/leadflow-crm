import { Fragment } from 'react'
import { useTranslation } from 'react-i18next'
import { PUBLIC_INFO } from '@/content/publicInfo'

const TOKENS = /(\{company\}|\{email\}|\{phone\}|\{address\}|\{website\}|\{processors\})/g

const link = 'font-semibold text-[var(--brand-text)] underline underline-offset-2'

/**
 * A sentence with the service's official facts in it.
 *
 * `{company}`, `{email}`, `{phone}`, `{address}`, `{website}` and `{processors}` are replaced by the official value in the
 * reader's language (see `publicInfo.ts`). The mailbox and the phone are links; Latin values read left to right.
 */
export function Rich({ text }: { text: string }) {
  const { i18n } = useTranslation()
  const lang = i18n.language.startsWith('ar') ? 'ar' : 'en'

  return (
    <>
      {text.split(TOKENS).map((part, index) => {
        switch (part) {
          case '{company}':
            return <Fragment key={index}>{PUBLIC_INFO.companyName[lang]}</Fragment>
          case '{address}':
            return <Fragment key={index}>{PUBLIC_INFO.address[lang]}</Fragment>
          case '{processors}':
            return <Fragment key={index}>{PUBLIC_INFO.processors[lang]}</Fragment>
          case '{email}':
            return <a key={index} href={`mailto:${PUBLIC_INFO.email}`} className={link} dir="ltr">{PUBLIC_INFO.email}</a>
          case '{phone}':
            return <a key={index} href={`tel:${PUBLIC_INFO.phone}`} className={link} dir="ltr">{PUBLIC_INFO.phoneDisplay}</a>
          case '{website}':
            return <a key={index} href={PUBLIC_INFO.website} className={link} dir="ltr">{PUBLIC_INFO.website.replace('https://', '')}</a>
          default:
            return <Fragment key={index}>{part}</Fragment>
        }
      })}
    </>
  )
}
