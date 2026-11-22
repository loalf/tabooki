import { Star } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'

/** 1-5 stars; tapping the current rating again clears it (when `clearable`). */
export function StarRating({
  value,
  onChange,
  size = 'md',
  clearable = true,
  label,
}: {
  value: number | null
  onChange: (value: number | null) => void
  size?: 'md' | 'lg'
  clearable?: boolean
  label?: string
}) {
  const { t } = useTranslation()
  return (
    <div className="flex" role="group" aria-label={label ?? t('recipe.rating')}>
      {[1, 2, 3, 4, 5].map((star) => (
        <button
          key={star}
          type="button"
          onClick={() => onChange(clearable && value === star ? null : star)}
          aria-label={t('recipe.stars', { count: star })}
          aria-pressed={value !== null && star <= value}
          className={cn('grid place-items-center text-outline', size === 'lg' ? 'size-14' : 'size-9')}
        >
          <Star
            strokeWidth={size === 'lg' ? 1.75 : 2}
            className={cn(size === 'lg' ? 'size-11' : 'size-6', value !== null && star <= value ? 'fill-highlight' : 'text-muted-foreground/60')}
          />
        </button>
      ))}
    </div>
  )
}
