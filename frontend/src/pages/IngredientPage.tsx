import { ArrowLeft, ArrowLeftRight, Check, LoaderCircle, Search, ShieldAlert, Undo2 } from 'lucide-react'
import { type ReactNode, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { ApiError } from '@/api/client'
import { type FoodDetail, foodName, ingredientName, useFoodSearch, useIngredient, useRepointIngredient } from '@/api/ingredients'
import { EmptyState, PageHeader } from '@/components/PageHeader'
import { Button, inputClass } from '@/components/ui'
import { useDebounced } from '@/lib/useDebounced'
import { cn } from '@/lib/utils'

/**
 * One of the household's ingredients: the food it is (figures per 100 g, allergens, weights,
 * substitutes), the recipes that use it, and pointing it at another food when it's wrong.
 */
export function IngredientPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const { id = '' } = useParams()
  const ingredient = useIngredient(id)
  const repoint = useRepointIngredient(id)
  const [choosing, setChoosing] = useState(false)

  const back = (
    <button type="button" onClick={() => void navigate('/settings/ingredients')} className="mb-3 inline-flex h-9 items-center gap-1 text-[15px] font-bold">
      <ArrowLeft className="size-4" aria-hidden />
      {t('ingredients.title')}
    </button>
  )
  if (ingredient.isPending) return <>{back}<div className="h-60 animate-pulse rounded-lg bg-muted" aria-hidden /></>
  if (ingredient.isError || !ingredient.data)
    return <>{back}<EmptyState>{ingredient.error instanceof ApiError && ingredient.error.status === 404 ? t('ingredients.notFound') : t('ingredients.loadError')}</EmptyState></>

  const data = ingredient.data
  const food = data.food
  const choose = (foodId: string) => repoint.mutate(foodId, { onSuccess: () => setChoosing(false) })

  return (
    <>
      {back}
      <PageHeader title={ingredientName(data, i18n.language)} className="mb-1 first-letter:uppercase" />
      <p className="mb-4 text-sm text-muted-foreground">
        {food ? t('ingredients.is', { food: foodName(food, i18n.language), key: food.key }) : t('ingredients.noFood')}
      </p>

      <div className="flex flex-col gap-4">
        {food && <FoodCard food={food} />}

        <section aria-labelledby="repoint-title" className="rounded-lg border-2 border-outline bg-card p-3.5">
          <h2 id="repoint-title" className="text-sm font-extrabold">{t('ingredients.repoint.title')}</h2>
          <p className="mt-1 text-sm text-muted-foreground">{t('ingredients.repoint.hint')}</p>
          <div className="mt-2.5 flex flex-wrap gap-2">
            {!choosing && (
              <Button variant="secondary" size="sm" onClick={() => setChoosing(true)}>
                <ArrowLeftRight className="size-4" aria-hidden />
                {t('ingredients.repoint.choose')}
              </Button>
            )}
            {data.keyFoodId && (
              <Button variant="secondary" size="sm" disabled={repoint.isPending} onClick={() => choose(data.keyFoodId!)}>
                <Undo2 className="size-4" aria-hidden />
                {t('ingredients.repoint.back', { key: data.canonicalKey })}
              </Button>
            )}
          </div>
          {choosing && <FoodPicker current={food?.id ?? null} busy={repoint.isPending} onPick={choose} onCancel={() => setChoosing(false)} />}
          {repoint.isError && <p className="mt-2 text-sm text-destructive">{t('ingredients.repoint.error')}</p>}
        </section>

        {!!data.recipes?.length && (
          <section aria-labelledby="used-in-title">
            <h2 id="used-in-title" className="mb-1.5 text-sm font-extrabold">{t('ingredients.usedIn', { count: data.recipes.length })}</h2>
            <ul className="flex flex-col divide-y-2 divide-outline/15 rounded-lg sticker bg-card">
              {data.recipes.map((recipe) => (
                <li key={recipe.id}>
                  <Link to={`/recipes/${recipe.id}`} className="block truncate px-3.5 py-2.5 text-[15px] font-semibold">{recipe.title}</Link>
                </li>
              ))}
            </ul>
          </section>
        )}
      </div>
    </>
  )
}

