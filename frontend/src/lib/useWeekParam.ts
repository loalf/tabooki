import { useSearchParams } from 'react-router'
import { isValidIsoDate, mondayOf, today } from '@/lib/week'

/** The week in the URL (?week=YYYY-MM-DD, any day of it), this week by default. */
export function useWeekParam() {
  const [params, setParams] = useSearchParams()
  const thisWeek = mondayOf(today())
  const requested = params.get('week')
  const week = isValidIsoDate(requested) ? mondayOf(requested) : thisWeek
  const go = (monday: string) =>
    setParams(
      (current) => {
        const next = new URLSearchParams(current)
        if (monday === thisWeek) next.delete('week')
        else next.set('week', monday)
        return next
      },
      { replace: true },
    )
  return { week, thisWeek, go }
}
