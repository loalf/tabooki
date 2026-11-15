import { BookOpen, CalendarDays, Settings, ShoppingCart } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Navigate, NavLink, Outlet } from 'react-router'
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

  if (isPending) return <Splash />
  if (!me) return <Navigate to="/login" replace />

  return (
    <div className="mx-auto flex min-h-dvh max-w-2xl flex-col">
      <main className="flex-1 px-4 pt-[max(1rem,env(safe-area-inset-top))] pb-24">
        <Outlet />
      </main>
      <nav
        className="fixed inset-x-0 bottom-0 border-t bg-card/95 pb-[env(safe-area-inset-bottom)] backdrop-blur"
        aria-label="Main"
      >
        <ul className="mx-auto grid max-w-2xl grid-cols-4">
          {tabs.map(({ to, key, icon: Icon, end }) => (
            <li key={key}>
              <NavLink
                to={to}
                end={end}
                className={({ isActive }) =>
                  cn(
                    'flex flex-col items-center gap-1 py-2.5 text-xs font-medium text-muted-foreground transition-colors',
                    isActive && 'text-primary',
                  )
                }
              >
                <Icon className="size-6" aria-hidden />
                {t(`nav.${key}`)}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  )
}
