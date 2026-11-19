import { Clock, Heart } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { RecipeListItem } from '@/api/library'
import { PlatformBadge } from '@/components/library/PlatformBadge'
import { Thumbnail } from '@/components/library/Thumbnail'

export function RecipeCard({ recipe }: { recipe: RecipeListItem }) {
  const { t } = useTranslation()
  return (
    <Link to={`/recipes/${recipe.id}`} className="group flex w-full flex-col overflow-hidden rounded-lg border bg-card">
      <div className="relative">
        <Thumbnail src={recipe.source?.thumbnail} className="aspect-[4/3] w-full" />
        {recipe.needsReview && (
          <span className="absolute top-2 left-2 rounded-full bg-card/90 px-2 py-0.5 text-xs font-medium text-destructive shadow-sm">
            {t('recipe.review.badge')}
          </span>
        )}
        {recipe.favorite && (
          <span className="absolute top-2 right-2 grid size-7 place-items-center rounded-full bg-card/90 text-primary shadow-sm">
            <Heart className="size-4 fill-current" aria-label={t('recipe.favorite')} />
          </span>
        )}
      </div>
      <div className="flex flex-1 flex-col gap-1 p-2.5">
        <h3 className="line-clamp-2 leading-snug font-medium group-hover:underline">{recipe.title}</h3>
        <div className="mt-auto flex items-center gap-2 pt-1 text-xs text-muted-foreground">
          {recipe.totalMinutes != null && (
            <span className="inline-flex shrink-0 items-center gap-1">
              <Clock className="size-3.5" aria-hidden />
              {t('recipe.minutes', { count: recipe.totalMinutes })}
            </span>
          )}
          {recipe.source && <PlatformBadge platform={recipe.source.platform} url={recipe.source.url} />}
        </div>
      </div>
    </Link>
  )
}
