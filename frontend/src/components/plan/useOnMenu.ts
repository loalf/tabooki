import { useMemo } from 'react'
import { useMealPlan } from '@/api/plan'
import { addDays, mondayOf, today } from '@/lib/week'

/**
 * When each recipe is next on the menu, from today on, this week or next (the weeks Hoy already
 * loads): recipe id → date. Leftovers days don't count: that's not cooking it.
 */
export function useOnMenu(): ReadonlyMap<string, string> {
  const now = today()
  const thisWeek = mondayOf(now)
  const current = useMealPlan(thisWeek)
  const next = useMealPlan(addDays(thisWeek, 7))
  return useMemo(() => {
    const dates = new Map<string, string>()
    for (const entry of [...(current.data?.entries ?? []), ...(next.data?.entries ?? [])]) {
      const id = entry.recipe?.id
      if (!id || !entry.date || entry.date < now || entry.leftoverOfId) continue
      const known = dates.get(id)
      if (!known || entry.date < known) dates.set(id, entry.date)
    }
    return dates
  }, [current.data, next.data, now])
}