/** What Tabooki knows about the food: per 100 g, allergens and groups, weights, substitutes, and where it comes from. */
function FoodCard({ food }: { food: FoodDetail }) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language.startsWith('en') ? 'en' : 'es'
  const number = (value: number, digits = 1) => new Intl.NumberFormat(i18n.language, { maximumFractionDigits: digits }).format(value)
  if (!food.described) {
    return <p className="rounded-lg border-2 border-dashed border-muted-foreground/60 p-3.5 text-sm text-muted-foreground">{t('ingredients.pending')}</p>
  }
  const rows: { label: string; value: number | null | undefined; sub?: boolean; digits?: number }[] = [
    { label: t('recipe.nutrition.protein'), value: food.protein },
    { label: t('recipe.nutrition.carbs'), value: food.carbs },
    { label: t('recipe.nutrition.sugar'), value: food.sugar, sub: true },
    { label: t('recipe.nutrition.fat'), value: food.fat },
    { label: t('recipe.nutrition.saturatedFat'), value: food.saturatedFat, sub: true },
    { label: t('recipe.nutrition.fiber'), value: food.fiber },
    { label: t('recipe.nutrition.salt'), value: food.salt, digits: 2 },
  ]
  const weights = [
    food.gramsPerPiece != null ? t('ingredients.perPiece', { grams: number(food.gramsPerPiece, 0) }) : null,
    food.density != null ? t('ingredients.perSpoon', { grams: number(food.density * 15, 0) }) : null,
  ].filter(Boolean)

  return (
    <section aria-labelledby="food-title" className="rounded-lg border-2 border-outline bg-card p-3.5">
      <div className="flex items-baseline justify-between gap-3">
        <h2 id="food-title" className="text-sm font-extrabold">{t('ingredients.per100')}</h2>
        {food.kcal != null && <p className="text-lg font-extrabold tabular-nums">{number(food.kcal, 0)} kcal</p>}
      </div>
      <dl className="mt-2 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
        {rows.map((row) => (
          <div key={row.label} className="contents">
            <dt className={row.sub ? 'pl-3 text-muted-foreground' : undefined}>{row.label}</dt>
            <dd className={cn('text-right tabular-nums', row.sub ? 'text-muted-foreground' : 'font-semibold')}>{row.value != null ? `${number(row.value, row.digits ?? 1)} g` : '–'}</dd>
          </div>
        ))}
      </dl>

      <Facts>
        {food.allergens?.length ? (
          <p className="flex items-start gap-1.5">
            <ShieldAlert className="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden />
            <span>
              <span className="font-semibold">{t('recipe.allergens')}:</span> {food.allergens.map((a) => t(`recipe.allergen.${a}`)).join(', ')}
            </span>
          </p>
        ) : (
          <p>{t('ingredients.noAllergens')}</p>
        )}
        {!!food.foodGroups?.length && (
          <p>
            <span className="font-semibold">{t('ingredients.groups')}:</span> {food.foodGroups.map((g) => t(`recipe.facts.group.${g}`)).join(', ')}
          </p>
        )}
        {(food.spice ?? 0) > 0 && <p>{t(`ingredients.spice.${food.spice}`)}</p>}
        {weights.length > 0 && <p>{weights.join(' · ')}</p>}
      </Facts>

      {!!food.substitutes?.length && (
        <Facts>
          <p className="font-semibold">{t('recipe.substitutes.title')}</p>
          <ul className="flex flex-col gap-0.5">
            {food.substitutes.map((s) => (
              <li key={s.key}>
                <span className="first-letter:uppercase">{s.name[lang]}</span>
                {s.ratio !== 1 && <span className="text-muted-foreground"> · {t('recipe.substitutes.ratio', { ratio: number(s.ratio, 2) })}</span>}
                {s.note && <span className="text-muted-foreground"> · {s.note[lang]}</span>}
              </li>
            ))}
          </ul>
        </Facts>
      )}

      <p className="mt-3 text-xs text-muted-foreground">{t(`ingredients.source.${food.source ?? 'llm'}`)}</p>
    </section>
  )
}

function Facts({ children }: { children: ReactNode }) {
  return <div className="mt-3 flex flex-col gap-1 border-t-2 border-outline/15 pt-3 text-sm">{children}</div>
}

/** Search the shared foods and pick one. */
function FoodPicker({ current, busy, onPick, onCancel }: { current: string | null; busy: boolean; onPick: (id: string) => void; onCancel: () => void }) {
  const { t, i18n } = useTranslation()
  const [query, setQuery] = useState('')
  const debounced = useDebounced(query)
  const foods = useFoodSearch(debounced)

  return (
    <div className="mt-3 flex flex-col gap-2">
      <label className="relative block">
        <span className="sr-only">{t('ingredients.repoint.search')}</span>
        <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden />
        <input type="search" autoFocus value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t('ingredients.repoint.search')} className={cn(inputClass, 'pl-10')} />
      </label>
      {debounced.trim().length >= 2 && foods.data?.length === 0 && <p className="text-sm text-muted-foreground">{t('ingredients.repoint.none')}</p>}
      {!!foods.data?.length && (
        <ul className="flex max-h-72 flex-col divide-y overflow-y-auto rounded-md border-2 border-outline/30">
          {foods.data.map((food) => (
            <li key={food.id}>
              <button
                type="button"
                disabled={busy || food.id === current}
                onClick={() => onPick(food.id!)}
                className="flex w-full items-center gap-3 px-3 py-2 text-left disabled:opacity-60"
              >
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-semibold first-letter:uppercase">{foodName(food, i18n.language)}</span>
                  <span className="block truncate text-xs text-muted-foreground">
                    {food.key}
                    {food.kcal != null && ` · ${t('ingredients.kcal', { kcal: Math.round(food.kcal) })}`}
                  </span>
                </span>
                {food.id === current ? <Check className="size-4 text-primary" aria-label={t('ingredients.repoint.current')} /> : busy ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : null}
              </button>
            </li>
          ))}
        </ul>
      )}
      <Button variant="ghost" size="sm" className="self-start" onClick={onCancel}>
        {t('common.cancel')}
      </Button>
    </div>
  )
}
