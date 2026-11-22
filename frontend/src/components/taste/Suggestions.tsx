import { Lightbulb } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { type TasteSuggestion, useSuggestionChoice, useTasteSuggestions } from '@/api/taste'
import { Button } from '@/components/ui'

/**
 * What the household's swaps say it could add to its likings: "you swapped out 3 dishes with
 * cheese — rather without cheese?". Taking one changes the likings; "no" hides it for good.
 */
export function Suggestions() {
  const { t } = useTranslation()
  const { data: suggestions = [] } = useTasteSuggestions()
  const choice = useSuggestionChoice()
  if (!suggestions.length) return null

  return (
    <section aria-labelledby="suggestions-title" className="flex flex-col gap-3">
      <h2 id="suggestions-title" className="flex items-center gap-2 text-[17px] font-extrabold">
        <Lightbulb className="size-5 text-brand" aria-hidden />
        {t('taste.suggest.title')}
      </h2>
      {suggestions.map((suggestion) => (
        <div key={suggestion.key} className="flex flex-col gap-3 rounded-lg sticker bg-card p-4">
          <SuggestionText suggestion={suggestion} />
          {suggestion.examples && suggestion.examples.length > 0 && (
            <p className="-mt-1 text-xs text-muted-foreground">{t('taste.suggest.examples', { dishes: suggestion.examples.join(', ') })}</p>
          )}
          <div className="flex gap-2">
            <Button variant="secondary" size="sm" className="flex-1" disabled={choice.isPending} onClick={() => choice.mutate({ key: suggestion.key, accept: false })}>
              {t('taste.suggest.no')}
            </Button>
            <Button size="sm" className="flex-[2]" disabled={choice.isPending} onClick={() => choice.mutate({ key: suggestion.key, accept: true })}>
              {t('taste.suggest.yes')}
            </Button>
          </div>
        </div>
      ))}
    </section>
  )
}

function SuggestionText({ suggestion }: { suggestion: TasteSuggestion }) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language.startsWith('en') ? 'en' : 'es'
  const count = suggestion.count ?? 0
  const text = (() => {
    switch (suggestion.kind) {
      case 'excludeGroup':
        return t('taste.suggest.exclude', { count, what: t(`recipe.facts.group.${suggestion.group}`) })
      case 'excludeIngredient':
        return t('taste.suggest.exclude', { count, what: suggestion.name?.[lang] ?? '' })
      case 'mealHeaviness':
        return t(`taste.suggest.heaviness.${suggestion.heaviness}`, { count, meal: t(`recipe.meal.${suggestion.slot}`).toLowerCase() })
      default:
        return t('taste.suggest.note', { count, note: suggestion.note?.[lang] ?? '' })
    }
  })()
  return <p className="text-[15px] leading-snug font-semibold">{text}</p>
}
