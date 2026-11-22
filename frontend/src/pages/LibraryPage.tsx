import { ChevronDown, Heart, ListChecks, Plus, Search, X } from 'lucide-react'
import { useEffect, useState } from 'react'
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
type Sort = (typeof SORTS)[number]

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

  // A new object each render is fine: query keys are compared by value.
  const filters: RecipeFilters = { q: debouncedQuery, tags, favorite, needsReview }
  const recipes = useRecipes(filters)
  const allTags = useTagNames()
  const reviewCount = useReviewCount()
  const filtering = Boolean(filters.q.trim() || tags.length || favorite || needsReview)

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

  const toggleFlag = (key: 'fav' | 'review') =>
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
          {tagChips.map((tag) => (
            <Chip key={tag} active={tags.includes(tag)} onClick={() => toggleTag(tag)}>
              {tag}
            </Chip>
          ))}
        </div>

        {recipes.isPending ? (
          <RecipeGridSkeleton />
        ) : recipes.isError ? (
          <EmptyState>{t('library.loadError')}</EmptyState>
        ) : recipes.data.length === 0 ? (
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
                {filtering ? t('library.results', { count: recipes.data.length }) : t('library.allCount', { count: recipes.data.length })}
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
              {sortRecipes(recipes.data, sort, i18n.language).map((recipe) => (
                <li key={recipe.id} className="flex">
                  <RecipeCard recipe={recipe} />
                </li>
              ))}
            </ul>
          </>
        )}
      </section>

      {adding && <AddRecipeSheet onClose={() => setAdding(false)} />}
    </>
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
