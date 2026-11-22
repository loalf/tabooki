import { Clock, Heart, ShieldAlert } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import type { RecipeListItem } from '@/api/library'
import { PlatformBadge } from '@/components/library/PlatformBadge'
import { Thumbnail } from '@/components/library/Thumbnail'
import { misfit } from '@/lib/taste'
import { cn } from '@/lib/utils'

export function RecipeCard({ recipe }: { recipe: RecipeListItem }) {
  const { t, i18n } = useTranslation()
  const bad = misfit(recipe.tasteConflicts, t, i18n.language)
  return (
    <Link to={`/recipes/${recipe.id}`} className="group flex w-full flex-col overflow-hidden rounded-lg sticker bg-card">
      <div className="relative border-b-2 border-outline">
        <Thumbnail src={recipe.source?.thumbnail} className={cn('aspect-[4/3] w-full', bad && 'opacity-55 grayscale-[40%]')} />
        {recipe.needsReview && (
          <span className="absolute top-2 left-2 rounded-full border-2 border-outline bg-highlight px-2 py-0.5 text-[11px] font-bold text-highlight-foreground">
            {t('recipe.review.badge')}
          </span>
        )}
        {recipe.favorite && (
          <span className="absolute top-2 right-2 grid size-7 place-items-center rounded-full border-2 border-outline bg-card text-brand">
            <Heart className="size-3.5 fill-current" aria-label={t('recipe.favorite')} />
          </span>
        )}
      </div>
      <div className="flex flex-1 flex-col gap-1.5 px-2.5 pt-2.5 pb-3">
        <h3 className="line-clamp-2 text-sm leading-snug font-bold group-hover:underline">{recipe.title}</h3>
        {bad && (
          <p className={cn('flex items-start gap-1 text-xs leading-snug font-semibold', bad.firm ? 'text-destructive' : 'text-muted-foreground')}>
            {bad.firm && <ShieldAlert className="mt-px size-3.5 shrink-0" aria-hidden />}
            {bad.text}
          </p>
        )}
        <div className="mt-auto flex items-center gap-2.5 text-xs text-muted-foreground">
          {recipe.totalMinutes != null && (
            <span className="inline-flex shrink-0 items-center gap-1">
              <Clock className="size-3" aria-hidden />
              {t('recipe.minutes', { count: recipe.totalMinutes })}
            </span>
          )}
          {recipe.source && <PlatformBadge platform={recipe.source.platform} url={recipe.source.url} />}
        </div>
      </div>
    </Link>
  )
}
