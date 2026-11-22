import {
  CalendarCheck,
  CalendarPlus,
  Check,
  ChefHat,
  ChevronLeft,
  Clock,
  Ellipsis,
  ExternalLink,
  Heart,
  LoaderCircle,
  Minus,
  Pencil,
  Plus,
  RefreshCw,
  Sparkles,
  Star,
  Sun,
  Trash2,
} from 'lucide-react'
import { type ReactNode, useEffect, useState } from 'react'
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
import { AddToMenuSheet } from '@/components/plan/AddToMenuSheet'
import { Sheet, SheetRowContent, sheetRowClass } from '@/components/Sheet'
import { StarRating } from '@/components/StarRating'
import { Button, linkButtonClass } from '@/components/ui'
import { scaleLine } from '@/lib/cook'
import { cn } from '@/lib/utils'
import { groupIngredients } from '@/lib/recipeText'
import { useGoBack } from '@/lib/useGoBack'
import { parseIsoDate } from '@/lib/week'

export function RecipePage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const recipe = useRecipe(id)
  const goBack = useGoBack()

  if (recipe.isPending || recipe.isError) {
    return (
      <div className="pt-[max(1rem,env(safe-area-inset-top))]">
        <RoundButton label={t('common.back')} onClick={goBack} className="mb-4">
          <ChevronLeft className="size-5" aria-hidden />
        </RoundButton>
        {recipe.isPending ? (
          <div className="flex flex-col gap-3" aria-hidden>
            <div className="aspect-video animate-pulse rounded-lg bg-muted" />
            <div className="h-7 w-2/3 animate-pulse rounded bg-muted" />
          </div>
        ) : (
          <EmptyState>
            {recipe.error instanceof ApiError && recipe.error.status === 404 ? t('recipe.notFound') : t('library.loadError')}
          </EmptyState>
        )}
      </div>
    )
  }
  return <RecipeView recipe={recipe.data} onBack={goBack} />
}

function RoundButton({ label, onClick, children, className, pressed }: { label: string; onClick: () => void; children: ReactNode; className?: string; pressed?: boolean }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      aria-pressed={pressed}
      className={cn('grid size-10 shrink-0 place-items-center rounded-full border-2 border-outline bg-card text-foreground', className)}
    >
      {children}
    </button>
  )
}

/** The leading amount of an ingredient line, to set it apart ("300 g" | "de lentejas pardinas"). */
const AMOUNT =
  /^((?:\d+(?:[.,]\d+)?|\d+\s*\/\s*\d+|[½¼¾⅓⅔])(?:\s*(?:-|–|a)\s*\d+(?:[.,]\d+)?)?\s*(?:(?:kg|g|gr|mg|l|ml|cl|dl|oz|lb|cdas?|cditas?|cucharadas?|cucharaditas?|tazas?|vasos?|dientes?|hojas?|latas?|pizcas?|ramitas?|manojos?|tbsp|tsp|cups?|cloves?|cans?|pinch(?:es)?)\b\.?)?)\s*(.*)$/i

function IngredientLine({ text }: { text: string }) {
  const match = AMOUNT.exec(text)
  if (!match || !match[2]) return <span className="flex-1">{text}</span>
  return (
    <>
      <span className="min-w-[4.5rem] shrink-0 font-extrabold whitespace-nowrap tabular-nums">{match[1].trim()}</span>
      <span className="flex-1">{match[2].replace(/^(de|del|of)\s+/i, '')}</span>
    </>
  )
}

