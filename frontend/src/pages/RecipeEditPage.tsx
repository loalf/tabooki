import { ArrowLeft, Link2, Minus, Plus } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import {
  hostOf,
  type Recipe,
  type RecipeInput,
  type Source,
  useCreateRecipe,
  useRecipe,
  useTagNames,
  useSource,
  useUpdateRecipe,
  ValidationError,
} from '@/api/library'
import { useMe } from '@/auth/useAuth'
import { PlatformBadge } from '@/components/library/PlatformBadge'
import { Thumbnail } from '@/components/library/Thumbnail'
import { EmptyState, pageTitleClass } from '@/components/PageHeader'
import { TagInput } from '@/components/TagInput'
import { Button, Chip, Field, inputClass, textareaClass } from '@/components/ui'
import { LOCALES } from '@/i18n'
import { FOOD_GROUPS, type FoodGroup, HEAVINESS, type Heaviness, SPICE, sortGroups } from '@/lib/facts'
import { ingredientsToText, stepsToText, textToIngredients, textToSteps } from '@/lib/recipeText'
import { cn } from '@/lib/utils'
import { useGoBack } from '@/lib/useGoBack'

type MealType = 'lunch' | 'dinner'
const MEAL_TYPES: MealType[] = ['lunch', 'dinner']

/** /recipes/new (optionally ?source=<link id>) and /recipes/:id/edit */
export function RecipeEditPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const [params] = useSearchParams()
  const sourceId = id ? null : params.get('source')
  const existing = useRecipe(id ?? null)
  const source = useSource(sourceId)
  const goBack = useGoBack()

  const loading = (id && existing.isPending) || (sourceId && source.isPending)
  const notFound = id && existing.isError

  return (
    <>
      <div className="mb-4 flex items-center gap-2">
        <button type="button" onClick={goBack} className="-ml-2 grid size-10 place-items-center" aria-label={t('common.back')}>
          <ArrowLeft className="size-5" />
        </button>
        <h1 className={pageTitleClass}>{id ? t('recipe.form.editTitle') : t('recipe.form.newTitle')}</h1>
      </div>
      {loading ? (
        <div className="h-40 animate-pulse rounded-lg bg-muted" aria-hidden />
      ) : notFound ? (
        <EmptyState>{t('recipe.notFound')}</EmptyState>
      ) : (
        <RecipeForm existing={id ? existing.data : undefined} source={source.data} />
      )}
    </>
  )
}

