import { useCallback, useEffect, useRef, useState } from 'react'

/** `step` is the step it was started from, so the same "15 minutos" on two steps are two timers. */
export type Timer = { id: number; step: number; label: string; seconds: number; endsAt: number }

let nextId = 0

/**
 * Kitchen timers: several can run at once. When one ends the phone vibrates (Android) and beeps
 * (the sound is unlocked by the tap that started the timer, as browsers require).
 *
 * They're kept per recipe for the tab's session, so closing cook mode to look at the recipe, or iOS
 * reloading the app, doesn't lose them: back in cook mode they're still counting, and one that ended
 * meanwhile shows "¡Tiempo!". After a reload there's no sound until the next tap anywhere (browsers
 * only start audio from a tap), so a restored timer that ends before then only vibrates.
 */
export function useTimers(recipeId: string) {
  const key = `tabooki-cook-timers:${recipeId}`
  const [restored] = useState(() => load(key))
  const [timers, setTimers] = useState<Timer[]>(restored)
  const [now, setNow] = useState(() => Date.now())
  const audio = useRef<AudioContext | null>(null)
  const latest = useRef<Timer[]>(restored)
  // Ones that ended while away already show "¡Tiempo!"; ringing late for them would only confuse.
  const [ended] = useState(() => new Set(restored.filter((t) => t.endsAt <= Date.now()).map((t) => t.id)))
  const rung = useRef(ended)

  const unlockAudio = useCallback(() => {
    if (!audio.current && typeof AudioContext !== 'undefined') audio.current = new AudioContext()
    void audio.current?.resume()
  }, [])

  useEffect(() => {
    if (!restored.length) return
    window.addEventListener('pointerdown', unlockAudio, { once: true })
    return () => window.removeEventListener('pointerdown', unlockAudio)
  }, [restored, unlockAudio])

  useEffect(() => {
    try {
      if (timers.length) sessionStorage.setItem(key, JSON.stringify(timers))
      else sessionStorage.removeItem(key)
    } catch {
      // Private mode: they just don't survive leaving cook mode.
    }
  }, [key, timers])

  useEffect(() => {
    latest.current = timers
  }, [timers])

  const running = timers.some((t) => t.endsAt > now)
  useEffect(() => {
    if (!running) return
    const tick = setInterval(() => {
      const time = Date.now()
      setNow(time)
      const ended = latest.current.filter((t) => t.endsAt <= time && !rung.current.has(t.id))
      if (!ended.length) return
      ended.forEach((t) => rung.current.add(t.id))
      navigator.vibrate?.([400, 200, 400, 200, 400])
      beep(audio.current)
    }, 250)
    return () => clearInterval(tick)
  }, [running])

  useEffect(() => () => void audio.current?.close(), [])

  const start = useCallback(
    (seconds: number, label: string, step: number) => {
      unlockAudio()
      const startedAt = Date.now()
      setNow(startedAt)
      setTimers((list) => [...list, { id: ++nextId, step, label, seconds, endsAt: startedAt + seconds * 1000 }])
    },
    [unlockAudio],
  )

  const stop = useCallback((id: number) => setTimers((list) => list.filter((t) => t.id !== id)), [])

  return { timers, now, start, stop }
}

function load(key: string): Timer[] {
  try {
    const list: unknown = JSON.parse(sessionStorage.getItem(key) ?? '[]')
    if (!Array.isArray(list)) return []
    const timers = list.filter(
      (t): t is Timer => typeof t?.id === 'number' && typeof t.step === 'number' && typeof t.label === 'string' && typeof t.seconds === 'number' && typeof t.endsAt === 'number',
    )
    nextId = Math.max(nextId, ...timers.map((t) => t.id))
    return timers
  } catch {
    return []
  }
}

/** Three short beeps. */
function beep(context: AudioContext | null) {
  if (!context) return
  for (const offset of [0, 0.35, 0.7]) {
    const oscillator = context.createOscillator()
    const gain = context.createGain()
    oscillator.frequency.value = 880
    gain.gain.setValueAtTime(0.0001, context.currentTime + offset)
    gain.gain.exponentialRampToValueAtTime(0.4, context.currentTime + offset + 0.02)
    gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + offset + 0.25)
    oscillator.connect(gain).connect(context.destination)
    oscillator.start(context.currentTime + offset)
    oscillator.stop(context.currentTime + offset + 0.3)
  }
}
