import { ChevronDown, Feather, Heart, Leaf, ListChecks, Plus, Salad, Search, SlidersHorizontal, Soup, X } from 'lucide-react'
import { type ReactNode, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { type RecipeFilters, type RecipeListItem, useRecipes, useReviewCount, useTagNames } from '@/api/library'
import { AddRecipeSheet } from '@/components/library/AddRecipeSheet'
import { InboxBanner } from '@/components/library/Inbox'
import { RecipeCard } from '@/components/library/RecipeCard'
import { Thumbnail } from '@/components/library/Thumbnail'
import { EmptyState, pageTitleClass } from '@/components/PageHeader'
import { Button, Chip, inputClass } from '@/components/ui'
import { cn } from '@/lib/utils'
import { useDebounced } from '@/lib/useDebounced'
import { parseIsoDate, today } from '@/lib/week'

const SORTS = ['recent', 'quick', 'rating', 'az'] as const
const HEAVY_FILTERS = ['light', 'hearty'] as const
type HeavyFilter = (typeof HEAVY_FILTERS)[number]
type Sort = (typeof SORTS)[number]

// "More filters": what a serving brings, how spicy, what it is free of. In the URL like the rest.
const KCAL_MAX = [400, 600, 800] as const
const PROTEIN_MIN = [20, 30] as const
const SPICE_FILTERS = ['none', 'hot'] as const
type SpiceFilter = (typeof SPICE_FILTERS)[number]
type Allergen = NonNullable<RecipeListItem['allergens']>[number]
type FoodGroup = NonNullable<RecipeListItem['foodGroups']>[number]
const ALLERGENS: Allergen[] = ['gluten', 'milk', 'eggs', 'fish', 'crustaceans', 'molluscs', 'tree_nuts', 'peanuts', 'soy', 'sesame', 'celery', 'mustard', 'sulphites', 'lupin']
// What the recipe's own reading says it contains backs up what its foods say.
const ALLERGEN_GROUPS: Partial<Record<Allergen, FoodGroup[]>> = {
  gluten: ['gluten'],
  milk: ['dairy', 'cheese'],
  eggs: ['egg'],
  fish: ['fish'],
  crustaceans: ['shellfish'],
  molluscs: ['shellfish'],
  tree_nuts: ['tree_nuts'],
  peanuts: ['peanuts'],
  soy: ['soy'],
  sesame: ['sesame'],
}

type MoreFilters = { kcal: number | null; protein: number | null; spice: SpiceFilter | null; without: Allergen[] }

function readMoreFilters(params: URLSearchParams): MoreFilters {
  const number = <T extends number>(key: string, allowed: readonly T[]) => allowed.find((n) => String(n) === params.get(key)) ?? null
  return {
    kcal: number('kcal', KCAL_MAX),
    protein: number('protein', PROTEIN_MIN),
    spice: SPICE_FILTERS.find((s) => s === params.get('spice')) ?? null,
    without: ALLERGENS.filter((a) => params.getAll('without').includes(a)),
  }
}

function moreFiltersCount(f: MoreFilters): number {
  return Number(f.kcal !== null) + Number(f.protein !== null) + Number(f.spice !== null) + f.without.length
}

/** Unknown figures, spice or allergens never pass: a filter is a promise. */
function passesMoreFilters(r: RecipeListItem, f: MoreFilters): boolean {
  if (f.kcal !== null && !(r.nutrition && r.nutrition.kcal <= f.kcal)) return false
  if (f.protein !== null && !(r.nutrition && r.nutrition.protein >= f.protein)) return false
  if (f.spice === 'none' && r.spice !== 0) return false
  if (f.spice === 'hot' && !((r.spice ?? 0) >= 1)) return false
  if (f.without.length) {
    if (!r.allergensComplete) return false
    const groups = r.foodGroups ?? []
    for (const allergen of f.without) {
      if (r.allergens?.includes(allergen)) return false
      if (ALLERGEN_GROUPS[allergen]?.some((g) => groups.includes(g))) return false
    }
  }
  return true
}

function sortRecipes(recipes: RecipeListItem[], sort: Sort, locale: string): RecipeListItem[] {
  if (sort === 'recent') return recipes
  const sorted = [...recipes]
  if (sort === 'quick') sorted.sort((a, b) => (a.totalMinutes ?? Infinity) - (b.totalMinutes ?? Infinity))
  if (sort === 'rating') sorted.sort((a, b) => (b.rating ?? 0) - (a.rating ?? 0) || Number(b.favorite) - Number(a.favorite))
  if (sort === 'az') sorted.sort((a, b) => a.title.localeCompare(b.title, locale))
  return sorted
}

export function LibraryPage() {
  const { t, i18n } = useTranslation()
  const [params, setParams] = useSearchParams()
  const [adding, setAdding] = useState(false)
  const sort: Sort = SORTS.find((s) => s === params.get('sort')) ?? 'recent'

  // Filters live in the URL so going back from a recipe keeps them.
  const tags = params.getAll('tag')
  const favorite = params.get('fav') === '1'
  const needsReview = params.get('review') === '1'
  // Filtered here rather than by the API: the whole library is already loaded.
  const heavy: HeavyFilter | null = HEAVY_FILTERS.find((h) => h === params.get('heavy')) ?? null
  const vegetarian = params.get('veg') === '1'
  const fitting = params.get('fits') === '1'
  const more = readMoreFilters(params)
  const moreCount = moreFiltersCount(more)
  const [showMore, setShowMore] = useState(moreCount > 0)
  const [query, setQuery] = useState(params.get('q') ?? '')
  const debouncedQuery = useDebounced(query)

  useEffect(() => {
    setParams(
      (current) => {
        const next = new URLSearchParams(current)
        if (debouncedQuery.trim()) next.set('q', debouncedQuery)
        else next.delete('q')
        return next
      },
      { replace: true },
    )
  }, [debouncedQuery, setParams])

  // A toast about a link that needs a hand links to ?inbox=1: the inbox is in the "add recipe"
  // sheet. Closing it drops the flag, so coming back here later doesn't open it again.
  const inboxRequested = params.get('inbox') === '1'
  const closeAdding = () => {
    setAdding(false)
    if (!inboxRequested) return
    setParams(
      (current) => {
        const next = new URLSearchParams(current)
        next.delete('inbox')
        return next
      },
      { replace: true },
    )
  }

  // A new object each render is fine: query keys are compared by value.
  const filters: RecipeFilters = { q: debouncedQuery, tags, favorite, needsReview }
  const recipes = useRecipes(filters)
  const allTags = useTagNames()
  const reviewCount = useReviewCount()
  const filtering = Boolean(filters.q.trim() || tags.length || favorite || needsReview || heavy || vegetarian || fitting || moreCount)
  const shown = (recipes.data ?? []).filter(
    (r) =>
      (!heavy || r.heaviness === heavy) &&
      (!vegetarian || r.diet === 'vegetarian' || r.diet === 'vegan') &&
      (!fitting || (!r.tasteConflicts?.length && !(r.notesFit?.fit === 'poor' && r.notesFit.when === 'always'))) &&
      passesMoreFilters(r, more),
  )
  // Only once the household has said what it likes (recipes then say what they have against it).
  const hasTaste = (recipes.data ?? []).some((r) => r.tasteConflicts != null)

  const toggleTag = (tag: string) =>
    setParams((current) => {
      const next = new URLSearchParams(current)
      const selected = next.getAll('tag')
      next.delete('tag')
      for (const t of selected.includes(tag) ? selected.filter((s) => s !== tag) : [...selected, tag]) next.append('tag', t)
      return next
    })

  const setSort = (next: Sort) =>
    setParams(
      (current) => {
        const params = new URLSearchParams(current)
        if (next === 'recent') params.delete('sort')
        else params.set('sort', next)
        return params
      },
      { replace: true },
    )

  const toggleHeavy = (value: HeavyFilter) =>
    setParams((current) => {
      const next = new URLSearchParams(current)
      if (next.get('heavy') === value) next.delete('heavy')
      else next.set('heavy', value)
      return next
    })

  /** One choice per key (kcal, protein, spice): tapping the chosen one again drops it. */
  const toggleChoice = (key: 'kcal' | 'protein' | 'spice', value: string) =>
    setParams((current) => {
      const next = new URLSearchParams(current)
      if (next.get(key) === value) next.delete(key)
      else next.set(key, value)
      return next
    })

  const toggleWithout = (allergen: Allergen) =>
    setParams((current) => {
      const next = new URLSearchParams(current)
      const selected = next.getAll('without')
      next.delete('without')
      for (const a of selected.includes(allergen) ? selected.filter((s) => s !== allergen) : [...selected, allergen]) next.append('without', a)
      return next
    })

  const toggleFlag = (key: 'fav' | 'review' | 'veg' | 'fits') =>
    setParams((current) => {
      const next = new URLSearchParams(current)
      if (next.get(key) === '1') next.delete(key)
      else next.set(key, '1')
      return next
    })

  const clearFilters = () => {
    setQuery('')
    setParams(sort === 'recent' ? new URLSearchParams() : new URLSearchParams({ sort }), { replace: true })
  }

  // Selected tags first (even if no recipe has them any more), then the rest by use.
  const tagChips = [...tags, ...allTags.filter((tag) => !tags.includes(tag))]

  return (
    <>
      <div className="mb-4 flex items-center justify-between gap-2">
        <h1 className={pageTitleClass}>{t('library.title')}</h1>
        <button
          type="button"
          onClick={() => setAdding(true)}
          aria-label={t('library.add.title')}
          aria-haspopup="dialog"
          className="grid size-12 place-items-center rounded-full sticker bg-primary text-primary-foreground"
        >
          <Plus className="size-6" aria-hidden />
        </button>
      </div>

      <div className="mb-4 empty:hidden">
        <InboxBanner onOpen={() => setAdding(true)} />
      </div>
      <section aria-label={t('library.recipes')} className="flex flex-col gap-3">
        <label className="relative">
          <span className="sr-only">{t('library.search')}</span>
          <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <input
            type="search"
            enterKeyHint="search"
            placeholder={t('library.search')}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            className={cn(inputClass, 'pr-10 pl-10 [&::-webkit-search-cancel-button]:hidden')}
          />
          {query && (
            <button
              type="button"
              onClick={() => setQuery('')}
              className="absolute top-1/2 right-1 grid size-9 -translate-y-1/2 place-items-center text-muted-foreground"
              aria-label={t('library.clearSearch')}
            >
              <X className="size-5" />
            </button>
          )}
        </label>

        <div className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
          <Chip active={!filtering} onClick={clearFilters} className={cn(!filtering && 'bg-foreground text-background')}>
            {t('library.all')}
          </Chip>
          <Chip active={moreCount > 0} onClick={() => setShowMore((open) => !open)} aria-expanded={showMore} aria-controls="more-filters">
            <SlidersHorizontal className="size-4" aria-hidden />
            {t('library.more.title')}
            {moreCount > 0 && <span className="tabular-nums">· {moreCount}</span>}
          </Chip>
          {(reviewCount > 0 || needsReview) && (
            <Chip active={needsReview} onClick={() => toggleFlag('review')}>
              <ListChecks className="size-4" aria-hidden />
              {t('library.toReview', { count: reviewCount })}
            </Chip>
          )}
          <Chip active={favorite} onClick={() => toggleFlag('fav')}>
            <Heart className={cn('size-4', favorite && 'fill-current')} aria-hidden />
            {t('library.favorites')}
          </Chip>
          {(hasTaste || fitting) && (
            <Chip active={fitting} onClick={() => toggleFlag('fits')}>
              <Salad className="size-4" aria-hidden />
              {t('library.fits')}
            </Chip>
          )}
          <Chip active={heavy === 'light'} onClick={() => toggleHeavy('light')}>
            <Feather className="size-4" aria-hidden />
            {t('library.light')}
          </Chip>
          <Chip active={heavy === 'hearty'} onClick={() => toggleHeavy('hearty')}>
            <Soup className="size-4" aria-hidden />
            {t('library.hearty')}
          </Chip>
          <Chip active={vegetarian} onClick={() => toggleFlag('veg')}>
            <Leaf className="size-4" aria-hidden />
            {t('library.vegetarian')}
          </Chip>
          {tagChips.map((tag) => (
            <Chip key={tag} active={tags.includes(tag)} onClick={() => toggleTag(tag)}>
              {tag}
            </Chip>
          ))}
        </div>

        {showMore && (
          <MoreFiltersPanel filters={more} onChoice={toggleChoice} onWithout={toggleWithout} />
        )}

        {recipes.isPending ? (
          <RecipeGridSkeleton />
        ) : recipes.isError ? (
          <EmptyState>{t('library.loadError')}</EmptyState>
        ) : shown.length === 0 ? (
          filtering ? (
            <EmptyState
              title={t('library.noMatches')}
              action={
                <Button variant="secondary" size="sm" onClick={clearFilters}>
                  {t('library.clearFilters')}
                </Button>
              }
            >
              {t('library.noMatchesHint')}
            </EmptyState>
          ) : (
            <EmptyState
              title={t('library.emptyTitle')}
              action={
                <Button size="sm" onClick={() => setAdding(true)}>
                  <Plus className="size-4" aria-hidden />
                  {t('library.add.title')}
                </Button>
              }
            >
              {t('library.empty')}
            </EmptyState>
          )
        ) : (
          <>
            {!filtering && <Rediscover recipes={recipes.data} />}
            <div className="mt-1 flex items-center justify-between gap-2">
              <h2 className="text-[17px] font-extrabold">
                {filtering ? t('library.results', { count: shown.length }) : t('library.allCount', { count: shown.length })}
              </h2>
              <label className="relative inline-flex items-center text-sm font-semibold text-muted-foreground">
                <span className="sr-only">{t('library.sort.label')}</span>
                <select value={sort} onChange={(e) => setSort(e.target.value as Sort)} className="h-9 appearance-none bg-transparent pr-5 text-right outline-none">
                  {SORTS.map((s) => (
                    <option key={s} value={s}>
                      {t(`library.sort.${s}`)}
                    </option>
                  ))}
                </select>
                <ChevronDown className="pointer-events-none absolute right-0 size-4" aria-hidden />
              </label>
            </div>
            <ul className={cn('grid grid-cols-2 gap-3 sm:grid-cols-3', recipes.isPlaceholderData && 'opacity-60')}>
              {sortRecipes(shown, sort, i18n.language).map((recipe) => (
                <li key={recipe.id} className="flex">
                  <RecipeCard recipe={recipe} />
                </li>
              ))}
            </ul>
          </>
        )}
      </section>

      {(adding || inboxRequested) && <AddRecipeSheet onClose={closeAdding} />}
    </>
  )
}

function MoreFiltersPanel({
  filters,
  onChoice,
  onWithout,
}: {
  filters: MoreFilters
  onChoice: (key: 'kcal' | 'protein' | 'spice', value: string) => void
  onWithout: (allergen: Allergen) => void
}) {
  const { t, i18n } = useTranslation()
  const number = (n: number) => new Intl.NumberFormat(i18n.language).format(n)
  const group = (label: string, chips: ReactNode) => (
    <div role="group" aria-label={label} className="flex flex-col gap-1.5">
      <h3 className="text-xs font-bold tracking-wide text-muted-foreground uppercase">{label}</h3>
      <div className="flex flex-wrap gap-2">{chips}</div>
    </div>
  )

  return (
    <section id="more-filters" aria-label={t('library.more.title')} className="flex flex-col gap-3.5 rounded-lg border-2 border-outline bg-card p-3.5">
      {group(
        t('library.more.kcal'),
        KCAL_MAX.map((kcal) => (
          <Chip key={kcal} active={filters.kcal === kcal} onClick={() => onChoice('kcal', String(kcal))}>
            {t('library.more.kcalMax', { kcal: number(kcal) })}
          </Chip>
        )),
      )}
      {group(
        t('library.more.protein'),
        PROTEIN_MIN.map((grams) => (
          <Chip key={grams} active={filters.protein === grams} onClick={() => onChoice('protein', String(grams))}>
            {t('library.more.proteinMin', { grams: number(grams) })}
          </Chip>
        )),
      )}
      {group(
        t('library.more.spice'),
        SPICE_FILTERS.map((spice) => (
          <Chip key={spice} active={filters.spice === spice} onClick={() => onChoice('spice', spice)}>
            {t(`library.more.spice_${spice}`)}
          </Chip>
        )),
      )}
      {group(
        t('library.more.without'),
        ALLERGENS.map((allergen) => (
          <Chip key={allergen} active={filters.without.includes(allergen)} onClick={() => onWithout(allergen)}>
            <span className="inline-block first-letter:uppercase">{t(`recipe.allergen.${allergen}`)}</span>
          </Chip>
        )),
      )}
      <p className="text-xs text-muted-foreground">{t('library.more.note')}</p>
    </section>
  )
}

const FORGOTTEN_AFTER_DAYS = 28

/** Recipes the household liked enough to cook but hasn't for a while, longest ago first. */
function Rediscover({ recipes }: { recipes: RecipeListItem[] }) {
  const { t, i18n } = useTranslation()
  const now = parseIsoDate(today()).getTime()
  const days = (date: string) => Math.round((now - parseIsoDate(date).getTime()) / 86_400_000)
  const forgotten = recipes
    .filter((r) => r.lastCookedOn && days(r.lastCookedOn) >= FORGOTTEN_AFTER_DAYS)
    .sort((a, b) => a.lastCookedOn!.localeCompare(b.lastCookedOn!))
    .slice(0, 10)
  if (!forgotten.length) return null
  const relative = new Intl.RelativeTimeFormat(i18n.language, { numeric: 'auto' })
  const ago = (date: string) => {
    const d = days(date)
    return d < 60 ? relative.format(-Math.round(d / 7), 'week') : relative.format(-Math.round(d / 30), 'month')
  }

  return (
    <section aria-labelledby="rediscover-title" className="mt-1 flex flex-col gap-2.5">
      <h2 id="rediscover-title" className="text-[17px] font-extrabold">
        {t('library.rediscover')}
      </h2>
      <ul className="no-scrollbar -mx-4 flex snap-x scroll-px-4 gap-3 overflow-x-auto px-4 pt-0.5 pb-2">
        {forgotten.map((recipe) => (
          <li key={recipe.id} className="flex w-[14.5rem] shrink-0 snap-start">
            <Link to={`/recipes/${recipe.id}`} className="flex w-full flex-col overflow-hidden rounded-lg sticker bg-card">
              <Thumbnail src={recipe.source?.thumbnail} className="aspect-[9/4] w-full border-b-2 border-outline" />
              <span className="px-2.5 pt-2 pb-2.5">
                <span className="block truncate text-sm font-bold">{recipe.title}</span>
                <span className="block text-xs text-muted-foreground first-letter:uppercase">{t('library.lastTime', { when: ago(recipe.lastCookedOn!) })}</span>
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  )
}

function RecipeGridSkeleton() {
  return (
    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3" aria-hidden>
      {Array.from({ length: 4 }, (_, i) => (
        <li key={i} className="overflow-hidden rounded-lg sticker bg-card">
          <div className="aspect-[4/3] animate-pulse border-b-2 border-outline bg-muted" />
          <div className="m-2.5 h-4 w-3/4 animate-pulse rounded bg-muted" />
        </li>
      ))}
    </ul>
  )
}
