import { ChevronDown, Feather, Heart, Leaf, ListChecks, Plus, Salad, Search, SlidersHorizontal, Soup, X } from 'lucide-react'
import { type ReactNode, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { type RecipeFilters, type RecipeListItem, useMeaningSearch, useRecipes, useReviewCount, useTagNames } from '@/api/library'
import { AddRecipeSheet } from '@/components/library/AddRecipeSheet'
import { InboxBanner } from '@/components/library/Inbox'
import { RecipeCard } from '@/components/library/RecipeCard'
import { Thumbnail } from '@/components/library/Thumbnail'
import { EmptyState, pageTitleClass } from '@/components/PageHeader'
import { useOnMenu } from '@/components/plan/useOnMenu'
import { Sheet } from '@/components/Sheet'
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

/** A filter as the row and the sheet show it: tapping it turns it on or off. */
type Filter = { key: string; on: boolean; icon?: ReactNode; label: string; toggle: () => void }

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
  const onMenu = useOnMenu()
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
  const [filtersOpen, setFiltersOpen] = useState(false)
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
  const passes = (r: RecipeListItem) =>
    (!heavy || r.heaviness === heavy) &&
    (!vegetarian || r.diet === 'vegetarian' || r.diet === 'vegan') &&
    (!fitting || (!r.tasteConflicts?.length && !(r.notesFit?.fit === 'poor' && r.notesFit.when === 'always'))) &&
    passesMoreFilters(r, more)
  const shown = (recipes.data ?? []).filter(passes)

  // By meaning: what the words don't find ("algo ligero con garbanzos"), from the library without
  // the words but with the other filters, closest first.
  const meaning = useMeaningSearch(debouncedQuery)
  const unworded = useRecipes({ ...filters, q: '' })
  const byId = new Map((unworded.data ?? []).map((r) => [r.id, r]))
  const shownIds = new Set(shown.map((r) => r.id))
  const closeBy = (meaning.data ?? [])
    .map((found) => byId.get(found.id))
    .filter((r): r is RecipeListItem => r !== undefined && !shownIds.has(r.id) && passes(r))
  // Only once the household has said what it likes (recipes then say what they have against it).
  const hasTaste = (recipes.data ?? []).some((r) => r.tasteConflicts != null)

  // Taps in the sheet replace the history entry, so back doesn't step through them one by one.
  const edit = (change: (next: URLSearchParams) => void) =>
    setParams(
      (current) => {
        const next = new URLSearchParams(current)
        change(next)
        return next
      },
      { replace: filtersOpen },
    )

  /** Keys that can hold several values (tags, allergens): adds or drops this one. */
  const toggleIn = (key: 'tag' | 'without', value: string) =>
    edit((next) => {
      const selected = next.getAll(key)
      next.delete(key)
      for (const v of selected.includes(value) ? selected.filter((s) => s !== value) : [...selected, value]) next.append(key, v)
    })

  /** One value per key: tapping the chosen one again drops it. */
  const toggleValue = (key: 'fav' | 'review' | 'fits' | 'veg' | 'heavy' | 'kcal' | 'protein' | 'spice', value = '1') =>
    edit((next) => (next.get(key) === value ? next.delete(key) : next.set(key, value)))

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

  const clearFilters = () => {
    setQuery('')
    setParams(sort === 'recent' ? new URLSearchParams() : new URLSearchParams({ sort }), { replace: true })
  }

  /** The sheet's "Borrar": every filter, but not the words in the search box. */
  const clearChosen = () =>
    setParams(
      (current) => {
        const next = new URLSearchParams()
        for (const key of ['q', 'sort']) if (current.get(key)) next.set(key, current.get(key)!)
        return next
      },
      { replace: true },
    )

  // The everyday filters: one tap away in the row, and first in the sheet.
  const shortcuts: Filter[] = [
    ...(reviewCount > 0 || needsReview
      ? [{ key: 'review', on: needsReview, icon: <ListChecks className="size-4" aria-hidden />, label: t('library.toReview', { count: reviewCount }), toggle: () => toggleValue('review') }]
      : []),
    { key: 'fav', on: favorite, icon: <Heart className={cn('size-4', favorite && 'fill-current')} aria-hidden />, label: t('library.favorites'), toggle: () => toggleValue('fav') },
    ...(hasTaste || fitting ? [{ key: 'fits', on: fitting, icon: <Salad className="size-4" aria-hidden />, label: t('library.fits'), toggle: () => toggleValue('fits') }] : []),
    { key: 'light', on: heavy === 'light', icon: <Feather className="size-4" aria-hidden />, label: t('library.light'), toggle: () => toggleValue('heavy', 'light') },
    { key: 'hearty', on: heavy === 'hearty', icon: <Soup className="size-4" aria-hidden />, label: t('library.hearty'), toggle: () => toggleValue('heavy', 'hearty') },
    { key: 'veg', on: vegetarian, icon: <Leaf className="size-4" aria-hidden />, label: t('library.vegetarian'), toggle: () => toggleValue('veg') },
  ]
  const number = (n: number) => new Intl.NumberFormat(i18n.language).format(n)
  // Everything that narrows the list, wherever it was chosen: first in the row, each with its ×.
  const chosen: Filter[] = [
    ...shortcuts.filter((s) => s.on),
    ...tags.map((tag) => ({ key: `tag:${tag}`, on: true, label: tag, toggle: () => toggleIn('tag', tag) })),
    ...(more.kcal !== null ? [{ key: 'kcal', on: true, label: t('library.more.kcalMax', { kcal: number(more.kcal) }), toggle: () => toggleValue('kcal', String(more.kcal)) }] : []),
    ...(more.protein !== null
      ? [{ key: 'protein', on: true, label: t('library.more.proteinChosen', { grams: number(more.protein) }), toggle: () => toggleValue('protein', String(more.protein)) }]
      : []),
    ...(more.spice !== null ? [{ key: 'spice', on: true, label: t(`library.more.spice_${more.spice}`), toggle: () => toggleValue('spice', more.spice!) }] : []),
    ...more.without.map((allergen) => ({
      key: `without:${allergen}`,
      on: true,
      label: t('library.more.withoutOne', { allergen: t(`recipe.allergen.${allergen}`) }),
      toggle: () => toggleIn('without', allergen),
    })),
  ]

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
        <div className="flex items-center gap-2.5">
          <label className="relative flex-1">
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
          <button
            type="button"
            onClick={() => setFiltersOpen(true)}
            aria-haspopup="dialog"
            aria-label={chosen.length ? `${t('library.filters')} · ${chosen.length}` : t('library.filters')}
            className="relative grid size-12 shrink-0 place-items-center rounded-md sticker-sm bg-card"
          >
            <SlidersHorizontal className="size-5.5" aria-hidden />
            {chosen.length > 0 && (
              <span className="absolute -top-2 -right-2 grid h-5.5 min-w-5.5 place-items-center rounded-full border-2 border-outline bg-primary px-1 text-xs font-extrabold text-primary-foreground tabular-nums">
                {chosen.length}
              </span>
            )}
          </button>
        </div>

        {/* What's narrowing the list comes first, so it never scrolls out of sight; then the shortcuts and tags not in use. */}
        <div className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
          {chosen.map((filter) => (
            <Chip key={filter.key} active onClick={filter.toggle} aria-pressed={undefined} aria-label={t('library.removeFilter', { name: filter.label })}>
              {filter.label}
              <X className="-mr-1 size-4" strokeWidth={2.5} aria-hidden />
            </Chip>
          ))}
          {shortcuts
            .filter((s) => !s.on)
            .map((s) => (
              <Chip key={s.key} onClick={s.toggle}>
                {s.icon}
                {s.label}
              </Chip>
            ))}
          {allTags
            .filter((tag) => !tags.includes(tag))
            .map((tag) => (
              <Chip key={tag} onClick={() => toggleIn('tag', tag)}>
                {tag}
              </Chip>
            ))}
        </div>

        {recipes.isPending ? (
          <RecipeGridSkeleton />
        ) : recipes.isError ? (
          <EmptyState>{t('library.loadError')}</EmptyState>
        ) : shown.length === 0 && closeBy.length > 0 ? (
          <CloseBy recipes={closeBy} onMenu={onMenu} />
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
            {!filtering && <Rediscover recipes={recipes.data} onMenu={onMenu} />}
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
                  <RecipeCard recipe={recipe} onMenu={onMenu.get(recipe.id!)} />
                </li>
              ))}
            </ul>
            {closeBy.length > 0 && <CloseBy recipes={closeBy} onMenu={onMenu} />}
          </>
        )}
      </section>

      {(adding || inboxRequested) && <AddRecipeSheet onClose={closeAdding} />}
      {filtersOpen && (
        <FiltersSheet
          shortcuts={shortcuts}
          more={more}
          tags={tags}
          allTags={allTags}
          onTag={(tag) => toggleIn('tag', tag)}
          onChoice={toggleValue}
          onWithout={(allergen) => toggleIn('without', allergen)}
          count={shown.length}
          onClear={chosen.length ? clearChosen : undefined}
          onClose={() => setFiltersOpen(false)}
        />
      )}
    </>
  )
}

