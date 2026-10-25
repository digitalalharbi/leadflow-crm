import { describe, expect, it } from 'vitest'
import { mergeParts, type SharedReportData } from './reportShares'

const base = (extra: Partial<SharedReportData> = {}): SharedReportData => ({
  report: { name: 'تقرير', template: 'client', days: 30, from: '2026-10-01', to: '2026-10-30', generated_at: 'now', currency: 'SAR', compare: false, refresh_seconds: 60 },
  sections: ['kpi_volume', 'campaigns'],
  workspace: { name: 'شركة', color: null, logo_url: null },
  ...extra,
})

describe('mergeParts', () => {
  it('keeps the first half as the report and adds the sections of the second', () => {
    const first = base({ kpis: { leads: 4 } })
    const second = base({ sections: ['campaigns'], campaigns: { total: 1, rows: [] } })

    const merged = mergeParts(first, second)

    expect(merged.kpis).toEqual({ leads: 4 })
    expect(merged.campaigns).toEqual({ total: 1, rows: [] })
    expect(merged.sections).toEqual(['kpi_volume', 'campaigns'])
    expect(merged.report.name).toBe('تقرير')
  })

  it('is the first half alone until the second arrives', () => {
    const first = base({ kpis: { leads: 4 } })
    expect(mergeParts(first, null)).toBe(first)
  })

  it('never lets the second half overwrite the header, the order or the link’s permissions', () => {
    const first = base({ link: { allow_export: true, exports: { csv: true, xlsx: false, pdf: false } } })
    const second = base({ sections: ['x' as never], link: { allow_export: false, exports: { csv: false, xlsx: false, pdf: false } }, workspace: { name: 'آخر', color: null, logo_url: null } })

    const merged = mergeParts(first, second)

    expect(merged.link?.allow_export).toBe(true)
    expect(merged.workspace.name).toBe('شركة')
    expect(merged.sections).toEqual(['kpi_volume', 'campaigns'])
  })
})
