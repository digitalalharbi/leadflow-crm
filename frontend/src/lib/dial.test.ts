import { describe, expect, it } from 'vitest'
import { dialTarget, whatsappTarget } from './dial'

/**
 * A number is dialled only when it is a number. Otherwise the rep is told why, in words, and the dialer is
 * never opened on junk (and nothing is recorded as a call that could not have been placed).
 */
describe('dialTarget', () => {
  it.each([
    ['+966500300021', 'tel:+966500300021'],
    ['966500300021', 'tel:966500300021'],
    ['0500300021', 'tel:0500300021'],
    ['+966 50 030 0021', 'tel:+966500300021'],
    ['(050) 030-0021', 'tel:0500300021'],
    ['٠٥٠٠٣٠٠٠٢١', 'tel:0500300021'],
  ])('dials %s', (input, href) => {
    expect(dialTarget(input)).toEqual({ ok: true, href, number: href.slice(4) })
  })

  it.each([null, undefined, '', '   '])('reports %j as missing', (input) => {
    expect(dialTarget(input)).toEqual({ ok: false, reason: 'missing' })
  })

  it.each(['abc', '12345', '+966-abc-021', '1234567890123456', '++966500300021', 'tel:+966500300021', '0500 300 021 ext 5'])('refuses %j as invalid', (input) => {
    expect(dialTarget(input)).toEqual({ ok: false, reason: 'invalid' })
  })

  it('never produces an address from an unusable number', () => {
    expect(dialTarget('call me maybe')).not.toHaveProperty('href')
  })
})

describe('whatsappTarget', () => {
  it('opens WhatsApp by its own deep link, digits only', () => {
    expect(whatsappTarget('+966 50 030 0021')).toBe('https://wa.me/966500300021')
  })

  it('is null for a number that cannot be used, so no link is built from it', () => {
    expect(whatsappTarget('not a number')).toBeNull()
    expect(whatsappTarget(null)).toBeNull()
  })
})
