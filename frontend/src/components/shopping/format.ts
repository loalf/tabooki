import type { TFunction } from 'i18next'
import type { Amount } from '@/api/shopping'

/** "600 g", "2 latas", "3" (counted items). */
export function formatAmount(amount: Amount, t: TFunction, locale: string): string {
  const n = new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(amount.quantity)
  if (amount.unit === null) return n
  if (['g', 'kg', 'ml', 'l'].includes(amount.unit)) return `${n} ${amount.unit}`
  return t(`shopping.unit.${amount.unit}`, { count: amount.quantity, n })
}
