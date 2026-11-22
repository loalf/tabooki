import { Check } from 'lucide-react'
import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { cn } from '@/lib/utils'

export const inputClass =
  'h-12 w-full rounded-md border-2 border-input bg-card px-3.5 text-base outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background'

export const textareaClass =
  'w-full rounded-md border-2 border-input bg-card px-3.5 py-2.5 text-base leading-relaxed outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background'

/** A tappable link or button that looks like a secondary Button (for <Link>s). */
export const linkButtonClass =
  'inline-flex h-9 shrink-0 items-center justify-center gap-1.5 rounded-sm sticker-sm bg-card px-3 text-sm font-semibold'

const variants = {
  primary: 'sticker-sm bg-primary text-primary-foreground',
  secondary: 'sticker-sm bg-card text-foreground',
  ghost: 'text-foreground hover:bg-muted',
  danger: 'border-2 border-destructive bg-card text-destructive',
} as const

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: keyof typeof variants
  size?: 'md' | 'sm' | 'icon'
}

export function Button({ variant = 'primary', size = 'md', className, type = 'button', ...props }: ButtonProps) {
  return (
    <button
      type={type}
      className={cn(
        'inline-flex shrink-0 items-center justify-center gap-2 font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:opacity-50',
        size === 'md' && 'h-12 rounded-md px-5 text-base',
        size === 'sm' && 'h-9 rounded-sm px-3 text-sm',
        size === 'icon' && 'size-11 rounded-md',
        variants[variant],
        className,
      )}
      {...props}
    />
  )
}

export function Chip({
  active,
  children,
  className,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { active?: boolean; children: ReactNode }) {
  return (
    <button
      type="button"
      aria-pressed={active}
      className={cn(
        'inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border-2 border-outline px-3.5 text-sm font-semibold whitespace-nowrap transition-colors',
        active ? 'bg-highlight text-highlight-foreground' : 'bg-card text-foreground',
        className,
      )}
      {...props}
    >
      {children}
    </button>
  )
}

export function Field({ label, hint, children }: { label: string; hint?: string; children: ReactNode }) {
  return (
    <label className="flex flex-col gap-1.5 text-sm font-semibold">
      {label}
      {children}
      {hint && <span className="text-xs font-normal text-muted-foreground">{hint}</span>}
    </label>
  )
}

/** A tick box drawn like the design's: outlined, avocado when ticked. Purely visual (aria-hidden). */
export function CheckMark({ checked, className }: { checked: boolean; className?: string }) {
  return (
    <span
      className={cn(
        'grid size-6 shrink-0 place-items-center rounded-[7px] border-2 border-outline',
        checked ? 'bg-primary text-primary-foreground' : 'bg-card',
        className,
      )}
      aria-hidden
    >
      {checked && <Check className="size-4" strokeWidth={3} />}
    </span>
  )
}
