import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, RouterProvider } from 'react-router'
import { AppShell } from '@/components/AppShell'
import { LocaleSync } from '@/components/LocaleSync'
import '@/i18n'
import '@/index.css'
import { LibraryPage } from '@/pages/LibraryPage'
import { LoginPage } from '@/pages/LoginPage'
import { PlanPage } from '@/pages/PlanPage'
import { RecipeEditPage } from '@/pages/RecipeEditPage'
import { RecipePage } from '@/pages/RecipePage'
import { SettingsPage } from '@/pages/SettingsPage'
import { SharePage } from '@/pages/SharePage'
import { ShoppingPage } from '@/pages/ShoppingPage'

const queryClient = new QueryClient()

const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  {
    element: <AppShell />,
    children: [
      { index: true, element: <LibraryPage /> },
      { path: 'recipes/new', element: <RecipeEditPage /> },
      { path: 'recipes/:id', element: <RecipePage /> },
      { path: 'recipes/:id/edit', element: <RecipeEditPage /> },
      { path: 'plan', element: <PlanPage /> },
      { path: 'shopping', element: <ShoppingPage /> },
      { path: 'settings', element: <SettingsPage /> },
      { path: 'share', element: <SharePage /> },
    ],
  },
])

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <LocaleSync />
      <RouterProvider router={router} />
    </QueryClientProvider>
  </StrictMode>,
)