/** Every filter in one place. Each tap applies at once, so the button below counts what you'd see. */
function FiltersSheet({
  shortcuts,
  more,
  tags,
  allTags,
  onTag,
  onChoice,
  onWithout,
  count,
  onClear,
  onClose,
}: {
  shortcuts: Filter[]
  more: MoreFilters
  tags: string[]
  allTags: string[]
  onTag: (tag: string) => void
  onChoice: (key: 'kcal' | 'protein' | 'spice', value: string) => void
  onWithout: (allergen: Allergen) => void
  count: number
  onClear?: () => void
  onClose: () => void
}) {
  const { t, i18n } = useTranslation()
  const number = (n: number) => new Intl.NumberFormat(i18n.language).format(n)
  const group = (label: string, chips: ReactNode) => (
    <div role="group" aria-label={label} className="flex flex-col gap-1.5">
      <h3 className="text-xs font-bold tracking-wide text-muted-foreground uppercase">{label}</h3>
      <div className="flex flex-wrap gap-2">{chips}</div>
    </div>
  )
  // Chosen tags first (even if no recipe has them any more), then the rest by use.
  const tagChips = [...tags, ...allTags.filter((tag) => !tags.includes(tag))]

  return (
    <Sheet title={t('library.filters')} onClose={onClose} className="pb-0">
      <div className="flex flex-col gap-3.5 pb-4">
        <div className="flex flex-wrap gap-2">
          {shortcuts.map((s) => (
            <Chip key={s.key} active={s.on} onClick={s.toggle}>
              {s.icon}
              {s.label}
            </Chip>
          ))}
        </div>
        {group(
          t('library.more.without'),
          ALLERGENS.map((allergen) => (
            <Chip key={allergen} active={more.without.includes(allergen)} onClick={() => onWithout(allergen)}>
              <span className="inline-block first-letter:uppercase">{t(`recipe.allergen.${allergen}`)}</span>
            </Chip>
          )),
        )}
        {group(
          t('library.more.spice'),
          SPICE_FILTERS.map((spice) => (
            <Chip key={spice} active={more.spice === spice} onClick={() => onChoice('spice', spice)}>
              {t(`library.more.spice_${spice}`)}
            </Chip>
          )),
        )}
        {group(
          t('library.more.kcal'),
          KCAL_MAX.map((kcal) => (
            <Chip key={kcal} active={more.kcal === kcal} onClick={() => onChoice('kcal', String(kcal))}>
              {t('library.more.kcalMax', { kcal: number(kcal) })}
            </Chip>
          )),
        )}
        {group(
          t('library.more.protein'),
          PROTEIN_MIN.map((grams) => (
            <Chip key={grams} active={more.protein === grams} onClick={() => onChoice('protein', String(grams))}>
              {t('library.more.proteinMin', { grams: number(grams) })}
            </Chip>
          )),
        )}
        {tagChips.length > 0 &&
          group(
            t('library.tags'),
            tagChips.map((tag) => (
              <Chip key={tag} active={tags.includes(tag)} onClick={() => onTag(tag)}>
                {tag}
              </Chip>
            )),
          )}
        <p className="text-xs text-muted-foreground">{t('library.more.note')}</p>
      </div>
      <div className="sticky bottom-0 -mx-4 flex gap-2.5 border-t-2 border-border bg-background px-4 pt-2.5 pb-[max(1rem,env(safe-area-inset-bottom))]">
        <Button variant="secondary" onClick={onClear} disabled={!onClear}>
          {t('library.clear')}
        </Button>
        <Button className="flex-1" onClick={onClose}>
          {t('library.showResults', { count })}
        </Button>
      </div>
    </Sheet>
  )
}

