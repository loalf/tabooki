import { Apple } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { Recipe } from '@/api/library'

type Nutrition = NonNullable<Recipe['nutrition']>

/** What one serving brings, added up from the lines' weights and their foods: an estimate, and it says how complete. */
export function NutritionCard({ nutrition }: { nutrition: Nutrition }) {
  const { t, i18n } = useTranslation()
  const grams = (value: number) =>
    `${new Intl.NumberFormat(i18n.language, { maximumFractionDigits: value < 10 ? 1 : 0 }).format(value)} g`
  const rows: { label: string; value: string; sub?: boolean }[] = [
    { label: t('recipe.nutrition.protein'), value: grams(nutrition.protein) },
    { label: t('recipe.nutrition.carbs'), value: grams(nutrition.carbs) },
    { label: t('recipe.nutrition.sugar'), value: grams(nutrition.sugar), sub: true },
    { label: t('recipe.nutrition.fat'), value: grams(nutrition.fat) },
    { label: t('recipe.nutrition.saturatedFat'), value: grams(nutrition.saturatedFat), sub: true },
    { label: t('recipe.nutrition.fiber'), value: grams(nutrition.fiber) },
    { label: t('recipe.nutrition.salt'), value: grams(nutrition.salt) },
  ]
  const partly = nutrition.coverage < 0.95

  return (
    <section aria-labelledby="nutrition-title" className="mt-2 rounded-lg border-2 border-outline bg-card p-3.5">
      <div className="flex items-baseline justify-between gap-3">
        <h3 id="nutrition-title" className="inline-flex items-center gap-1.5 text-sm font-extrabold">
          <Apple className="size-4 text-brand" aria-hidden />
          {t('recipe.nutrition.title')}
        </h3>
        <p className="text-lg font-extrabold tabular-nums">
          ≈ {new Intl.NumberFormat(i18n.language).format(nutrition.kcal)} kcal
        </p>
      </div>
      <dl className="mt-2 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
        {rows.map((row) => (
          <div key={row.label} className="contents">
            <dt className={row.sub ? 'pl-3 text-muted-foreground' : undefined}>{row.label}</dt>
            <dd className={`text-right tabular-nums ${row.sub ? 'text-muted-foreground' : 'font-semibold'}`}>{row.value}</dd>
          </div>
        ))}
      </dl>
      <p className="mt-2.5 text-xs text-muted-foreground">
        {t('recipe.nutrition.estimate')}
        {partly && <> {t('recipe.nutrition.coverage', { percent: Math.round(nutrition.coverage * 100) })}</>}
        {nutrition.unweighed > 0 && <> {t('recipe.nutrition.unweighed', { count: nutrition.unweighed })}</>}
      </p>
    </section>
  )
}
