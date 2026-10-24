import type { FormField, FormLayout, RepeaterSubfield } from '@/features/hub'
import { e164 } from './phoneCountries'
import { visibility } from './formLogic'

/**
 * What is wrong with an answer, as a code the page translates — the same checks the server makes, so a form that passes here is not
 * sent back for a reason the visitor could have been told. The server still checks everything again: this only saves a round trip.
 */
export type Answers = Record<string, unknown>
export type PhoneAnswer = { country: string; number: string }

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const text = (v: unknown): string => (typeof v === 'string' ? v.trim() : typeof v === 'number' ? String(v) : '')

export const isBlank = (v: unknown): boolean => {
  if (v === null || v === undefined || v === '') return true
  if (Array.isArray(v)) return v.length === 0
  if (typeof v === 'object') return Object.values(v as Record<string, unknown>).every(isBlank)

  return false
}

type Scalar = Pick<FormField, 'type' | 'options' | 'validation'> & { key?: string }

function scalarProblem(f: Scalar, value: unknown, defaultCountry: string): string | null {
  const v = f.validation ?? {}

  switch (f.type) {
    case 'phone': {
      const p = value as Partial<PhoneAnswer> | string
      const number = typeof p === 'string' ? p : p?.number ?? ''
      const country = typeof p === 'string' ? defaultCountry : p?.country ?? defaultCountry

      return e164(number, country) ? null : 'invalid_phone'
    }
    case 'email': return EMAIL.test(text(value)) && text(value).length <= 190 ? null : 'invalid_email'
    case 'number': {
      const n = Number(text(value))
      if (text(value) === '' || Number.isNaN(n)) return 'invalid_number'

      return (v.min !== undefined && n < v.min) || (v.max !== undefined && n > v.max) ? 'out_of_range' : null
    }
    case 'select':
    case 'radio': return (f.options ?? []).some((o) => o.value === value) ? null : 'invalid_option'
    case 'multi_select': return Array.isArray(value) && value.length > 0 && value.every((x) => (f.options ?? []).some((o) => o.value === x)) ? null : 'invalid_option'
    case 'date': return /^\d{4}-\d{2}-\d{2}$/.test(text(value)) && !Number.isNaN(Date.parse(text(value))) ? null : 'invalid_date'
    case 'time': return /^([01]\d|2[0-3]):[0-5]\d$/.test(text(value)) ? null : 'invalid_time'
    case 'text':
    case 'textarea': {
      const t = text(value)
      if (v.max_length !== undefined && t.length > v.max_length) return 'too_long'
      if (v.min_length !== undefined && t.length < v.min_length) return 'too_short'
      if (v.pattern) {
        try { if (!new RegExp(v.pattern, 'u').test(t)) return 'invalid_pattern' } catch { /* a pattern the server accepted that this engine cannot read: the server decides */ }
      }

      return null
    }
    default: return null
  }
}

function matrixProblem(f: FormField, value: unknown): string | null {
  const m = f.matrix
  if (!m) return null
  const answers = (value && typeof value === 'object' ? value : {}) as Record<string, unknown>
  const answered = Object.values(answers).filter((a) => !isBlank(a)).length

  if (m.require_all_rows && answered < m.rows.length) return 'matrix_incomplete'
  if (answered < m.min_answers) return 'matrix_incomplete'

  return null
}

function repeaterProblems(f: FormField, value: unknown, required: boolean, defaultCountry: string): Record<string, string> {
  const r = f.repeater
  if (!r) return {}
  const rows = (Array.isArray(value) ? value : []).filter((row) => !isBlank(row)) as Record<string, unknown>[]
  const out: Record<string, string> = {}

  rows.forEach((row, i) => {
    for (const s of r.subfields as RepeaterSubfield[]) {
      const cell = row[s.key]
      if (isBlank(cell)) { if (s.required) out[`${f.key}.${i}.${s.key}`] = 'required'; continue }
      const problem = scalarProblem({ type: s.type, options: s.options }, cell, defaultCountry)
      if (problem) out[`${f.key}.${i}.${s.key}`] = problem
    }
  })

  if (Object.keys(out).length === 0) {
    if (rows.length > r.max_rows) out[f.key] = 'too_many_rows'
    else if (rows.length < r.min_rows) out[f.key] = rows.length === 0 ? 'required' : 'too_few_rows'
    else if (required && rows.length === 0) out[f.key] = 'required'
  }

  return out
}

/** The problems with these answers, keyed by field (a repeater's by `field.row.column`). Only what is shown is checked. */
export function validateAnswers(fields: FormField[], layout: FormLayout, answers: Answers, defaultCountry: string, onlyKeys?: string[]): Record<string, string> {
  const state = visibility(fields, layout, answers)
  const errors: Record<string, string> = {}

  for (const f of fields) {
    if (!state.visible.includes(f.key) || ['heading', 'paragraph', 'divider'].includes(f.type)) continue
    if (onlyKeys && !onlyKeys.includes(f.key)) continue

    const value = answers[f.key]
    const required = state.required.includes(f.key)

    if (f.type === 'checkbox' || f.type === 'consent') {
      if (required && value !== true) errors[f.key] = 'required'
      continue
    }

    if (f.type === 'repeater') {
      Object.assign(errors, repeaterProblems(f, value, required, defaultCountry))
      continue
    }

    if (isBlank(value)) {
      if (required || (f.type === 'matrix' && (f.matrix?.min_answers ?? 0) > 0)) errors[f.key] = 'required'
      continue
    }

    const problem = f.type === 'matrix' ? matrixProblem(f, value) : scalarProblem(f, value, defaultCountry)
    if (problem) errors[f.key] = problem
  }

  return errors
}

/** What goes to the server: only what the visitor could see, trimmed, with a repeater's empty rows left out. */
export function serializeAnswers(fields: FormField[], layout: FormLayout, answers: Answers): Answers {
  const state = visibility(fields, layout, answers)
  const out: Answers = {}

  for (const f of fields) {
    if (!state.visible.includes(f.key) || ['heading', 'paragraph', 'divider'].includes(f.type)) continue
    const v = answers[f.key]
    if (v === undefined) continue

    out[f.key] = typeof v === 'string' ? v.trim() : f.type === 'repeater' && Array.isArray(v) ? v.filter((row) => !isBlank(row)) : v
  }

  return out
}

/** A phone answer is `{country, number}`; a number alone is read in the form's own country. */
export const phoneOf = (v: unknown, fallback: string): PhoneAnswer => (v && typeof v === 'object' ? { country: (v as PhoneAnswer).country || fallback, number: (v as PhoneAnswer).number ?? '' } : { country: fallback, number: typeof v === 'string' ? v : '' })
