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
          className={cn('grid place-items-center text-primary', size === 'lg' ? 'size-12' : 'size-9')}
        >
          <Star className={cn(size === 'lg' ? 'size-8' : 'size-5', value !== null && star <= value ? 'fill-current' : 'text-muted-foreground/50')} />
        </button>
      ))}
    </div>
  )
}
