/**
 * The zones and currencies this product's customers actually operate in. A full
 * IANA list would be 400+ options to scroll past; anything missing can still be
 * set through the API, which validates against the complete list.
 *
 * Shared by the tenant's own settings screen and the platform console, so a
 * client created by the operator and one that configures itself offer the same
 * choices.
 */
export const TIMEZONES = [
  'Asia/Riyadh',
  'Asia/Dubai',
  'Asia/Kuwait',
  'Asia/Qatar',
  'Asia/Bahrain',
  'Asia/Muscat',
  'Asia/Baghdad',
  'Asia/Amman',
  'Asia/Beirut',
  'Africa/Cairo',
  'Europe/Istanbul',
  'Europe/London',
  'UTC',
] as const

export const CURRENCIES = ['SAR', 'AED', 'KWD', 'QAR', 'BHD', 'OMR', 'EGP', 'JOD', 'USD', 'EUR'] as const
