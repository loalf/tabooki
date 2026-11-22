import { createContext, useContext } from 'react'

/** A recipe itself: full-bleed photo on top and its own action bar instead of the tabs. */
export function isRecipeDetail(pathname: string) {
  return /^\/recipes\/(?!new$)[^/]+$/.test(pathname)
}

/** Where a page can put a bar that sits right above the tab bar (Compra's quick add). */
export const AboveTabBar = createContext<HTMLElement | null>(null)

export function useAboveTabBar() {
  return useContext(AboveTabBar)
}
