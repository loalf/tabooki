import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { cn } from '@/lib/utils'

export const inputClass =
  'h-11 w-full rounded-md border border-input bg-card px-3 text-base outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring'

export const textareaClass =
  'w-full rounded-md border border-input bg-card px-3 py-2.5 text-base leading-relaxed outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring'

const variants = {
  primary: 'bg-primary text-primary-foreground',
  secondary: 'border bg-card text-foreground',
  ghost: 'text-foreground hover:bg-muted',
  danger: 'border border-destructive/40 bg-card text-destructive',
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
        'inline-flex shrink-0 items-center justify-center gap-2 rounded-md font-medium transition-opacity disabled:opacity-60',
        size === 'md' && 'h-11 px-4',
        size === 'sm' && 'h-9 px-3 text-sm',
        size === 'icon' && 'size-11',
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
        'inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border px-3.5 text-sm font-medium whitespace-nowrap transition-colors',
        active ? 'border-primary bg-primary text-primary-foreground' : 'bg-card text-foreground',
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
    <label className="flex flex-col gap-1.5 text-sm font-medium">
      {label}
      {children}
      {hint && <span className="text-xs font-normal text-muted-foreground">{hint}</span>}
    </label>
  )
}
