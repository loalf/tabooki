import { defineConfig, minimal2023Preset } from '@vite-pwa/assets-generator/config'

// Home-screen icons that get cropped (Android maskable, iOS) sit on the logo's own pimentón.
const background = '#d2531a'

export default defineConfig({
  headLinkOptions: { preset: '2023' },
  preset: {
    ...minimal2023Preset,
    maskable: { ...minimal2023Preset.maskable, resizeOptions: { background } },
    apple: { ...minimal2023Preset.apple, resizeOptions: { background } },
  },
  images: ['public/logo.svg'],
})
