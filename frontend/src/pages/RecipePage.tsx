import { ArrowLeft, Check, ChefHat, Clock, ExternalLink, Heart, LoaderCircle, Pencil, RefreshCw, Sparkles, Trash2, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { ApiError } from '@/api/client'
import {
  isReextracting,
  platformLabel,
  type Recipe,
  useDeleteRecipe,
  useReextractRecipe,
  useRecipe,
  useRefreshRecipeLists,
  useUpdateRecipe,
} from '@/api/library'
import { useMe } from '@/auth/useAuth'
import { Thumbnail } from '@/components/library/Thumbnail'
import { EmptyState } from '@/components/PageHeader'
import { StarRating } from '@/components/StarRating'
import { Button } from '@/components/ui'
import { cn } from '@/lib/utils'
import { groupIngredients } from '@/lib/recipeText'
import { useGoBack } from '@/lib/useGoBack'

export function RecipePage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const recipe = useRecipe(id)
  const goBack = useGoBack()

  return (
    <>
      <button type="button" onClick={goBack} className="mb-3 inline-flex h-9 items-center gap-1 text-sm font-medium text-muted-foreground">
        <ArrowLeft className="size-4" aria-hidden />
        {t('library.title')}
      </button>
      {recipe.isPending ? (
        <div className="flex flex-col gap-3" aria-hidden>
          <div className="aspect-video animate-pulse rounded-lg bg-muted" />
          <div className="h-7 w-2/3 animate-pulse rounded bg-muted" />
        </div>
      ) : recipe.isError ? (
        <EmptyState>
          {recipe.error instanceof ApiError && recipe.error.status === 404 ? t('recipe.notFound') : t('library.loadError')}
        </EmptyState>
      ) : (
        <RecipeView recipe={recipe.data} />
      )}
    </>
  )
}