function RecipeView({ recipe, onBack }: { recipe: Recipe; onBack: () => void }) {
  const { t, i18n } = useTranslation()
  const id = recipe.id!
  const update = useUpdateRecipe(id)
  // Opened from the menu: cook mode gets that meal's servings and rates that meal.
  const [params] = useSearchParams()
  const [servings, setServings] = useState(() => Math.min(50, Math.max(1, Number(params.get('servings')) || recipe.servings || 1)))
  const steps = recipe.steps ?? []
  const ingredients = recipe.ingredients ?? []
  const [tab, setTab] = useState<'ingredients' | 'steps'>(ingredients.length || !steps.length ? 'ingredients' : 'steps')
  const [more, setMore] = useState(false)
  const [toMenu, setToMenu] = useState(false)
  const source = recipe.source
  const tags = recipe.tags ?? []
  const factor = servings / (recipe.servings || servings)

  const cookParams = new URLSearchParams(params)
  if (servings !== recipe.servings) cookParams.set('servings', String(servings))
  else if (!params.get('plan')) cookParams.delete('servings')
  const cookHref = `/recipes/${id}/cook${cookParams.size ? `?${cookParams}` : ''}`
  const lastCooked = recipe.lastCookedOn
    ? new Intl.DateTimeFormat(i18n.language, { day: 'numeric', month: 'short' }).format(parseIsoDate(recipe.lastCookedOn))
    : null

  const actions = (
    <>
      <RoundButton label={t('recipe.favorite')} pressed={recipe.favorite} onClick={() => update.mutate({ favorite: !recipe.favorite })}>
        <Heart className={cn('size-[18px]', recipe.favorite ? 'fill-current text-brand' : '')} aria-hidden />
      </RoundButton>
      <RoundButton label={t('recipe.more')} onClick={() => setMore(true)}>
        <Ellipsis className="size-5" aria-hidden />
      </RoundButton>
    </>
  )

  return (
    <article className="flex flex-col gap-4">
      {source?.thumbnail ? (
        <div className="relative -mx-4 border-b-2 border-outline">
          <Thumbnail src={source.thumbnail} className="aspect-[4/3] max-h-[46dvh] w-full" />
          <div className="absolute inset-x-0 top-0 h-28 bg-gradient-to-b from-black/45 to-transparent" aria-hidden />
          <div className="absolute inset-x-0 top-0 flex items-center gap-2 px-4 pt-[max(0.75rem,env(safe-area-inset-top))]">
            <RoundButton label={t('common.back')} onClick={onBack} className="mr-auto">
              <ChevronLeft className="size-5" aria-hidden />
            </RoundButton>
            {actions}
          </div>
        </div>
      ) : (
        <div className="flex items-center gap-2 pt-[max(1rem,env(safe-area-inset-top))]">
          <RoundButton label={t('common.back')} onClick={onBack} className="mr-auto">
            <ChevronLeft className="size-5" aria-hidden />
          </RoundButton>
          {actions}
        </div>
      )}

      <header className="flex flex-col gap-2">
        {source?.url && (
          <a href={source.url} target="_blank" rel="noreferrer" className="inline-flex max-w-full items-center gap-1.5 self-start text-[13px] font-semibold text-muted-foreground underline underline-offset-2">
            <ExternalLink className="size-3.5 shrink-0" aria-hidden />
            <span className="truncate">
              {source.author ? t('recipe.byOn', { author: source.author, site: platformLabel(source.platform, source.url) }) : t('recipe.openOn', { site: platformLabel(source.platform, source.url) })}
            </span>
          </a>
        )}
        <h1 className="text-[1.75rem] leading-[1.15] font-extrabold text-balance">{recipe.title}</h1>
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-[13px] font-semibold text-muted-foreground">
          {recipe.totalMinutes != null && (
            <li className="inline-flex items-center gap-1.5">
              <Clock className="size-4" aria-label={t('recipe.time')} />
              {t('recipe.minutes', { count: recipe.totalMinutes })}
              {recipe.prepMinutes != null && recipe.cookMinutes != null && (
                <span className="font-normal">
                  ({t('recipe.prepShort', { count: recipe.prepMinutes })} · {t('recipe.cookShort', { count: recipe.cookMinutes })})
                </span>
              )}
            </li>
          )}
          {(recipe.mealTypes ?? []).length > 0 && (
            <li className="inline-flex items-center gap-1.5">
              <Sun className="size-4" aria-label={t('recipe.mealTypes')} />
              {(recipe.mealTypes ?? []).map((type) => t(`recipe.meal.${type}`)).join(' · ')}
            </li>
          )}
          {(recipe.rating != null || lastCooked) && (
            <li className="inline-flex items-center gap-1.5">
              {recipe.rating != null && (
                <>
                  <Star className="size-4 fill-highlight text-outline" aria-hidden />
                  <span aria-label={t('plan.rated', { count: recipe.rating })}>{recipe.rating}</span>
                </>
              )}
              {recipe.rating != null && lastCooked && ' · '}
              {lastCooked && t('recipe.lastCooked', { date: lastCooked })}
            </li>
          )}
        </ul>
        {recipe.planNext && (
          <p className="inline-flex items-center gap-1.5 self-start rounded-full border-2 border-outline bg-accent px-2.5 py-0.5 text-xs font-bold text-accent-foreground">
            <CalendarCheck className="size-3.5" aria-hidden />
            {t('recipe.planNext')}
          </p>
        )}
      </header>

      {tags.length > 0 && (
        <ul className="flex flex-wrap gap-1.5" aria-label={t('recipe.tags')}>
          {tags.map((tag) => (
            <li key={tag}>
              <Link to={`/recipes?tag=${encodeURIComponent(tag)}`} className="inline-flex h-7 items-center rounded-full bg-muted px-2.5 text-xs font-semibold">
                {tag}
              </Link>
            </li>
          ))}
        </ul>
      )}

      {recipe.needsReview && (
        <div className="flex flex-col gap-3 rounded-lg sticker bg-accent p-3">
          <p className="flex gap-2 text-sm">
            <Sparkles className="mt-0.5 size-4 shrink-0" aria-hidden />
            {t('recipe.review.message')}
          </p>
          <div className="flex gap-2">
            <Button size="sm" onClick={() => update.mutate({ needsReview: false })} disabled={update.isPending}>
              <Check className="size-4" aria-hidden />
              {t('recipe.review.looksGood')}
            </Button>
            <Link to={`/recipes/${id}/edit`} className={linkButtonClass}>
              {t('recipe.review.fix')}
            </Link>
          </div>
        </div>
      )}

      <div className="flex gap-1 rounded-md border-2 border-outline bg-muted p-1" role="tablist" aria-label={t('recipe.contents')}>
        {(['ingredients', 'steps'] as const).map((key) => (
          <button
            key={key}
            type="button"
            role="tab"
            id={`tab-${key}`}
            aria-selected={tab === key}
            aria-controls={`panel-${key}`}
            onClick={() => setTab(key)}
            className={cn('h-10 flex-1 rounded-sm text-sm font-bold', tab === key ? 'border-2 border-outline bg-card' : 'text-muted-foreground')}
          >
            {t(`recipe.${key}`)} · {key === 'ingredients' ? ingredients.length : steps.length}
          </button>
        ))}
      </div>

      {tab === 'ingredients' ? (
        <section id="panel-ingredients" role="tabpanel" aria-labelledby="tab-ingredients" className="flex flex-col gap-3">
          {ingredients.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('recipe.noIngredients')}</p>
          ) : (
            <>
              <div className="flex items-center justify-between gap-3">
                <span className="font-extrabold">{t('recipe.for')}</span>
                <div className="flex items-center rounded-full border-2 border-outline bg-card" role="group" aria-label={t('recipe.servings')}>
                  <button type="button" className="grid size-10 place-items-center disabled:opacity-40" disabled={servings <= 1} onClick={() => setServings(servings - 1)} aria-label={t('recipe.form.fewer')}>
                    <Minus className="size-4" aria-hidden />
                  </button>
                  <span className="min-w-22 text-center text-sm font-bold tabular-nums" aria-live="polite">
                    {t('recipe.servingsCount', { count: servings })}
                  </span>
                  <button type="button" className="grid size-10 place-items-center disabled:opacity-40" disabled={servings >= 50} onClick={() => setServings(servings + 1)} aria-label={t('recipe.form.more')}>
                    <Plus className="size-4" aria-hidden />
                  </button>
                </div>
              </div>
              {groupIngredients(ingredients).map((group, index) => (
                <div key={index}>
                  {group.name && <h3 className="mb-1 text-sm font-semibold text-muted-foreground">{group.name}</h3>}
                  <ul className="flex flex-col divide-y">
                    {group.items.map((ingredient, i) => {
                      const line = scaleLine(ingredient.rawText, ingredient.quantity, factor, i18n.language)
                      return (
                        <li key={i} className={cn('flex items-baseline gap-3 px-0.5 py-2.5 text-[15px]', ingredient.optional && 'text-muted-foreground')}>
                          <IngredientLine text={line.text} />
                          {!line.scaled && (
                            <span className="shrink-0 rounded-md bg-muted px-1.5 py-0.5 text-xs font-semibold text-muted-foreground tabular-nums" title={t('cook.notScaled')}>
                              ×{new Intl.NumberFormat(i18n.language, { maximumFractionDigits: 2 }).format(factor)}
                            </span>
                          )}
                        </li>
                      )
                    })}
                  </ul>
                </div>
              ))}
            </>
          )}
        </section>
      ) : (
        <section id="panel-steps" role="tabpanel" aria-labelledby="tab-steps" className="flex flex-col gap-5">
          {steps.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('recipe.noSteps')}</p>
          ) : (
            <ol className="flex flex-col gap-4">
              {steps.map((step, i) => (
                <li key={i} className="flex gap-3">
                  <span className="grid size-[1.875rem] shrink-0 place-items-center rounded-full border-2 border-outline bg-highlight text-sm font-extrabold text-highlight-foreground">
                    {i + 1}
                  </span>
                  <p className="pt-0.5 text-base leading-relaxed">{step}</p>
                </li>
              ))}
            </ol>
          )}
          {recipe.notes && (
            <section aria-labelledby="notes-title">
              <h2 id="notes-title" className="mb-2 text-lg font-extrabold">
                {t('recipe.notes')}
              </h2>
              <p className="rounded-lg sticker bg-card p-3 leading-relaxed whitespace-pre-line">{recipe.notes}</p>
            </section>
          )}
          {source?.originalText && (
            <details className="group rounded-lg sticker bg-card" open={recipe.needsReview}>
              <summary className="cursor-pointer px-3 py-2.5 text-sm font-medium">{t('recipe.originalText')}</summary>
              <p className="border-t px-3 py-2.5 text-sm leading-relaxed whitespace-pre-line text-muted-foreground">{source.originalText}</p>
            </details>
          )}
        </section>
      )}

      <div className="fixed inset-x-0 bottom-0 z-30 border-t-2 border-outline bg-card pb-[env(safe-area-inset-bottom)]">
        <div className="mx-auto flex max-w-2xl gap-2.5 px-4 pt-3 pb-3">
          <Button variant="secondary" onClick={() => setToMenu(true)} aria-haspopup="dialog">
            <CalendarPlus className="size-5" aria-hidden />
            {t('recipe.toMenu')}
          </Button>
          {steps.length > 0 || ingredients.length > 0 ? (
            <Link to={cookHref} className="inline-flex h-12 flex-1 items-center justify-center gap-2 rounded-md sticker-sm bg-primary px-4 text-base font-bold text-primary-foreground">
              <ChefHat className="size-5" aria-hidden />
              {servings !== recipe.servings ? t('cook.openFor', { count: servings }) : t('cook.open')}
            </Link>
          ) : (
            <Link to={`/recipes/${id}/edit`} className="inline-flex h-12 flex-1 items-center justify-center gap-2 rounded-md sticker-sm bg-primary px-4 text-base font-bold text-primary-foreground">
              <Pencil className="size-5" aria-hidden />
              {t('recipe.edit')}
            </Link>
          )}
        </div>
      </div>

      {more && <MoreSheet recipe={recipe} onClose={() => setMore(false)} />}
      {toMenu && <AddToMenuSheet recipe={recipe} onClose={() => setToMenu(false)} />}
    </article>
  )
}

