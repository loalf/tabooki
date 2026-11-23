import { ChevronDown, Minus, Plus, ShieldAlert, X } from 'lucide-react'
import { type ReactNode, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { Diet, TasteImpact, TasteRules } from '@/api/taste'
import { Button, Chip, textareaClass } from '@/components/ui'
import { HEAVINESS } from '@/lib/facts'
import { cn } from '@/lib/utils'
import { type RuleLine, ruleLines } from '@/lib/taste'

/**
 * The household's rules, one line each, with how many recipes each leaves out. When it can be
 * changed (onChange), a line opens to edit it (a dislike or an allergy, how many times a week…)
 * or goes with its ×; so do the notes.
 */
export function RuleList({
  rules,
  impact,
  notes,
  onChange,
  onNotesChange,
}: {
  rules: TasteRules
  impact?: TasteImpact
  notes?: string | null
  onChange?: (rules: TasteRules) => void
  onNotesChange?: (notes: string | null) => void
}) {
  const { t, i18n } = useTranslation()
  const [open, setOpen] = useState<string | null>(null)
  const lines = ruleLines(rules, impact, t, i18n.language)
  // How the saved notes judged the library (not on a draft: nothing is judged until it's saved).
  const notesDetail = impact?.notesPending
    ? t('taste.notesPending', { count: impact.notesPending })
    : impact?.notesGood != null && impact.notesPoor != null
      ? t('taste.notesJudged', { good: impact.notesGood, poor: impact.notesPoor })
      : undefined

  if (!lines.length && !notes) return <p className="py-2 text-sm text-muted-foreground">{t('taste.noRules')}</p>

  const toggle = (id: string) => setOpen(open === id ? null : id)

  return (
    <ul className="flex flex-col divide-y-2 divide-outline/15">
      {lines.map((line) => (
        <Line
          key={line.id}
          firm={line.firm}
          detail={line.removes ? t('taste.removes', { count: line.removes, total: impact?.library ?? 0 }) : undefined}
          open={open === line.id}
          onToggle={onChange ? () => toggle(line.id) : undefined}
          onRemove={onChange ? () => onChange(line.without()) : undefined}
          editor={onChange && <RuleEditor rules={rules} line={line} onChange={onChange} />}
        >
          {line.text}
        </Line>
      ))}
      {notes && (
        <Line
          detail={notesDetail}
          open={open === 'notes'}
          onToggle={onNotesChange ? () => toggle('notes') : undefined}
          onRemove={onNotesChange ? () => onNotesChange(null) : undefined}
          editor={onNotesChange && <NotesEditor notes={notes} onSave={(text) => (onNotesChange(text), setOpen(null))} />}
        >
          <span className="font-semibold">{t('taste.notes')}:</span> {notes}
        </Line>
      )}
    </ul>
  )
}

function Line({
  children,
  detail,
  firm,
  open,
  onToggle,
  onRemove,
  editor,
}: {
  children: ReactNode
  detail?: string
  firm?: boolean
  open?: boolean
  onToggle?: () => void
  onRemove?: () => void
  editor?: ReactNode
}) {
  const { t } = useTranslation()
  const body = (
    <>
      <span className="flex items-start gap-1.5 text-[15px] leading-snug font-semibold">
        {firm && <ShieldAlert className="mt-0.5 size-4 shrink-0 text-destructive" aria-label={t('taste.firm')} />}
        <span>{children}</span>
        {onToggle && <ChevronDown className={cn('mt-0.5 size-4 shrink-0 text-muted-foreground transition-transform', open && 'rotate-180')} aria-hidden />}
      </span>
      {detail && <span className={cn('block text-xs text-muted-foreground', firm && 'pl-5.5')}>{detail}</span>}
    </>
  )
  return (
    <li className="py-2.5">
      <div className="flex items-start gap-2">
        {onToggle ? (
          <button type="button" onClick={onToggle} aria-expanded={open} className="min-w-0 flex-1 text-left">
            {body}
          </button>
        ) : (
          <span className="min-w-0 flex-1">{body}</span>
        )}
        {onRemove && (
          <button type="button" onClick={onRemove} className="-mr-2 grid size-9 shrink-0 place-items-center text-muted-foreground" aria-label={t('taste.review.remove')}>
            <X className="size-4" aria-hidden />
          </button>
        )}
      </div>
      {open && editor && <div className="mt-2.5">{editor}</div>}
    </li>
  )
}

/** What can be changed in a rule, short of removing it. */
function RuleEditor({ rules, line, onChange }: { rules: TasteRules; line: RuleLine; onChange: (rules: TasteRules) => void }) {
  const { t } = useTranslation()
  const i = line.index

  switch (line.kind) {
    case 'diet':
      return (
        <Choices
          value={rules.diet}
          options={['pescatarian', 'vegetarian', 'vegan'] as Diet[]}
          label={(diet) => t(`taste.rule.diet.${diet}`)}
          onChange={(diet) => onChange({ ...rules, diet })}
        />
      )
    case 'group':
    case 'allergen':
    case 'ingredient': {
      const key = ({ group: 'excludeGroups', allergen: 'excludeAllergens', ingredient: 'excludeIngredients' } as const)[line.kind]
      const list: { strength: 'never' | 'avoid' }[] = rules[key]
      const set = (strength: 'never' | 'avoid') => onChange({ ...rules, [key]: list.map((rule, j) => (j === i ? { ...rule, strength } : rule)) })
      return <Choices value={list[i]?.strength} options={['avoid', 'never'] as const} label={(s) => t(`taste.q.avoid.${s}`)} onChange={set} />
    }
    case 'weekly': {
      const rule = rules.weekly[i]
      const set = (field: 'max' | 'min', value: number) =>
        onChange({ ...rules, weekly: rules.weekly.map((r, j) => (j === i ? { ...r, [field]: Math.min(14, Math.max(1, value)) } : r)) })
      return (
        <div className="flex flex-col gap-2">
          {rule?.max != null && <Stepper label={t('taste.editRule.atMost')} value={rule.max} onChange={(v) => set('max', v)} />}
          {rule?.min != null && <Stepper label={t('taste.editRule.atLeast')} value={rule.min} onChange={(v) => set('min', v)} />}
        </div>
      )
    }
    case 'meals':
      return (
        <div className="flex flex-col gap-2">
          {(['lunch', 'dinner'] as const).map((slot) => (
            <div key={slot} className="flex flex-col gap-1">
              <span className="text-xs font-semibold text-muted-foreground">{t(`recipe.meal.${slot}`)}</span>
              <Choices
                value={rules.mealHeaviness[slot]}
                options={[null, ...HEAVINESS]}
                label={(h) => (h ? t(`recipe.facts.heaviness.${h}`) : t('taste.editRule.any'))}
                onChange={(h) => onChange({ ...rules, mealHeaviness: { ...rules.mealHeaviness, [slot]: h } })}
              />
            </div>
          ))}
        </div>
      )
    case 'prefer':
      return (
        <Choices
          value={rules.prefer[i]?.weight}
          options={['more', 'less'] as const}
          label={(w) => t(`taste.editRule.${w}`)}
          onChange={(weight) => onChange({ ...rules, prefer: rules.prefer.map((r, j) => (j === i ? { ...r, weight } : r)) })}
        />
      )
    case 'spice':
      return <Choices value={rules.maxSpice} options={[0, 1]} label={(s) => t(`taste.rule.spice.${s}`)} onChange={(maxSpice) => onChange({ ...rules, maxSpice })} />
  }
}

function Choices<T extends string | number | null>({ value, options, label, onChange }: { value: T | undefined; options: readonly T[]; label: (value: T) => string; onChange: (value: T) => void }) {
  return (
    <div className="flex flex-wrap gap-2">
      {options.map((option) => (
        <Chip key={String(option)} active={value === option} onClick={() => onChange(option)}>
          {label(option)}
        </Chip>
      ))}
    </div>
  )
}

function Stepper({ label, value, onChange }: { label: string; value: number; onChange: (value: number) => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex items-center justify-between gap-3">
      <span className="text-sm font-semibold">{label}</span>
      <div className="flex items-center gap-1" role="group" aria-label={label}>
        <Button variant="secondary" size="icon" className="size-9" onClick={() => onChange(value - 1)} disabled={value <= 1} aria-label={t('taste.editRule.fewer')}>
          <Minus className="size-4" aria-hidden />
        </Button>
        <span className="w-14 text-center text-sm font-bold tabular-nums">{t('taste.q.protein.times', { count: value })}</span>
        <Button variant="secondary" size="icon" className="size-9" onClick={() => onChange(value + 1)} disabled={value >= 14} aria-label={t('taste.editRule.more_')}>
          <Plus className="size-4" aria-hidden />
        </Button>
      </div>
    </div>
  )
}

function NotesEditor({ notes, onSave }: { notes: string; onSave: (notes: string | null) => void }) {
  const { t } = useTranslation()
  const [text, setText] = useState(notes)
  return (
    <div className="flex flex-col gap-2">
      <textarea rows={3} value={text} onChange={(e) => setText(e.target.value)} maxLength={2000} aria-label={t('taste.notes')} className={textareaClass} />
      <Button size="sm" className="self-end" disabled={text.trim() === notes.trim()} onClick={() => onSave(text.trim() || null)}>
        {t('common.save')}
      </Button>
    </div>
  )
}