function RecipeView({ recipe }: { recipe: Recipe }) {
  const { t } = useTranslation()
  const id = recipe.id!
  const update = useUpdateRecipe(id)
  const navigate = useNavigate()
  const remove = useDeleteRecipe()
  const [confirming, setConfirming] = useState(false)
  // Opened from the menu: cook mode gets that meal's servings and rates that meal.
  const [params] = useSearchParams()

  useEffect(() => {
    if (!confirming) return
    const timer = setTimeout(() => setConfirming(false), 4000)
    return () => clearTimeout(timer)
  }, [confirming])

  const deleteRecipe = () =>
    remove.mutate(id, { onSuccess: () => void navigate('/', { replace: true }) })

  const source = recipe.source
  const tags = recipe.tags ?? []
  const steps = recipe.steps ?? []
  const ingredients = recipe.ingredients ?? []

  return (
    <article className="flex flex-col gap-5">
      {source?.thumbnail && <Thumbnail src={source.thumbnail} className="aspect-video w-full rounded-lg" />}

      <header className="flex flex-col gap-2">
        <h1 className="text-2xl leading-tight font-semibold tracking-tight">{recipe.title}</h1>
        <dl className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
          <div className="inline-flex items-center gap-1">
            <dt>
              <Users className="size-4" aria-label={t('recipe.servings')} />
            </dt>
            <dd>{t('recipe.servingsCount', { count: recipe.servings })}</dd>
          </div>
          {recipe.totalMinutes != null && (
            <div className="inline-flex items-center gap-1">
              <dt>
                <Clock className="size-4" aria-label={t('recipe.time')} />
              </dt>
              <dd>
                {t('recipe.minutes', { count: recipe.totalMinutes })}
                {recipe.prepMinutes != null && recipe.cookMinutes != null && (
                  <span>
                    {' '}
                    ({t('recipe.prepShort', { count: recipe.prepMinutes })} · {t('recipe.cookShort', { count: recipe.cookMinutes })})
                  </span>
                )}
              </dd>
            </div>
          )}
          {(recipe.mealTypes ?? []).length > 0 && (
            <div>
              <dt className="sr-only">{t('recipe.mealTypes')}</dt>
              <dd>{(recipe.mealTypes ?? []).map((type) => t(`recipe.meal.${type}`)).join(' · ')}</dd>
            </div>
          )}
        </dl>
      </header>

      {(steps.length > 0 || ingredients.length > 0) && (
        <Link
          to={`/recipes/${id}/cook${params.size ? `?${params}` : ''}`}
          className="inline-flex h-12 items-center justify-center gap-2 rounded-md bg-primary px-4 text-base font-medium text-primary-foreground"
        >
          <ChefHat className="size-5" aria-hidden />
          {params.get('servings') && Number(params.get('servings')) !== recipe.servings
            ? t('cook.openFor', { count: Number(params.get('servings')) })
            : t('cook.open')}
        </Link>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <Button
          variant="secondary"
          size="sm"
          aria-pressed={recipe.favorite}
          onClick={() => update.mutate({ favorite: !recipe.favorite })}
        >
          <Heart className={cn('size-4', recipe.favorite && 'fill-current text-primary')} aria-hidden />
          {t('recipe.favorite')}
        </Button>
        <StarRating value={recipe.rating ?? null} onChange={(rating) => update.mutate({ rating })} />
        <div className="ml-auto flex gap-2">
          <Link
            to={`/recipes/${id}/edit`}
            className="inline-flex h-9 items-center gap-1.5 rounded-md border bg-card px-3 text-sm font-medium"
          >
            <Pencil className="size-4" aria-hidden />
            {t('recipe.edit')}
          </Link>
          <Button
            variant={confirming ? 'danger' : 'secondary'}
            size="sm"
            disabled={remove.isPending}
            onClick={() => (confirming ? deleteRecipe() : setConfirming(true))}
            aria-label={confirming ? undefined : t('recipe.delete')}
          >
            <Trash2 className="size-4" aria-hidden />
            {confirming && t('common.confirmDelete')}
          </Button>
        </div>
      </div>

      {recipe.needsReview && (
        <div className="flex flex-col gap-3 rounded-lg border border-destructive/30 bg-card p-3">
          <p className="flex gap-2 text-sm">
            <Sparkles className="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden />
            {t('recipe.review.message')}
          </p>
          <div className="flex gap-2">
            <Button size="sm" onClick={() => update.mutate({ needsReview: false })} disabled={update.isPending}>
              <Check className="size-4" aria-hidden />
              {t('recipe.review.looksGood')}
            </Button>
            <Link to={`/recipes/${id}/edit`} className="inline-flex h-9 items-center rounded-md border bg-card px-3 text-sm font-medium">
              {t('recipe.review.fix')}
            </Link>
          </div>
        </div>
      )}

      {source?.url && (
        <a
          href={source.url}
          target="_blank"
          rel="noreferrer"
          className="flex items-center gap-2 rounded-lg border bg-card p-3 text-sm font-medium"
        >
          <ExternalLink className="size-4 shrink-0 text-primary" aria-hidden />
          <span className="min-w-0 truncate">
            {t('recipe.openOn', { site: platformLabel(source.platform, source.url) })}
            {source.author && <span className="font-normal text-muted-foreground"> · {source.author}</span>}
          </span>
        </a>
      )}

      {tags.length > 0 && (
        <ul className="flex flex-wrap gap-2" aria-label={t('recipe.tags')}>
          {tags.map((tag) => (
            <li key={tag}>
              <Link to={`/?tag=${encodeURIComponent(tag)}`} className="inline-flex h-8 items-center rounded-full bg-accent px-3 text-sm text-accent-foreground">
                {tag}
              </Link>
            </li>
          ))}
        </ul>
      )}

      <section aria-labelledby="ingredients-title">
        <h2 id="ingredients-title" className="mb-2 text-lg font-semibold">
          {t('recipe.ingredients')}
        </h2>
        {ingredients.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('recipe.noIngredients')}</p>
        ) : (
          groupIngredients(ingredients).map((group, index) => (
            <div key={index} className="mb-3">
              {group.name && <h3 className="mb-1 text-sm font-semibold text-muted-foreground">{group.name}</h3>}
              <ul className="flex flex-col divide-y rounded-lg border bg-card">
                {group.items.map((ingredient, i) => (
                  <li key={i} className={cn('px-3 py-2.5', ingredient.optional && 'text-muted-foreground')}>
                    {ingredient.rawText}
                  </li>
                ))}
              </ul>
            </div>
          ))
        )}
      </section>

      <section aria-labelledby="steps-title">
        <h2 id="steps-title" className="mb-2 text-lg font-semibold">
          {t('recipe.steps')}
        </h2>
        {steps.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('recipe.noSteps')}</p>
        ) : (
          <ol className="flex flex-col gap-4">
            {steps.map((step, i) => (
              <li key={i} className="flex gap-3">
                <span className="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">
                  {i + 1}
                </span>
                <p className="pt-0.5 leading-relaxed">{step}</p>
              </li>
            ))}
          </ol>
        )}
      </section>

      {source?.originalText && (
        <details className="group rounded-lg border bg-card" open={recipe.needsReview}>
          <summary className="cursor-pointer px-3 py-2.5 text-sm font-medium">{t('recipe.originalText')}</summary>
          <p className="border-t px-3 py-2.5 text-sm leading-relaxed whitespace-pre-line text-muted-foreground">
            {source.originalText}
          </p>
        </details>
      )}

      {source && <Reextract recipe={recipe} />}

      {recipe.notes && (
        <section aria-labelledby="notes-title">
          <h2 id="notes-title" className="mb-2 text-lg font-semibold">
            {t('recipe.notes')}
          </h2>
          <p className="rounded-lg border bg-card p-3 leading-relaxed whitespace-pre-line">{recipe.notes}</p>
        </section>
      )}
    </article>
  )
}

