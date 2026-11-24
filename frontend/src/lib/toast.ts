import type { LucideIcon } from 'lucide-react'
import { useSyncExternalStore } from 'react'

/** A short message that floats over the app for a few seconds (see <Toaster />). */
export type Toast = {
  id: number
  tone: 'success' | 'error'
  title: string
  body?: string
  /** Instead of the tone's icon: what happened ("Fuera" shows its crossed cutlery). */
  icon?: LucideIcon
  /** Somewhere to go from it ("Open" the recipe, see what went wrong…), or a way to take it back. */
  action?: { label: string; to: string } | { label: string; undo: () => void }
}

const MAX_VISIBLE = 3
const DURATION = { success: 6000, error: 10_000 } as const

let toasts: Toast[] = []
let nextId = 1
const listeners = new Set<() => void>()

function emit() {
  for (const listener of listeners) listener()
}

export function toast(input: Omit<Toast, 'id'>): number {
  const id = nextId++
  toasts = [...toasts, { ...input, id }].slice(-MAX_VISIBLE)
  emit()
  setTimeout(() => dismissToast(id), DURATION[input.tone])
  return id
}

export function dismissToast(id: number) {
  if (!toasts.some((t) => t.id === id)) return
  toasts = toasts.filter((t) => t.id !== id)
  emit()
}

function subscribe(listener: () => void) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function useToasts(): Toast[] {
  return useSyncExternalStore(subscribe, () => toasts)
}
