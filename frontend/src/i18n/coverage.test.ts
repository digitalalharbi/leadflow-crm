import { describe, expect, it } from 'vitest'
import ar from './ar'
import en from './en'

/**
 * Every key the interface asks for exists in both languages.
 *
 * i18next answers a missing key with the key itself, so nothing throws, nothing
 * logs, and the screen simply reads `common.saved` or `minutes_since_first_contact`.
 * It is invisible in review — the code looks right — and invisible in testing
 * unless somebody opens that exact state in that exact language.
 *
 * Three were found the first time this ran, and all three were in states a
 * developer rarely sees: a success toast in English, a chart with no data, and
 * a filter nobody had cleared. A fourth was a whole namespace that did not
 * exist anywhere in the app, so the rule builder listed its stages in English
 * inside an Arabic product.
 */

/** Arabic has six plural categories; i18next stores each as its own key. */
const PLURAL_CATEGORIES = ['zero', 'one', 'two', 'few', 'many', 'other']

/*
 * Read through Vite rather than `node:fs`: the app's tsconfig deliberately
 * leaves node's types out, because nothing under `src` should be reaching for
 * the filesystem, and a test is not a reason to widen that for the whole app.
 */
const sources: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

type Bundle = Record<string, unknown>

function resolve(bundle: Bundle, key: string): unknown {
  return key
    .split('.')
    .reduce<unknown>(
      (node, part) => (node && typeof node === 'object' ? (node as Bundle)[part] : undefined),
      bundle,
    )
}

function hasString(bundle: Bundle, key: string): boolean {
  if (typeof resolve(bundle, key) === 'string') {
    return true
  }

  // A pluralised key never exists on its own — `t('x.count', { count })` is
  // stored as `count_one`, `count_other` and so on.
  const parts = key.split('.')
  const leaf = parts.pop() as string
  const parent = resolve(bundle, parts.join('.'))

  if (!parent || typeof parent !== 'object') {
    return false
  }

  return PLURAL_CATEGORIES.some(
    (category) => typeof (parent as Bundle)[`${leaf}_${category}`] === 'string',
  )
}

const files = Object.entries(sources).filter(([path]) => !/\.test\.tsx?$/.test(path))

/** `t('a.b')` — a key written out in full. */
function staticKeys(source: string): string[] {
  return [...source.matchAll(/\bt\(\s*'([A-Za-z0-9_.]+)'/g)].map((match) => match[1])
}

/**
 * `` t(`a.b.${value}`) `` — the member is decided at runtime, so only the
 * namespace can be checked. That is the granularity that matters anyway: the
 * members often come from the API and a `defaultValue` covers the long tail,
 * but a namespace that does not exist means every one of them falls back.
 */
function dynamicNamespaces(source: string): string[] {
  return [...source.matchAll(/\bt\(\s*`([A-Za-z0-9_.]*?)\$\{/g)]
    .map((match) => match[1])
    .filter((prefix) => prefix.endsWith('.'))
    .map((prefix) => prefix.slice(0, -1))
}

describe('every key the interface asks for exists', () => {
  it('reads the source tree', () => {
    // A guard that silently walks nothing passes forever.
    expect(files.length).toBeGreaterThan(50)
  })

  it.each([
    ['ar', ar as Bundle],
    ['en', en as Bundle],
  ])('%s has every key used in the code', (_language, bundle) => {
    const missing = files.flatMap(([file, source]) =>
      staticKeys(source)
        .filter((key) => !hasString(bundle, key))
        .map((key) => `${key}  (${file})`),
    )

    expect([...new Set(missing)]).toEqual([])
  })

  it.each([
    ['ar', ar as Bundle],
    ['en', en as Bundle],
  ])('%s has every namespace the code builds a key from', (_language, bundle) => {
    const missing = files.flatMap(([file, source]) =>
      dynamicNamespaces(source)
        .filter((namespace) => {
          const node = resolve(bundle, namespace)

          return !node || typeof node !== 'object'
        })
        .map((namespace) => `${namespace}  (${file})`),
    )

    expect([...new Set(missing)]).toEqual([])
  })
})
