/**
 * What the public pages say about who runs the service.
 *
 * None of it is invented. The legal name, the contact address and the postal address are set per
 * deployment (`VITE_PUBLIC_COMPANY_NAME`, `VITE_PUBLIC_CONTACT_EMAIL`, `VITE_PUBLIC_ADDRESS`, `VITE_PUBLIC_PROCESSORS`); until they
 * are, the pages show a plain "to be added before launch" marker in their place — never a made-up company
 * or a mailbox nobody reads. `unset` lists what is still missing, for the deploy checklist.
 */
const read = (value: string | undefined): string | null => {
  const text = value?.trim()

  return text ? text : null
}

export const PUBLIC_INFO = {
  /** The legal entity that provides the service. */
  companyName: read(import.meta.env.VITE_PUBLIC_COMPANY_NAME),
  /** One mailbox for support, privacy and deletion requests. */
  email: read(import.meta.env.VITE_PUBLIC_CONTACT_EMAIL),
  /** The postal address. */
  address: read(import.meta.env.VITE_PUBLIC_ADDRESS),
  /** The named list of outside services that process data for the platform. */
  processors: read(import.meta.env.VITE_PUBLIC_PROCESSORS),
  /** When the legal texts were last revised. */
  updated: '2026-10-22',
} as const

export const unset = (): string[] => Object.entries(PUBLIC_INFO).filter(([key, value]) => key !== 'updated' && value === null).map(([key]) => key)

/** The product's own name in each language: always «مهتم», never a Latin spelling. */
export const PRODUCT = 'مهتم'
