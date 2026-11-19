import { addDays, parseIsoDate } from '@/lib/week'

/** "lunes 23" */
export function formatDay(date: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric' }).format(parseIsoDate(date))
}

/** "lunes" */
export function formatWeekday(date: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, { weekday: 'long' }).format(parseIsoDate(date))
}

/** "23–29 nov" (or "30 nov – 6 dic") */
export function formatWeek(monday: string, locale: string): string {
  const format = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' })
  return format.formatRange(parseIsoDate(monday), parseIsoDate(addDays(monday, 6)))
}
