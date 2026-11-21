import { useEffect, useState } from 'react'

export type WakeLockState = 'on' | 'off' | 'unsupported'

/**
 * Keeps the screen on while mounted (Screen Wake Lock API: Chrome, Safari 16.4+, also from the
 * home screen on recent iOS). The browser drops the lock when the app is hidden, so it's taken
 * again when it's back.
 */
export function useWakeLock(): WakeLockState {
  const supported = typeof navigator !== 'undefined' && 'wakeLock' in navigator
  const [on, setOn] = useState(false)

  useEffect(() => {
    if (!supported) return
    let lock: WakeLockSentinel | null = null
    let unmounted = false

    const acquire = async () => {
      if (document.visibilityState !== 'visible' || (lock && !lock.released)) return
      try {
        const next = await navigator.wakeLock.request('screen')
        if (unmounted) return void next.release()
        lock = next
        setOn(true)
        next.addEventListener('release', () => setOn(false))
      } catch {
        // Refused (battery saver, not allowed in this frame): the screen just sleeps as usual.
        setOn(false)
      }
    }
    const onVisibility = () => void acquire()

    void acquire()
    document.addEventListener('visibilitychange', onVisibility)
    return () => {
      unmounted = true
      document.removeEventListener('visibilitychange', onVisibility)
      void lock?.release()
    }
  }, [supported])

  return !supported ? 'unsupported' : on ? 'on' : 'off'
}
