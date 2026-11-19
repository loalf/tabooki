/** Calendar days as YYYY-MM-DD strings in local time (no time zones involved). Weeks start on Monday. */

export function toIsoDate(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

export function parseIsoDate(value: string): Date {
  const [year, month, day] = value.split('-').map(Number)
  return new Date(year, month - 1, day)
}

export function addDays(value: string, days: number): string {
  const date = parseIsoDate(value)
  date.setDate(date.getDate() + days)
  return toIsoDate(date)
}

export function mondayOf(value: string): string {
  const date = parseIsoDate(value)
  return addDays(value, -((date.getDay() + 6) % 7))
}

export function today(): string {
  return toIsoDate(new Date())
}

/** The seven days of the week starting on `monday`. */
export function weekDays(monday: string): string[] {
  return Array.from({ length: 7 }, (_, i) => addDays(monday, i))
}

/** Monday to Friday. */
export function isWeekday(value: string): boolean {
  const day = parseIsoDate(value).getDay()
  return day >= 1 && day <= 5
}

export function isValidIsoDate(value: string | null): value is string {
  return value !== null && /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(parseIsoDate(value).getTime())
}
