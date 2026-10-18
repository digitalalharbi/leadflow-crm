import type { ClassificationKey } from '@/types/api'

/**
 * A colour per classification, from tokens only.
 *
 * Hot / warm / cold borrow the temperature tokens so a lead is the same colour
 * wherever it is drawn; the rest are the tone tokens. Priority is the brand
 * colour — it is the one the product wants looked at first.
 */
export const CLASSIFICATION_COLOR: Record<ClassificationKey, string> = {
  priority: 'var(--brand)',
  hot: 'var(--color-temp-hot)',
  warm: 'var(--color-temp-warm)',
  cold: 'var(--color-temp-cold)',
  new: 'var(--color-status-new)',
  qualified: 'var(--color-status-qualified)',
  unqualified: 'var(--color-status-invalid)',
}

/** Display order: the ones to act on first. */
export const CLASSIFICATION_ORDER: ClassificationKey[] = ['priority', 'hot', 'warm', 'new', 'cold', 'qualified', 'unqualified']
