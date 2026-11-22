const ASK_KEY = 'tabooki.askWhySwap'

/** Whether to ask why after a swap (someone can say "don't ask again" on this device). */
export function askWhySwap(): boolean {
  try {
    return localStorage.getItem(ASK_KEY) !== 'off'
  } catch {
    return true
  }
}

export function stopAskingWhySwap(): void {
  try {
    localStorage.setItem(ASK_KEY, 'off')
  } catch {
    // Private mode: it will ask again, that's all.
  }
}
