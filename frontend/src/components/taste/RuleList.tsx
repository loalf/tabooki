import { ShieldAlert, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { TasteImpact, TasteRules } from '@/api/taste'
import { cn } from '@/lib/utils'
import { ruleLines } from '@/lib/taste'

/**
 * The household's rules, one line each, with how many recipes each leaves out. On the review
 * screen each line can be removed; elsewhere it's read-only.
 */
export function RuleList({
  rules,
  impact,
  notes,
  onChange,
  onRemoveNotes,
}: {
  rules: TasteRules
  impact?: TasteImpact
  notes?: string | null
  onChange?: (rules: TasteRules) => void
  onRemoveNotes?: () => void
}) {
  const { t, i18n } = useTranslation()
  const lines = ruleLines(rules, impact, t, i18n.language)
  // How the saved notes judged the library (not on a draft: nothing is judged until it's saved).
  const notesDetail = impact?.notesPending
    ? t('taste.notesPending', { count: impact.notesPending })
    : impact?.notesGood != null && impact.notesPoor != null
      ? t('taste.notesJudged', { good: impact.notesGood, poor: impact.notesPoor })
      : undefined

  if (!lines.length && !notes) return <p className="text-sm text-muted-foreground">{t('taste.noRules')}</p>

  return (
    <ul className="flex flex-col divide-y-2 divide-outline/15">
      {lines.map((line) => (
        <Line
          key={line.id}
          firm={line.firm}
          detail={line.removes ? t('taste.removes', { count: line.removes, total: impact?.library ?? 0 }) : undefined}
          onRemove={onChange ? () => onChange(line.without()) : undefined}
        >
          {line.text}
        </Line>
      ))}
      {notes && (
        <Line onRemove={onRemoveNotes} detail={notesDetail}>
          <span className="font-semibold">{t('taste.notes')}:</span> {notes}
        </Line>
      )}
    </ul>
  )
}

function Line({ children, detail, firm, onRemove }: { children: React.ReactNode; detail?: string; firm?: boolean; onRemove?: () => void }) {
  const { t } = useTranslation()
  return (
    <li className="flex items-start gap-2 py-2.5">
      <span className="min-w-0 flex-1">
        <span className="flex items-start gap-1.5 text-[15px] leading-snug font-semibold">
          {firm && <ShieldAlert className="mt-0.5 size-4 shrink-0 text-destructive" aria-label={t('taste.firm')} />}
          <span>{children}</span>
        </span>
        {detail && <span className={cn('block text-xs text-muted-foreground', firm && 'pl-5.5')}>{detail}</span>}
      </span>
      {onRemove && (
        <button type="button" onClick={onRemove} className="-mr-2 grid size-9 shrink-0 place-items-center text-muted-foreground" aria-label={t('taste.review.remove')}>
          <X className="size-4" aria-hidden />
        </button>
      )}
    </li>
  )
}
