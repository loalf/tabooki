import { createSyncStoragePersister } from '@tanstack/query-sync-storage-persister'
import { QueryClient } from '@tanstack/react-query'
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, RouterProvider } from 'react-router'
import { registerShoppingMutations } from '@/api/shopping'
import { AppShell } from '@/components/AppShell'
import { LocaleSync } from '@/components/LocaleSync'
import '@/i18n'
import '@/index.css'
import { CookPage } from '@/pages/CookPage'
import { CostsPage } from '@/pages/CostsPage'
import { ForgotPasswordPage } from '@/pages/ForgotPasswordPage'
import { LibraryPage } from '@/pages/LibraryPage'
import { LoginPage } from '@/pages/LoginPage'
import { PlanPage } from '@/pages/PlanPage'
import { RecipeEditPage } from '@/pages/RecipeEditPage'
import { RecipePage } from '@/pages/RecipePage'
import { ResetPasswordPage } from '@/pages/ResetPasswordPage'
import { SettingsPage } from '@/pages/SettingsPage'
import { SharePage } from '@/pages/SharePage'
import { ShoppingPage } from '@/pages/ShoppingPage'

const queryClient = new QueryClient()
registerShoppingMutations(queryClient)

/**
 * The shopping list works offline in the shop: it (and who's signed in) is kept on the phone, and
 * check-offs made without signal wait there until it's back, even if the app is closed meanwhile.
 * Storage can be unavailable (private browsing): then it just isn't kept.
 */
const storage = {
  getItem: (key: string) => {
    try {
      return window.localStorage.getItem(key)
    } catch {
      return null
    }
  },
  setItem: (key: string, value: string) => {
    try {
      window.localStorage.setItem(key, value)
    } catch {
      // full or blocked: not kept
    }
  },
  removeItem: (key: string) => {
    try {
      window.localStorage.removeItem(key)
    } catch {
      // blocked
    }
  },
}
const persistOptions = {
  persister: createSyncStoragePersister({ storage, key: 'tabooki-offline' }),
  maxAge: 7 * 24 * 60 * 60 * 1000,
  buster: 'shopping-v1',
  dehydrateOptions: {
    shouldDehydrateQuery: (query: { queryKey: readonly unknown[]; state: { status: string } }) =>
      query.state.status === 'success' && (query.queryKey[0] === 'shopping' || query.queryKey[0] === 'me'),
  },
}

const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  { path: '/forgot-password', element: <ForgotPasswordPage /> },
  { path: '/reset-password', element: <ResetPasswordPage /> },
  {
    element: <AppShell />,
    children: [
      { index: true, element: <LibraryPage /> },
      { path: 'recipes/new', element: <RecipeEditPage /> },
      { path: 'recipes/:id', element: <RecipePage /> },
      { path: 'recipes/:id/edit', element: <RecipeEditPage /> },
      { path: 'recipes/:id/cook', element: <CookPage /> },
      { path: 'plan', element: <PlanPage /> },
      { path: 'shopping', element: <ShoppingPage /> },
      { path: 'settings', element: <SettingsPage /> },
      { path: 'settings/costs', element: <CostsPage /> },
      { path: 'share', element: <SharePage /> },
    ],
  },
])

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <PersistQueryClientProvider client={queryClient} persistOptions={persistOptions} onSuccess={() => queryClient.resumePausedMutations()}>
      <LocaleSync />
      <RouterProvider router={router} />
    </PersistQueryClientProvider>
  </StrictMode>,
)
