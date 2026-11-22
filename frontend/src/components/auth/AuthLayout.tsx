import type { ReactNode } from 'react'

/** The signed-out pages: sign in, forgot password, reset password. */
export function AuthLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  return (
    <div className="mx-auto flex min-h-dvh max-w-sm flex-col justify-center px-4 py-8">
      <img src="/logo.svg" alt="" className="mb-6 size-16" />
      <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
      {subtitle && <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>}
      <div className="mt-6">{children}</div>
    </div>
  )
}

export const authInputClass =
  'h-11 rounded-md border border-input bg-card px-3 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring'

export const authButtonClass = 'h-11 rounded-md bg-primary font-medium text-primary-foreground transition-opacity disabled:opacity-60'