function RecipeForm({ existing, source }: { existing?: Recipe; source?: Source }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const goBack = useGoBack()
  const { data: me } = useMe()
  const allTags = useTagNames()
  const create = useCreateRecipe()
  const update = useUpdateRecipe(existing?.id ?? '')
  const save = existing ? update : create

  const [title, setTitle] = useState(existing?.title ?? source?.title ?? '')
  const [language, setLanguage] = useState<RecipeInput['language']>(existing?.language ?? me?.locale ?? 'es')
  const [servings, setServings] = useState(existing?.servings ?? me?.defaultServings ?? 4)
  const [prepMinutes, setPrepMinutes] = useState(existing?.prepMinutes?.toString() ?? '')
  const [cookMinutes, setCookMinutes] = useState(existing?.cookMinutes?.toString() ?? '')
  const [mealTypes, setMealTypes] = useState<MealType[]>(existing?.mealTypes ?? MEAL_TYPES)
  const [tags, setTags] = useState<string[]>(existing?.tags ?? [])
  const [ingredientsText, setIngredientsText] = useState(ingredientsToText(existing?.ingredients ?? []))
  const [stepsText, setStepsText] = useState(stepsToText(existing?.steps ?? []))
  const [notes, setNotes] = useState(existing?.notes ?? '')
  const [heaviness, setHeaviness] = useState<Heaviness | null>(existing?.heaviness ?? null)
  const [spice, setSpice] = useState<number | null>(existing?.spice ?? null)
  const [foodGroups, setFoodGroups] = useState<FoodGroup[]>(sortGroups(existing?.foodGroups ?? []))

  const linkedSource = existing?.source ?? source
  const violations = save.error instanceof ValidationError ? save.error.violations : []
  const errorFor = (path: string) =>
    violations
      .filter((v) => v.propertyPath === path || v.propertyPath.startsWith(`${path}[`))
      .map((v) => v.message)
      .join(' ') || undefined

  const toMinutes = (value: string) => (value.trim() === '' ? null : Math.max(0, Math.round(Number(value))))

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    const body: RecipeInput = {
      title,
      language,
      servings,
      prepMinutes: toMinutes(prepMinutes),
      cookMinutes: toMinutes(cookMinutes),
      mealTypes,
      tags,
      ingredients: textToIngredients(ingredientsText, existing?.ingredients),
      steps: textToSteps(stepsText),
      notes: notes.trim() || null,
      // Facts only when changed here: the model may have read them while the form was open,
      // and sending them back unchanged would pin its old (empty) answer as a fix by hand.
      ...(heaviness !== (existing?.heaviness ?? null) ? { heaviness } : {}),
      ...(spice !== (existing?.spice ?? null) ? { spice } : {}),
      ...(foodGroups.join() !== sortGroups(existing?.foodGroups ?? []).join() ? { foodGroups } : {}),
      favorite: existing?.favorite ?? false,
      planNext: existing?.planNext ?? false,
      // Saving the form means someone has looked at it.
      needsReview: false,
      // The link only needs sending when creating a recipe from it; edits keep it.
      ...(!existing && source?.id ? { source: `/api/sources/${source.id}` } : {}),
    }
    save.mutate(body, {
      onSuccess: (recipe) => void navigate(`/recipes/${recipe.id}`, { replace: true }),
    })
  }

  const toggleMealType = (type: MealType) =>
    setMealTypes(mealTypes.includes(type) ? mealTypes.filter((m) => m !== type) : [...mealTypes, type])

  const toggleGroup = (group: FoodGroup) =>
    setFoodGroups(sortGroups(foodGroups.includes(group) ? foodGroups.filter((g) => g !== group) : [...foodGroups, group]))

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-5" noValidate>
      {linkedSource?.url && (
        <a href={linkedSource.url} target="_blank" rel="noreferrer" className="flex items-center gap-3 rounded-lg sticker bg-card p-2.5">
          <Thumbnail src={linkedSource.thumbnail} className="size-12 shrink-0 rounded-sm border-2 border-outline" />
          <span className="flex min-w-0 flex-col">
            <span className="flex items-center gap-1 text-xs text-muted-foreground">
              <Link2 className="size-3.5" aria-hidden />
              {t('recipe.form.fromLink')}
            </span>
            <span className="truncate text-sm font-bold">{linkedSource.title ?? hostOf(linkedSource.url)}</span>
            <PlatformBadge platform={linkedSource.platform} url={linkedSource.url} />
          </span>
        </a>
      )}

      <Field label={t('recipe.form.title')}>
        <input
          required
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          maxLength={200}
          aria-invalid={errorFor('title') ? true : undefined}
          className={inputClass}
        />
        <FieldError message={errorFor('title')} />
      </Field>

      <div className="grid grid-cols-2 gap-4">
        <fieldset className="flex flex-col gap-1.5">
          <legend className="mb-1.5 text-sm font-semibold">{t('recipe.form.language')}</legend>
          <div className="flex gap-2">
            {LOCALES.map((locale) => (
              <Chip key={locale} active={language === locale} onClick={() => setLanguage(locale)}>
                {locale.toUpperCase()}
              </Chip>
            ))}
          </div>
        </fieldset>
        <fieldset className="flex flex-col gap-1.5">
          <legend className="mb-1.5 text-sm font-semibold">{t('recipe.servings')}</legend>
          <div className="flex items-center gap-1">
            <Button variant="secondary" size="icon" className="size-9" onClick={() => setServings(Math.max(1, servings - 1))} aria-label={t('recipe.form.fewer')}>
              <Minus className="size-4" />
            </Button>
            <output className="w-8 text-center text-lg font-extrabold" aria-live="polite">
              {servings}
            </output>
            <Button variant="secondary" size="icon" className="size-9" onClick={() => setServings(Math.min(50, servings + 1))} aria-label={t('recipe.form.more')}>
              <Plus className="size-4" />
            </Button>
          </div>
        </fieldset>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <Field label={t('recipe.form.prepMinutes')}>
          <input type="number" inputMode="numeric" min={0} max={1440} value={prepMinutes} onChange={(e) => setPrepMinutes(e.target.value)} className={inputClass} />
        </Field>
        <Field label={t('recipe.form.cookMinutes')}>
          <input type="number" inputMode="numeric" min={0} max={1440} value={cookMinutes} onChange={(e) => setCookMinutes(e.target.value)} className={inputClass} />
        </Field>
      </div>

      <fieldset>
        <legend className="mb-1.5 text-sm font-semibold">{t('recipe.mealTypes')}</legend>
        <div className="flex gap-2">
          {MEAL_TYPES.map((type) => (
            <Chip key={type} active={mealTypes.includes(type)} onClick={() => toggleMealType(type)}>
              {t(`recipe.meal.${type}`)}
            </Chip>
          ))}
        </div>
      </fieldset>

      <fieldset className="flex flex-col gap-3">
        <legend className="text-sm font-semibold">{t('recipe.facts.title')}</legend>
        <p className="-mt-1.5 text-xs text-muted-foreground">{t('recipe.facts.hint')}</p>
        <div role="group" aria-label={t('recipe.facts.heavinessLabel')} className="flex flex-wrap gap-2">
          {HEAVINESS.map((value) => (
            <Chip key={value} active={heaviness === value} onClick={() => setHeaviness(heaviness === value ? null : value)}>
              {t(`recipe.facts.heaviness.${value}`)}
            </Chip>
          ))}
        </div>
        <div role="group" aria-label={t('recipe.facts.spiceLabel')} className="flex flex-wrap gap-2">
          {SPICE.map((value) => (
            <Chip key={value} active={spice === value} onClick={() => setSpice(spice === value ? null : value)}>
              {t(`recipe.facts.spice.${value}`)}
            </Chip>
          ))}
        </div>
        <div className="flex flex-col gap-1.5">
          <span className="text-xs font-semibold text-muted-foreground">{t('recipe.facts.contains')}</span>
          <div role="group" aria-label={t('recipe.facts.contains')} className="flex flex-wrap gap-2">
            {FOOD_GROUPS.map((group) => (
              <Chip key={group} active={foodGroups.includes(group)} onClick={() => toggleGroup(group)}>
                {t(`recipe.facts.group.${group}`)}
              </Chip>
            ))}
          </div>
        </div>
      </fieldset>

      <div className="flex flex-col gap-1.5 text-sm font-semibold">
        <span>{t('recipe.tags')}</span>
        <TagInput value={tags} onChange={setTags} suggestions={allTags} />
        <FieldError message={errorFor('tags')} />
      </div>

      <Field label={t('recipe.ingredients')} hint={t('recipe.form.ingredientsHint')}>
        <textarea
          rows={8}
          value={ingredientsText}
          onChange={(e) => setIngredientsText(e.target.value)}
          placeholder={t('recipe.form.ingredientsPlaceholder')}
          className={textareaClass}
        />
        <FieldError message={errorFor('ingredients')} />
      </Field>

      <Field label={t('recipe.steps')} hint={t('recipe.form.stepsHint')}>
        <textarea
          rows={8}
          value={stepsText}
          onChange={(e) => setStepsText(e.target.value)}
          placeholder={t('recipe.form.stepsPlaceholder')}
          className={textareaClass}
        />
        <FieldError message={errorFor('steps')} />
      </Field>

      <Field label={t('recipe.notes')}>
        <textarea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} className={textareaClass} />
      </Field>

      {save.isError && !(save.error instanceof ValidationError) && (
        <p className="text-sm text-destructive" role="alert">
          {t('recipe.form.saveError')}
        </p>
      )}
      {violations.length > 0 && (
        <p className="text-sm text-destructive" role="alert">
          {t('recipe.form.fixErrors')}
        </p>
      )}

      <div
        className={cn(
          'sticky bottom-[calc(4.5rem+env(safe-area-inset-bottom))] -mx-4 flex gap-2 border-t-2 border-outline bg-background px-4 py-3',
        )}
      >
        <Button variant="secondary" className="flex-1" onClick={goBack}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" className="flex-[2]" disabled={save.isPending || !title.trim()}>
          {save.isPending ? t('common.saving') : t('common.save')}
        </Button>
      </div>
    </form>
  )
}

function FieldError({ message }: { message?: string }) {
  if (!message) return null
  return <span className="text-xs font-normal text-destructive">{message}</span>
}
