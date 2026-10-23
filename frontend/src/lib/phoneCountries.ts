/**
 * The countries a phone field offers. The same list, and the same rules for what a typed number means, as the server's
 * (`PhoneCountries`) — both run `features/formVectors.json`, so what the page accepts the server accepts.
 */
export const DIAL: Record<string, string> = {
  SA: '966', AE: '971', KW: '965', QA: '974', BH: '973', OM: '968', EG: '20', JO: '962', IQ: '964', LB: '961', SY: '963', YE: '967',
  SD: '249', MA: '212', DZ: '213', TN: '216', LY: '218', PK: '92', IN: '91', TR: '90', GB: '44', US: '1', DE: '49', FR: '33',
}

/** Names as people say them, for the selector. */
export const COUNTRY_NAMES: Record<string, { ar: string; en: string }> = {
  SA: { ar: 'السعودية', en: 'Saudi Arabia' }, AE: { ar: 'الإمارات', en: 'UAE' }, KW: { ar: 'الكويت', en: 'Kuwait' }, QA: { ar: 'قطر', en: 'Qatar' },
  BH: { ar: 'البحرين', en: 'Bahrain' }, OM: { ar: 'عُمان', en: 'Oman' }, EG: { ar: 'مصر', en: 'Egypt' }, JO: { ar: 'الأردن', en: 'Jordan' },
  IQ: { ar: 'العراق', en: 'Iraq' }, LB: { ar: 'لبنان', en: 'Lebanon' }, SY: { ar: 'سوريا', en: 'Syria' }, YE: { ar: 'اليمن', en: 'Yemen' },
  SD: { ar: 'السودان', en: 'Sudan' }, MA: { ar: 'المغرب', en: 'Morocco' }, DZ: { ar: 'الجزائر', en: 'Algeria' }, TN: { ar: 'تونس', en: 'Tunisia' },
  LY: { ar: 'ليبيا', en: 'Libya' }, PK: { ar: 'باكستان', en: 'Pakistan' }, IN: { ar: 'الهند', en: 'India' }, TR: { ar: 'تركيا', en: 'Turkey' },
  GB: { ar: 'بريطانيا', en: 'UK' }, US: { ar: 'أمريكا', en: 'USA' }, DE: { ar: 'ألمانيا', en: 'Germany' }, FR: { ar: 'فرنسا', en: 'France' },
}

const DIGITS: Record<string, string> = {
  '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
  '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
}

const ascii = (text: string) => text.replace(/[٠-٩۰-۹]/g, (d) => DIGITS[d] ?? d)

/** Saudi numbers arrive as 05…, 5…, 9665…, +9665…, 01…: all one number. Mirrors the server's normaliser, shape for shape. */
function saudi(d: string): string | null {
  let out: string

  if (d.startsWith('966')) out = `966${d.slice(3).replace(/^0+/, '')}`
  else if (/^05\d{8}$/.test(d)) out = `966${d.slice(1)}`
  else if (/^5\d{8}$/.test(d)) out = `966${d}`
  else if (/^0\d{9}$/.test(d)) out = `966${d.slice(1)}`
  else out = d

  return out.length >= 8 && out.length <= 15 ? `+${out}` : null
}

/** E.164 (`+966551234567`), or null when this is not a number. */
export function e164(input: string, country: string): string | null {
  const text = ascii(input.trim())
  const digits = text.replace(/\D/g, '')

  if (digits === '' || /[^\d\s\-().+]/.test(text)) return null

  if (text.startsWith('+') || digits.startsWith('00')) {
    const d = digits.replace(/^00/, '')

    return d.length >= 8 && d.length <= 15 ? `+${d}` : null
  }

  const dial = DIAL[country]
  if (!dial) return null

  if (country === 'SA') return saudi(digits)

  const national = digits.replace(/^0+/, '')
  const full = national.startsWith(dial) && national.length - dial.length >= 8 ? national : dial + national

  return full.length >= 8 && full.length <= 15 ? `+${full}` : null
}
