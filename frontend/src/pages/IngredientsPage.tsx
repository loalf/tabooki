import { AlertTriangle, ArrowLeft, ChevronRight, Search, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { type HouseholdIngredient, ingredientName, useIngredients } from '@/api/ingredients'
import { EmptyState, PageHeader } from '@/components/PageHeader'
import { inputClass } from '@/components/ui'
import { useGoBack } from '@/lib/useGoBack'
import { cn } from '@/lib/utils'
import { SearchText } from '@/lib/searchText'

/** Every ingredient the household's recipes use, with what Tabooki knows about it: to check it, or say it's another food. */
export function IngredientsPage() {
  const { t, i18n } = useTranslation()
  const back = useGoBack('/settings')
  const ingredients = useIngredients()
  const [query, setQuery] = useState('')

  const words = SearchText.words(query)
  const shown = (ingredients.data ?? [])
    .filter((i) => (i.recipeCount ?? 0) > 0)
    .map((i) => ({ ingredient: i, name: ingredientName(i, i18n.language) }))
    .filter(({ ingredient, name }) => {
      const text = SearchText.normalize(`${name} ${ingredient.canonicalKey?.replaceAll('_', ' ')} ${ingredient.food?.key?.replaceAll('_', ' ') ?? ''}`)
      return words.every((w) => text.includes(w))
    })
    .sort((a, b) => a.name.localeCompare(b.name, i18n.language))

  return (
    <>
      <button type="button" onClick={back} className="mb-3 inline-flex h-9 items-center gap-1 text-[15px] font-bold">
        <ArrowLeft className="size-4" aria-hidden />
        {t('settings.title')}
      </button>
      <PageHeader title={t('ingredients.title')} className="mb-1" />
      <p className="mb-4 text-sm text-muted-foreground">{t('ingredients.intro')}</p>

      <label className="relative mb-3 block">
        <span className="sr-only">{t('ingredients.search')}</span>
        <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden />
        <input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={t('ingredients.search')}
          className={cn(inputClass, 'pr-10 pl-10 [&::-webkit-search-cancel-button]:hidden')}
        />
        {query && (
          <button type="button" onClick={() => setQuery('')} className="absolute top-1/2 right-1 grid size-9 -translate-y-1/2 place-items-center text-muted-foreground" aria-label={t('library.clearSearch')}>
            <X className="size-5" />
          </button>
        )}
      </label>

      {ingredients.isPending ? (
        <div className="h-60 animate-pulse rounded-lg bg-muted" aria-hidden />
      ) : ingredients.isError ? (
        <EmptyState>{t('ingredients.loadError')}</EmptyState>
      ) : shown.length === 0 ? (
        <EmptyState>{query ? t('ingredients.noMatches') : t('ingredients.empty')}</EmptyState>
      ) : (
        <ul className="flex flex-col divide-y-2 divide-outline/15 rounded-lg sticker bg-card">
          {shown.map(({ ingredient, name }) => (
            <li key={ingredient.id}>
              <IngredientRow ingredient={ingredient} name={name} />
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

function IngredientRow({ ingredient, name }: { ingredient: HouseholdIngredient; name: string }) {
  const { t, i18n } = useTranslation()
  const food = ingredient.food
  const known = Boolean(food?.described)
  const elsewhere = food != null && food.key !== ingredient.canonicalKey
  const details = [
    t('ingredients.recipes', { count: ingredient.recipeCount ?? 0 }),
    known && food?.kcal != null ? t('ingredients.kcal', { kcal: new Intl.NumberFormat(i18n.language).format(Math.round(food.kcal)) }) : null,
    known && food?.allergens?.length ? food.allergens.map((a) => t(`recipe.allergen.${a}`)).join(', ') : null,
  ].filter(Boolean)

  return (
    <Link to={`/settings/ingredients/${ingredient.id}`} className="flex items-center gap-3 px-3.5 py-3">
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-2">
          <span className="truncate font-bold first-letter:uppercase">{name}</span>
          {elsewhere && <span className="shrink-0 rounded-full bg-highlight px-2 py-0.5 text-[11px] font-bold text-highlight-foreground">{t('ingredients.repointed')}</span>}
        </span>
        <span className="block truncate text-xs text-muted-foreground">{details.join(' · ')}</span>
      </span>
      {!known && (
        <span className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-muted-foreground">
          <AlertTriangle className="size-3.5" aria-hidden />
          {t('ingredients.unknown')}
        </span>
      )}
      <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />
    </Link>
  )
}
