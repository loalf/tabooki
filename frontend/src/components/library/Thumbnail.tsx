import { ChefHat } from 'lucide-react'
import { useState } from 'react'
import { cn } from '@/lib/utils'

/** A recipe or link image, with a calm placeholder when there's none (or it fails to load). */
export function Thumbnail({ src, className }: { src?: string | null; className?: string }) {
  const [failed, setFailed] = useState(false)

  if (!src || failed) {
    return (
      <div className={cn('grid place-items-center bg-muted text-muted-foreground/60', className)} aria-hidden>
        <ChefHat className="size-1/3 max-h-10 max-w-10" strokeWidth={1.5} />
      </div>
    )
  }

  return (
    <img
      src={src}
      alt=""
      loading="lazy"
      decoding="async"
      // Remote images (when we couldn't cache a copy) often refuse hotlinking with a referrer.
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
      className={cn('bg-muted object-cover', className)}
    />
  )
}
