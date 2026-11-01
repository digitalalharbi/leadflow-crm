import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(path.resolve(__dirname, 'sw.template.js'), 'utf8')

describe('the service worker template', () => {
  it('clears earlier builds under the current cache name, not a misspelt one', () => {
    const prefix = /const OLD_CACHE = (\/.+\/)/.exec(source)?.[1]
    const pattern = new RegExp(prefix!.slice(1, -1))

    expect(pattern.test('mohtam-shell-abc123')).toBe(true)
    expect(pattern.test('mohtam-assets-abc123')).toBe(true)
    expect(pattern.test('mahtam-shell-old')).toBe(true)
    expect(pattern.test('workbox-precache')).toBe(false)
  })

  it('installs even when one precached file is missing', () => {
    expect(source).toContain('Promise.allSettled(PRECACHE.map(')
    expect(source).not.toMatch(/cache\.addAll\(PRECACHE\)/)
  })

  it('never touches the API or a write', () => {
    expect(source).toContain("request.method !== 'GET'")
    expect(source).toContain("url.pathname.startsWith('/api/')")
  })
})