/** Recipes found by meaning rather than by the words, closest first. */
function CloseBy({ recipes, onMenu }: { recipes: RecipeListItem[]; onMenu: ReadonlyMap<string, string> }) {
  const { t } = useTranslation()
  return (
    <section aria-labelledby="close-by-title" className="mt-3 flex flex-col gap-2">
      <div>
        <h2 id="close-by-title" className="text-[17px] font-extrabold">
          {t('library.closeBy.title')}
        </h2>
        <p className="text-xs text-muted-foreground">{t('library.closeBy.hint')}</p>
      </div>
      <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        {recipes.map((recipe) => (
          <li key={recipe.id} className="flex">
            <RecipeCard recipe={recipe} onMenu={onMenu.get(recipe.id!)} />
          </li>
        ))}
      </ul>
    </section>
  )
}

const FORGOTTEN_AFTER_DAYS = 28

/** Recipes the household liked enough to cook but hasn't for a while, longest ago first; not the ones already back on the menu. */
function Rediscover({ recipes, onMenu }: { recipes: RecipeListItem[]; onMenu: ReadonlyMap<string, string> }) {
  const { t, i18n } = useTranslation()
  const now = parseIsoDate(today()).getTime()
  const days = (date: string) => Math.round((now - parseIsoDate(date).getTime()) / 86_400_000)
  const forgotten = recipes
    .filter((r) => r.lastCookedOn && days(r.lastCookedOn) >= FORGOTTEN_AFTER_DAYS && !onMenu.has(r.id!))
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
