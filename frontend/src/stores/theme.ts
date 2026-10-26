import { ref } from 'vue'
import { defineStore } from 'pinia'

export type ThemePreference = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'theme_preference'

function isThemePreference(value: string | null): value is ThemePreference {
  return value === 'light' || value === 'dark' || value === 'system'
}

function loadStoredPreference(): ThemePreference {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    return isThemePreference(stored) ? stored : 'system'
  } catch {
    // localStorage unavailable (private mode etc.) — fall back to the default
    return 'system'
  }
}

/**
 * Drives the light/dark/system switcher in the sidebar. "System" removes
 * `data-theme` entirely so `base.css`'s `prefers-color-scheme` block decides;
 * "Hell"/"Dunkel" set `data-theme` explicitly to override the OS preference.
 */
export const useThemeStore = defineStore('theme', () => {
  const preference = ref<ThemePreference>(loadStoredPreference())

  function apply() {
    if (preference.value === 'system') {
      document.documentElement.removeAttribute('data-theme')
    } else {
      document.documentElement.setAttribute('data-theme', preference.value)
    }
  }

  function setPreference(next: ThemePreference) {
    preference.value = next
    apply()

    try {
      localStorage.setItem(STORAGE_KEY, next)
    } catch {
      // localStorage unavailable — the choice just won't persist across visits
    }
  }

  apply()

  return { preference, setPreference }
})
