import { BookOpen, CalendarDays, Settings, ShoppingCart } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Navigate, NavLink, Outlet, ScrollRestoration, useLocation } from 'react-router'
import { useMe } from '@/auth/useAuth'
import { Splash } from '@/components/Splash'
import { cn } from '@/lib/utils'

const tabs = [
  { to: '/', key: 'library', icon: BookOpen, end: true },
  { to: '/plan', key: 'plan', icon: CalendarDays, end: false },
  { to: '/shopping', key: 'shopping', icon: ShoppingCart, end: false },
  { to: '/settings', key: 'settings', icon: Settings, end: false },
] as const

/** Authenticated layout: page content plus a bottom tab bar (mobile-first). */
export function AppShell() {
  const { t } = useTranslation()
  const { data: me, isPending } = useMe()
  const location = useLocation()
  const { pathname } = location

  if (isPending) return <Splash />
  // Come back here after signing in (e.g. a link shared from another app).
  if (!me) return <Navigate to="/login" replace state={{ from: location }} />

  // Cook mode takes the whole screen.
  if (pathname.endsWith('/cook')) {
    return (
      <>
        <ScrollRestoration getKey={(location) => location.pathname} />
        <Outlet />
      </>
    )
  }

  return (
    <div className="mx-auto flex min-h-dvh max-w-2xl flex-col">
      {/* New pages start at the top and going back restores where you were. Keyed by path, so
          changing search filters (query string) doesn't jump to the top. */}
      <ScrollRestoration getKey={(location) => location.pathname} />
      <main className="flex-1 px-4 pt-[max(1rem,env(safe-area-inset-top))] pb-24">
        <Outlet />
      </main>
      <nav
        className="fixed inset-x-0 bottom-0 border-t-2 border-outline bg-card pb-[env(safe-area-inset-bottom)]"
        aria-label="Main"
      >
        <ul className="mx-auto grid max-w-2xl grid-cols-4 px-1.5">
          {tabs.map(({ to, key, icon: Icon, end }) => {
            // Recipe pages belong to the library tab.
            const current = (active: boolean) => active || (key === 'library' && pathname.startsWith('/recipes'))
            return (
              <li key={key}>
                <NavLink
                  to={to}
                  end={end}
                  className={({ isActive }) =>
                    cn(
                      'group flex flex-col items-center gap-1 pt-2 pb-2.5 text-[11px] font-bold text-muted-foreground transition-colors',
                      current(isActive) && 'text-foreground',
                    )
                  }
                >
                  {({ isActive }) => (
                    <>
                      <span
                        className={cn(
                          'rounded-full border-2 border-transparent px-4 py-0.5 transition-colors',
                          current(isActive) && 'border-outline bg-highlight text-highlight-foreground',
                        )}
                      >
                        <Icon className="size-[22px]" aria-hidden />
                      </span>
                      {t(`nav.${key}`)}
                    </>
                  )}
                </NavLink>
              </li>
            )
          })}
        </ul>
      </nav>
    </div>
  )
}