/** The recipe's less frequent actions: edit, the original, rate, extract again, delete. */
function MoreSheet({ recipe, onClose }: { recipe: Recipe; onClose: () => void }) {
  const { t } = useTranslation()
  const id = recipe.id!
  const update = useUpdateRecipe(id)
  const navigate = useNavigate()
  const remove = useDeleteRecipe()
  const [confirming, setConfirming] = useState(false)
  const source = recipe.source
  const chevron = <ExternalLink className="size-4 shrink-0 text-muted-foreground" aria-hidden />

  useEffect(() => {
    if (!confirming) return
    const timer = setTimeout(() => setConfirming(false), 4000)
    return () => clearTimeout(timer)
  }, [confirming])

  return (
    <Sheet title={recipe.title} onClose={onClose}>
      <div className="flex flex-col gap-4">
        <div className="flex flex-col divide-y">
          <Link to={`/recipes/${id}/edit`} className={sheetRowClass}>
            <SheetRowContent icon={<Pencil />} title={t('recipe.edit')} />
          </Link>
          {source?.url && (
            <a href={source.url} target="_blank" rel="noreferrer" className={sheetRowClass}>
              <SheetRowContent icon={<ExternalLink />} title={t('recipe.openOn', { site: platformLabel(source.platform, source.url) })} hint={source.author ?? undefined} trailing={chevron} />
            </a>
          )}
          <div className="flex items-center gap-3 px-1 py-2">
            <span className="grid size-9 shrink-0 place-items-center rounded-[10px] border-2 border-outline bg-muted">
              <Star className="size-[18px]" aria-hidden />
            </span>
            <span className="flex-1 font-bold">{t('recipe.rating')}</span>
            <StarRating value={recipe.rating ?? null} onChange={(rating) => update.mutate({ rating })} />
          </div>
          <button
            type="button"
            className={cn(sheetRowClass, 'text-destructive')}
            disabled={remove.isPending}
            onClick={() => (confirming ? remove.mutate(id, { onSuccess: () => void navigate('/recipes', { replace: true }) }) : setConfirming(true))}
          >
            <SheetRowContent icon={<Trash2 />} title={confirming ? t('common.confirmDelete') : t('recipe.delete')} />
          </button>
        </div>
        {source && <Reextract recipe={recipe} />}
      </div>
    </Sheet>
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
    <div className="flex flex-col gap-2 rounded-lg sticker bg-card p-3 text-sm">
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