/**
 * "Extracted with <model>" and a way to extract it again: after switching to a newer model, or
 * when the first try missed things. It replaces the contents, so it asks twice.
 */
function Reextract({ recipe }: { recipe: Recipe }) {
  const { t } = useTranslation()
  const { data: me } = useMe()
  const reextract = useReextractRecipe(recipe.id!)
  const refreshLists = useRefreshRecipeLists()
  const [confirming, setConfirming] = useState(false)
  // What extractedAt was when we asked: if it changes, it worked; if not, say why.
  const [asked, setAsked] = useState<{ at: string | null } | null>(null)
  const working = isReextracting(recipe) || reextract.isPending
  const models = me?.extractionModels ?? []
  const outdated = Boolean(recipe.extractedBy && models.length && !models.includes(recipe.extractedBy))

  useEffect(() => {
    if (!confirming) return
    // Long enough to read what it replaces.
    const timer = setTimeout(() => setConfirming(false), 15000)
    return () => clearTimeout(timer)
  }, [confirming])

  const finished = asked !== null && !working
  useEffect(() => {
    if (finished) refreshLists()
    // eslint-disable-next-line react-hooks/exhaustive-deps -- once per finished run
  }, [finished])

  const start = () => {
    setConfirming(false)
    setAsked({ at: recipe.extractedAt ?? null })
    reextract.mutate()
  }

  return (
    <div className="flex flex-col gap-2 rounded-lg border bg-card p-3 text-sm">
      <p className="text-muted-foreground">
        {recipe.extractedBy ? t('recipe.extractedBy', { model: recipe.extractedBy }) : t('recipe.reextract.typed')}
        {outdated && <span className="font-medium text-foreground"> · {t('recipe.reextract.newer')}</span>}
      </p>
      {working ? (
        <p className="flex items-center gap-2 font-medium" role="status">
          <LoaderCircle className="size-4 animate-spin" aria-hidden />
          {t('recipe.reextract.working')}
        </p>
      ) : (
        <>
          {finished &&
            (recipe.extractedAt !== asked?.at ? (
              <p className="font-medium text-primary" role="status">
                {t('recipe.reextract.done')}
              </p>
            ) : (
              <p className="text-destructive" role="status">
                {reextract.isError ? t('recipe.reextract.error') : (recipe.source?.statusMessage ?? t('recipe.reextract.error'))}
              </p>
            ))}
          {confirming && <p>{t('recipe.reextract.confirmHint')}</p>}
          <div>
            <Button variant={confirming ? 'primary' : 'secondary'} size="sm" onClick={() => (confirming ? start() : setConfirming(true))}>
              <RefreshCw className="size-4" aria-hidden />
              {confirming ? t('recipe.reextract.confirm') : t('recipe.reextract.action')}
            </Button>
          </div>
        </>
      )}
    </div>
  )
}
