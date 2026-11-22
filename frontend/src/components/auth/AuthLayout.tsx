import type { ReactNode } from 'react'
import { Wordmark } from '@/components/brand'

/** The signed-out pages: sign in, forgot password, reset password. */
export function AuthLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  return (
    <div className="mx-auto flex min-h-dvh max-w-sm flex-col justify-center px-6 py-8">
      <Wordmark className="mb-8 text-[4rem]" />
      <h1 className="text-[1.625rem] leading-tight font-extrabold text-balance">{title}</h1>
      {subtitle && <p className="mt-1 text-[15px] leading-snug text-muted-foreground">{subtitle}</p>}
      <div className="mt-6">{children}</div>
    </div>
  )
}
