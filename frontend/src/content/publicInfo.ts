/**
 * What the public pages say about who runs the service.
 *
 * The official facts of «مهتم» (approved by the owner on 2026-11-01): the name, the one mailbox, the phone, the city and the
 * site. A deployment may override any of them (`VITE_PUBLIC_LEGAL_NAME`, `VITE_PUBLIC_CONTACT_EMAIL`, `VITE_PUBLIC_PHONE`,
 * `VITE_PUBLIC_ADDRESS`, `VITE_PUBLIC_PROCESSORS`). Nothing here is invented: where a fact was not supplied (the named list of
 * outside processors), the text says it is available on request rather than making one up.
 */
const read = (value: string | undefined): string | null => {
  const text = value?.trim()

  return text ? text : null
}

type Bilingual = { ar: string; en: string }

const both = (override: string | null, fallback: Bilingual): Bilingual => (override ? { ar: override, en: override } : fallback)

export const OFFICIAL = {
  name: { ar: 'مهتم', en: 'Mohtam' },
  email: 'info@mohtam.pro',
  /** E.164 for links, spaced for reading. */
  phone: '+966532115582',
  phoneDisplay: '+966 53 211 5582',
  address: { ar: 'الرياض، المملكة العربية السعودية', en: 'Riyadh, Saudi Arabia' },
  website: 'https://mohtam.pro',
} as const

const phone = read(import.meta.env.VITE_PUBLIC_PHONE)

export const PUBLIC_INFO = {
  /** The entity that provides the service. */
  companyName: both(read(import.meta.env.VITE_PUBLIC_LEGAL_NAME) ?? read(import.meta.env.VITE_PUBLIC_COMPANY_NAME), OFFICIAL.name),
  /** One mailbox for support, privacy and deletion requests. */
  email: read(import.meta.env.VITE_PUBLIC_CONTACT_EMAIL) ?? OFFICIAL.email,
  phone: phone ? phone.replace(/\s+/g, '') : OFFICIAL.phone,
  phoneDisplay: phone ?? OFFICIAL.phoneDisplay,
  /** The postal address. */
  address: both(read(import.meta.env.VITE_PUBLIC_ADDRESS), OFFICIAL.address),
  website: OFFICIAL.website,
  /** The named list of outside services that process data for the platform; on request until it is published. */
  processors: both(read(import.meta.env.VITE_PUBLIC_PROCESSORS), {
    ar: `القائمة المسمّاة بهؤلاء المزوّدين متاحة عند الطلب عبر ${OFFICIAL.email}`,
    en: `the named list of these providers is available on request at ${OFFICIAL.email}`,
  }),
  /** When the legal texts were last revised. */
  updated: '2026-11-01',
} as const

/** Facts still unsupplied. Every one now has an official value, so this stays empty; kept for the deploy checklist. */
export const unset = (): string[] => []

/** The product's own name in Arabic: always «مهتم». */
export const PRODUCT = 'مهتم'
