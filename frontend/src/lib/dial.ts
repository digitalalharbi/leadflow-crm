/**
 * Whether a number can be dialled, and the one address that dials it.
 *
 * A `tel:` link built from whatever is in the phone field opens the dialer on a string that is not a
 * number — the rep presses Call, the phone shows a keypad with junk in it, and the product has already
 * written down that a call was placed. So the number is checked first, and an unusable one is reported
 * as that, in words, and never handed to the dialer.
 *
 * Accepted: an optional leading `+`, then 7–15 digits (E.164 is at most 15), with spaces, dashes,
 * dots and brackets stripped. Anything else — letters, an empty field, too few or too many digits — is
 * `invalid`, and an absent number is `missing`.
 */
export type Dial =
  | { ok: true; href: string; number: string }
  | { ok: false; reason: 'missing' | 'invalid' }

const DIGITS = /^\+?\d{7,15}$/

export function dialTarget(phone: string | null | undefined): Dial {
  if (phone === null || phone === undefined || phone.trim() === '') return { ok: false, reason: 'missing' }

  // Arabic-Indic digits are digits to a person; normalise them so a number typed that way still dials.
  const latin = phone.replaceAll(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
  const number = latin.replaceAll(/[\s\-.()]/g, '')

  return DIGITS.test(number) ? { ok: true, href: `tel:${number}`, number } : { ok: false, reason: 'invalid' }
}

/** WhatsApp's own deep link for a number: `https://wa.me/<digits>`, digits only, no `+`. Null when unusable. */
export function whatsappTarget(phone: string | null | undefined): string | null {
  const target = dialTarget(phone)

  return target.ok ? `https://wa.me/${target.number.replace('+', '')}` : null
}
