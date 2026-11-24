import { CalendarCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { cn } from '@/lib/utils'
import { addDays, parseIsoDate, today } from '@/lib/week'

const badgeClass = 'inline-flex items-center gap-1 rounded-full border-2 border-outline bg-primary px-2 py-0.5 text-[11px] font-bold whitespace-nowrap text-primary-foreground'

/** "En el menú · jue": today, a weekday within the week ahead, or with its date beyond that. */
function OnMenuLabel({ date }: { date: string }) {
  const { t, i18n } = useTranslation()
  const now = today()
  const day =
    date === now
      ? t('plan.today')
      : new Intl.DateTimeFormat(i18n.language, date < addDays(now, 7) ? { weekday: 'short' } : { weekday: 'short', day: 'numeric' }).format(parseIsoDate(date))
  return (
    <>
      <CalendarCheck className="size-3 shrink-0" aria-hidden />
      {t('library.onMenu', { day: day.replace(/\.$/, '') })}
    </>
  )
}

export function OnMenuBadge({ date, className }: { date: string; className?: string }) {
  return (
    <span className={cn(badgeClass, className)}>
      <OnMenuLabel date={date} />
    </span>
  )
}

/** On the recipe page, among its tags: the same badge, opening that day on the menu. */
export function OnMenuLink({ date }: { date: string }) {
  return (
    <Link to={`/plan?week=${date}#day-${date}`} className={cn(badgeClass, 'h-7 px-2.5 text-xs')}>
      <OnMenuLabel date={date} />
    </Link>
  )
}
