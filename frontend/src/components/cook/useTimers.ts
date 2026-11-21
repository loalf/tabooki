import { useCallback, useEffect, useRef, useState } from 'react'

export type Timer = { id: number; label: string; seconds: number; endsAt: number }

let nextId = 0

/**
 * Kitchen timers: several can run at once. When one ends the phone vibrates (Android) and beeps
 * (the sound is unlocked by the tap that started the timer, as browsers require).
 */
export function useTimers() {
  const [timers, setTimers] = useState<Timer[]>([])
  const [now, setNow] = useState(() => Date.now())
  const audio = useRef<AudioContext | null>(null)
  const latest = useRef<Timer[]>([])
  const rung = useRef(new Set<number>())

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

  const start = useCallback((seconds: number, label: string) => {
    if (!audio.current && typeof AudioContext !== 'undefined') audio.current = new AudioContext()
    void audio.current?.resume()
    const startedAt = Date.now()
    setNow(startedAt)
    setTimers((list) => [...list, { id: ++nextId, label, seconds, endsAt: startedAt + seconds * 1000 }])
  }, [])

  const stop = useCallback((id: number) => setTimers((list) => list.filter((t) => t.id !== id)), [])

  return { timers, now, start, stop }
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
