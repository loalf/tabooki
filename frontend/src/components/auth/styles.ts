import { inputClass } from '@/components/ui'
import { cn } from '@/lib/utils'

export const authInputClass = inputClass

export const authButtonClass = cn(
  'h-[3.25rem] rounded-md sticker-sm bg-primary text-base font-semibold text-primary-foreground disabled:opacity-50',
  'outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
)

/** Same size as the main button, on the card colour: "Continue with Google". */
export const authSecondaryButtonClass = cn(
  'flex h-[3.25rem] items-center justify-center gap-3 rounded-md sticker-sm bg-card text-base font-semibold text-foreground',
  'outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
)

export const authLinkClass = 'text-center text-sm font-bold text-primary underline-offset-4 hover:underline'

export const authNoticeClass = 'flex gap-3 rounded-lg sticker bg-card p-4 text-sm leading-relaxed'
