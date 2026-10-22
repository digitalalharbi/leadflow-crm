import { describe, expect, it } from 'vitest'
import { arcs, brandRamp, foldSlivers, clampPercent, funnelRows, healthTone, rank, STATUS, type StatusKey } from './index'

describe('visual primitives', () => {
  it('drops empty and non-finite donut segments and sums fractions to one', () => {
    const result = arcs([
      { key: 'a', value: 30 },
      { key: 'b', value: 0 },
      { key: 'c', value: Number.NaN },
      { key: 'd', value: 70 },
    ])
    expect(result.map((a) => a.key)).toEqual(['a', 'd'])
    expect(result.reduce((sum, a) => sum + a.fraction, 0)).toBeCloseTo(1)
    expect(result[1].start).toBeCloseTo(0.3)
  })

  it('clamps progress into 0–100', () => {
    expect(clampPercent(-5)).toBe(0)
    expect(clampPercent(140)).toBe(100)
    expect(clampPercent(Number.NaN)).toBe(0)
    expect(clampPercent(42)).toBe(42)
  })

  it('narrows the funnel against the first step and reports step-to-step keep', () => {
    const rows = funnelRows([
      { key: 'n', label: 'New', count: 100 },
      { key: 'c', label: 'Contacted', count: 50 },
      { key: 'w', label: 'Won', count: 0 },
    ])
    expect(rows.map((r) => r.width)).toEqual([100, 50, 0])
    expect(rows.map((r) => r.keep)).toEqual([null, 50, 0])
  })

  it('keeps a tiny step visible and survives an empty funnel', () => {
    expect(funnelRows([{ key: 'n', label: 'N', count: 1000 }, { key: 'c', label: 'C', count: 1 }])[1].width).toBe(8)
    expect(funnelRows([])).toEqual([])
  })

  it('ranks high to low, caps the list and scales bars to the leader', () => {
    const rows = rank(
      [
        { key: 'a', label: 'A', value: 10 },
        { key: 'b', label: 'B', value: 40 },
        { key: 'c', label: 'C', value: 20 },
      ],
      2,
    )
    expect(rows.map((r) => r.key)).toEqual(['b', 'c'])
    expect(rows[0].width).toBe(100)
    expect(rows[1].width).toBe(50)
  })

  it('maps every status to a tone and icon', () => {
    const keys: StatusKey[] = ['healthy', 'attention', 'risk', 'overdue', 'pending', 'completed', 'active', 'paused', 'today']
    for (const key of keys) {
      expect(STATUS[key].tone).toBeTruthy()
      expect(STATUS[key].Icon).toBeTruthy()
    }
    expect(Object.keys(STATUS)).toHaveLength(9)
  })

  it('bands health into good / warn / bad and builds a pale-to-deep ramp', () => {
    expect(healthTone(90)).toBe('good')
    expect(healthTone(60)).toBe('warn')
    expect(healthTone(10)).toBe('bad')
    const ramp = brandRamp(4)
    expect(ramp).toHaveLength(4)
    expect(ramp[3]).toContain('100%')
  })
})

describe('foldSlivers', () => {
  const others = { key: 'o', value: 0 }

  it('folds two or more slivers into one arc when the ring is crowded', () => {
    const segs = [
      { key: 'a', value: 50 }, { key: 'b', value: 30 }, { key: 'c', value: 14 },
      { key: 'd', value: 3 }, { key: 'e', value: 2 }, { key: 'f', value: 1 },
    ]
    const folded = foldSlivers(segs, others)
    expect(folded.map((s) => s.key)).toEqual(['a', 'b', 'c', 'o'])
    expect(folded[3].value).toBe(6)
  })

  it('leaves a short list alone, slivers and all', () => {
    const segs = [{ key: 'a', value: 97 }, { key: 'b', value: 2 }, { key: 'c', value: 1 }]
    expect(foldSlivers(segs, others)).toBe(segs)
  })
})

describe('heatShade', () => {
  it('is pale for little and heavy for most, and never leaves the scale', async () => {
    const { heatShade } = await import('./HeatGrid')

    expect(heatShade(0)).toBe(8)
    expect(heatShade(100)).toBe(88)
    expect(heatShade(-20)).toBe(8)
    expect(heatShade(250)).toBe(88)
    expect(heatShade(50)).toBeGreaterThan(heatShade(10))
  })
})
