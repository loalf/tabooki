import { ArrowLeft, Plus } from 'lucide-react'
import { type FormEvent, type ReactNode, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { Diet, Strength, TasteAnswers } from '@/api/taste'
import { DictateButton } from '@/components/DictateButton'
import { Button, Chip, CheckMark, inputClass, textareaClass } from '@/components/ui'
import type { FoodGroup } from '@/lib/facts'
import { cn } from '@/lib/utils'

const STEPS = ['diet', 'avoid', 'protein', 'meals', 'more', 'spice', 'other'] as const
type Step = (typeof STEPS)[number]

/** Food groups people usually leave out, in the order they think of them. */
const AVOID_GROUPS: FoodGroup[] = ['fish', 'shellfish', 'pork', 'red_meat', 'poultry', 'processed_meat', 'cheese', 'dairy', 'egg', 'gluten', 'tree_nuts', 'peanuts', 'soy', 'sesame', 'alcohol']
/** Common dislikes that aren't food groups: the model maps them onto ingredients. */
const AVOID_OTHERS = ['mushrooms', 'coriander', 'olives', 'onion', 'eggplant'] as const
const MORE = ['legumes', 'vegetables', 'salads', 'soups', 'rice', 'pasta', 'fish', 'asian', 'mexican', 'italian'] as const

/**
 * The questions that build the household's likings (docs/PLAN.md §6.1): one per screen, all
 * tappable and skippable, with a free-text one at the end. Starts from the answers given last time.
 */
export function Questionnaire({ initial, onDone, onCancel }: { initial: TasteAnswers; onDone: (answers: TasteAnswers) => void; onCancel?: () => void }) {
  const { t } = useTranslation()
  const [step, setStep] = useState(0)
  const [answers, setAnswers] = useState<TasteAnswers>(initial)
  const set = (patch: Partial<TasteAnswers>) => setAnswers((current) => ({ ...current, ...patch }))
  const name: Step = STEPS[step]
  const last = step === STEPS.length - 1

  const next = () => (last ? onDone(answers) : setStep(step + 1))
  const back = () => (step === 0 ? onCancel?.() : setStep(step - 1))

  return (
    <section className="flex flex-col gap-5" aria-labelledby="question-title">
      <div className="flex items-center gap-1.5" aria-label={t('taste.q.progress', { step: step + 1, total: STEPS.length })}>
        {STEPS.map((s, i) => (
          <span key={s} className={cn('h-1.5 flex-1 rounded-full', i <= step ? 'bg-primary' : 'bg-muted')} />
        ))}
      </div>

      <header>
        <h2 id="question-title" className="text-[1.375rem] leading-tight font-extrabold text-balance">
          {t(`taste.q.${name}.title`)}
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">{t(`taste.q.${name}.hint`)}</p>
      </header>

      {name === 'diet' && (
        <Options
          value={answers.diet ?? null}
          options={[null, 'pescatarian', 'vegetarian', 'vegan'] as (Diet | null)[]}
          label={(value) => t(`taste.q.diet.${value ?? 'omnivore'}`)}
          onChange={(diet) => set({ diet })}
        />
      )}
      {name === 'avoid' && <Avoid value={answers.avoid ?? []} onChange={(avoid) => set({ avoid })} />}
      {name === 'protein' && (
        <div className="flex flex-col gap-5">
          <Options
            value={answers.animalProteinMax ?? null}
            options={[null, 2, 4]}
            label={(value) => (value == null ? t('taste.q.protein.noLimit') : t('taste.q.protein.atMost', { count: value }))}
            onChange={(animalProteinMax) => set({ animalProteinMax })}
          />
          {answers.animalProteinMax != null && (
            <button type="button" onClick={() => set({ eggsAndDairyCount: !answers.eggsAndDairyCount })} className="flex items-center gap-3 text-left text-[15px] font-semibold" aria-pressed={!!answers.eggsAndDairyCount}>
              <CheckMark checked={!!answers.eggsAndDairyCount} />
              {t('taste.q.protein.eggsAndDairy')}
            </button>
          )}
          <div className="flex flex-col gap-2">
            <span className="text-sm font-semibold">{t('taste.q.protein.fishMin')}</span>
            <div className="flex flex-wrap gap-2">
              {[null, 1, 2, 3].map((value) => (
                <Chip key={value ?? 0} active={(answers.fishMin ?? null) === value} onClick={() => set({ fishMin: value })}>
                  {value == null ? t('taste.q.protein.fishAny') : t('taste.q.protein.times', { count: value })}
                </Chip>
              ))}
            </div>
          </div>
        </div>
      )}
      {name === 'meals' && (
        <Options
          value={answers.meals ?? null}
          options={['heavyLunch', 'same', 'heavyDinner'] as const}
          label={(value) => t(`taste.q.meals.${value}`)}
          onChange={(meals) => set({ meals })}
        />
      )}
      {name === 'more' && (
        <div className="flex flex-wrap gap-2">
          {MORE.filter((item) => item !== 'fish' || !(answers.avoid ?? []).some((a) => a.item === 'fish')).map((item) => {
            const more = answers.more ?? []
            const on = more.includes(item)
            return (
              <Chip key={item} active={on} onClick={() => set({ more: on ? more.filter((m) => m !== item) : [...more, item] })}>
                {t(`taste.q.more.${item}`)}
              </Chip>
            )
          })}
        </div>
      )}
      {name === 'spice' && (
        <Options value={answers.spice ?? null} options={['none', 'mild', 'any'] as const} label={(value) => t(`taste.q.spice.${value}`)} onChange={(spice) => set({ spice })} />
      )}
      {name === 'other' && (
        <div className="flex flex-col gap-2">
          <textarea
            rows={5}
            value={answers.other ?? ''}
            onChange={(e) => set({ other: e.target.value })}
            placeholder={t('taste.q.other.placeholder')}
            aria-labelledby="question-title"
            maxLength={2000}
            className={textareaClass}
          />
          <DictateButton onText={(text) => set({ other: [answers.other?.trim(), text].filter(Boolean).join(' ') })} />
        </div>
      )}

      <div className="sticky bottom-[calc(4.5rem+env(safe-area-inset-bottom))] -mx-4 flex gap-2 border-t-2 border-outline bg-background px-4 py-3">
        <Button variant="secondary" size="icon" onClick={back} aria-label={t('common.back')} disabled={step === 0 && !onCancel}>
          <ArrowLeft className="size-5" aria-hidden />
        </Button>
        <Button className="flex-1" onClick={next}>
          {last ? t('taste.q.finish') : t('taste.q.next')}
        </Button>
      </div>
    </section>
  )
}

/** One choice of a few, as big rows (null is an option too: "no limit", "eat anything"). */
function Options<T extends string | number | null>({ value, options, label, onChange }: { value: T | null; options: readonly T[]; label: (value: T) => ReactNode; onChange: (value: T) => void }) {
  return (
    <div className="flex flex-col gap-2" role="radiogroup">
      {options.map((option) => (
        <button
          key={String(option)}
          type="button"
          role="radio"
          aria-checked={value === option}
          onClick={() => onChange(option)}
          className={cn('flex min-h-13 items-center gap-3 rounded-lg sticker-sm px-4 text-left text-[15px] font-bold', value === option ? 'bg-highlight text-highlight-foreground' : 'bg-card')}
        >
          <span className={cn('size-4 shrink-0 rounded-full border-2 border-outline', value === option && 'bg-outline')} aria-hidden />
          {label(option)}
        </button>
      ))}
    </div>
  )
}

type Avoided = NonNullable<TasteAnswers['avoid']>[number]

/** What they don't eat: tap the usual ones or type another, then say whether it's a dislike or an allergy. */
function Avoid({ value, onChange }: { value: Avoided[]; onChange: (value: Avoided[]) => void }) {
  const { t } = useTranslation()
  const [typed, setTyped] = useState('')
  const has = (item: string) => value.some((v) => v.item === item)
  const toggle = (item: string, custom: boolean) =>
    onChange(has(item) ? value.filter((v) => v.item !== item) : [...value, { item, strength: 'avoid', ...(custom ? { custom: true } : {}) }])
  const setStrength = (item: string, strength: Strength) => onChange(value.map((v) => (v.item === item ? { ...v, strength } : v)))
  const labelOf = (v: Avoided) => ((AVOID_GROUPS as string[]).includes(v.item) ? t(`recipe.facts.group.${v.item}`) : v.item)

  const add = (event: FormEvent) => {
    event.preventDefault()
    const item = typed.trim().toLowerCase()
    if (item && !has(item)) onChange([...value, { item, custom: true, strength: 'avoid' }])
    setTyped('')
  }

  const others = AVOID_OTHERS.map((key) => t(`taste.q.avoid.others.${key}`))
  const typedOnes = value.filter((v) => v.custom && !others.includes(v.item)).map((v) => v.item)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap gap-2">
        {AVOID_GROUPS.map((group) => (
          <Chip key={group} active={has(group)} onClick={() => toggle(group, false)}>
            {t(`recipe.facts.group.${group}`)}
          </Chip>
        ))}
        {[...others, ...typedOnes].map((item) => (
          <Chip key={item} active={has(item)} onClick={() => toggle(item, true)}>
            {item}
          </Chip>
        ))}
      </div>
      <form onSubmit={add} className="flex gap-2">
        <input value={typed} onChange={(e) => setTyped(e.target.value)} placeholder={t('taste.q.avoid.add')} aria-label={t('taste.q.avoid.add')} maxLength={40} className={cn(inputClass, 'flex-1')} />
        <Button type="submit" variant="secondary" size="icon" aria-label={t('taste.q.avoid.addButton')} disabled={!typed.trim()}>
          <Plus className="size-5" aria-hidden />
        </Button>
      </form>
      {value.length > 0 && (
        <ul className="flex flex-col gap-2">
          {value.map((v) => (
            <li key={v.item} className="flex items-center justify-between gap-3 rounded-lg sticker-sm bg-card px-3 py-2">
              <span className="min-w-0 truncate font-bold first-letter:uppercase">{labelOf(v)}</span>
              <span className="flex shrink-0 gap-1 rounded-md border-2 border-outline bg-muted p-0.5" role="group" aria-label={labelOf(v)}>
                {(['avoid', 'never'] as const).map((strength) => (
                  <button
                    key={strength}
                    type="button"
                    aria-pressed={v.strength === strength}
                    onClick={() => setStrength(v.item, strength)}
                    className={cn('h-8 rounded-sm px-2.5 text-xs font-bold', v.strength === strength ? 'border-2 border-outline bg-card' : 'text-muted-foreground')}
                  >
                    {t(`taste.q.avoid.${strength}`)}
                  </button>
                ))}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
