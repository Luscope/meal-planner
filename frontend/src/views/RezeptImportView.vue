<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import { extractErrorMessage } from '@/lib/api'
import {
  createRecipeImport,
  fetchRecipeImportStatus,
  type ImportStatus,
} from '@/lib/recipeImport'
import AppIcon from '@/components/AppIcon.vue'

type SourceType = 'text' | 'url' | 'image'

const sourceType = ref<SourceType>('text')
const textValue = ref('')
const urlValue = ref('')
const imageFile = ref<File | null>(null)

const isSubmitting = ref(false)
const errorMessage = ref('')

const importId = ref<number | null>(null)
const importStatus = ref<ImportStatus | null>(null)
const recipeId = ref<number | null>(null)
let pollTimer: number | undefined

const canSubmit = computed(() => {
  if (sourceType.value === 'text') return textValue.value.trim().length > 0
  if (sourceType.value === 'url') return urlValue.value.trim().length > 0
  return imageFile.value !== null
})

function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  imageFile.value = input.files?.[0] ?? null
}

async function handleSubmit() {
  errorMessage.value = ''
  isSubmitting.value = true

  try {
    const payload =
      sourceType.value === 'text'
        ? { text: textValue.value }
        : sourceType.value === 'url'
          ? { url: urlValue.value }
          : { image: imageFile.value! }

    const result = await createRecipeImport(payload)
    importId.value = result.id
    importStatus.value = result.status
    startPolling(result.id)
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isSubmitting.value = false
  }
}

function startPolling(id: number) {
  pollTimer = window.setInterval(async () => {
    try {
      const status = await fetchRecipeImportStatus(id)
      importStatus.value = status.status

      if (status.status === 'completed') {
        recipeId.value = status.recipe_id
        stopPolling()
      } else if (status.status === 'failed') {
        errorMessage.value = status.error_message ?? 'Import fehlgeschlagen.'
        stopPolling()
      }
    } catch (error) {
      errorMessage.value = extractErrorMessage(error)
      stopPolling()
    }
  }, 2000)
}

function stopPolling() {
  if (pollTimer !== undefined) {
    window.clearInterval(pollTimer)
    pollTimer = undefined
  }
}

function reset() {
  importId.value = null
  importStatus.value = null
  recipeId.value = null
  errorMessage.value = ''
  textValue.value = ''
  urlValue.value = ''
  imageFile.value = null
}

onUnmounted(stopPolling)
</script>

<template>
  <main class="import">
    <RouterLink to="/rezepte" class="back-link">
      <AppIcon name="chevron-left" :size="16" />
      Zurück zu den Rezepten
    </RouterLink>
    <h1>Rezept importieren</h1>

    <template v-if="!importId">
      <div class="tabs">
        <button type="button" :class="{ active: sourceType === 'text' }" @click="sourceType = 'text'">
          Text
        </button>
        <button type="button" :class="{ active: sourceType === 'url' }" @click="sourceType = 'url'">
          Link
        </button>
        <button type="button" :class="{ active: sourceType === 'image' }" @click="sourceType = 'image'">
          Foto
        </button>
      </div>

      <form @submit.prevent="handleSubmit">
        <label v-if="sourceType === 'text'">
          Rezepttext
          <textarea
            v-model="textValue"
            rows="10"
            placeholder="Rezept hier einfügen (Zutaten, Mengen, Zubereitung)…"
          ></textarea>
        </label>

        <label v-else-if="sourceType === 'url'">
          Link zur Rezeptseite
          <input v-model="urlValue" type="url" placeholder="https://…" />
        </label>

        <label v-else>
          Foto oder Screenshot
          <input type="file" accept="image/png,image/jpeg,image/webp" @change="onFileChange" />
          <span class="hint">JPG, PNG oder WebP, max. 10 MB.</span>
        </label>

        <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

        <button type="submit" class="submit" :disabled="isSubmitting || !canSubmit">
          {{ isSubmitting ? 'Wird gesendet…' : 'Importieren' }}
        </button>
      </form>
    </template>

    <template v-else>
      <div class="status-card">
        <template v-if="importStatus === 'pending' || importStatus === 'processing'">
          <div class="spinner" aria-hidden="true"></div>
          <p>Claude extrahiert das Rezept …</p>
        </template>
        <template v-else-if="importStatus === 'completed'">
          <p class="status-icon success"><AppIcon name="check" :size="22" :stroke-width="2.25" /></p>
          <p>Rezept erfolgreich importiert!</p>
          <RouterLink :to="{ name: 'recipe-detail', params: { id: recipeId! } }" class="result-link">
            Zum Rezept
            <AppIcon name="chevron-right" :size="16" />
          </RouterLink>
        </template>
        <template v-else-if="importStatus === 'failed'">
          <p class="status-icon error-icon"><AppIcon name="close" :size="22" /></p>
          <p class="error">Import fehlgeschlagen: {{ errorMessage }}</p>
          <button type="button" @click="reset">Erneut versuchen</button>
        </template>
      </div>
    </template>
  </main>
</template>

<style scoped>
.import {
  max-width: 580px;
  margin: 0 auto;
  padding: 2rem 1.5rem;
}

.back-link {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  margin-bottom: 1rem;
  color: var(--color-muted);
  text-decoration: none;
  font-size: 0.9rem;
  font-weight: 500;
}

.back-link:hover {
  color: var(--color-text);
}

h1 {
  font-size: 1.85rem;
  margin-bottom: 1.25rem;
}

.tabs {
  display: inline-grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.25rem;
  padding: 0.25rem;
  border-radius: var(--radius-md);
  background: var(--color-surface-2);
  margin-bottom: 1.25rem;
}

.tabs button {
  height: 2.5rem;
  padding: 0 1.1rem;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: var(--color-muted);
  cursor: pointer;
  font-size: 0.875rem;
  font-weight: 500;
}

.tabs button.active {
  background: var(--color-surface);
  color: var(--color-text);
  font-weight: 600;
  box-shadow: var(--shadow-sm);
}

form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--color-muted);
}

textarea,
input[type='url'],
input[type='file'] {
  padding: 0.65rem 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
  font-size: 0.9375rem;
  font-family: inherit;
}

textarea {
  resize: vertical;
}

.hint {
  font-size: 0.75rem;
  color: var(--color-muted);
  font-weight: 400;
}

.error {
  color: #e0554f;
}

button.submit {
  height: 2.75rem;
  padding: 0 1.1rem;
  border: none;
  border-radius: var(--radius-md);
  background: var(--color-accent);
  color: var(--color-on-accent);
  font-size: 0.9375rem;
  font-weight: 600;
  cursor: pointer;
}

button.submit:hover:not(:disabled) {
  background: var(--color-accent-hover);
  box-shadow: var(--shadow-md);
}

button.submit:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.status-card {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface);
  padding: 2.5rem 1.5rem;
  text-align: center;
}

.status-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 3rem;
  margin: 0 auto 1rem;
  border-radius: var(--radius-pill);
}

.status-icon.success {
  background: var(--color-tint);
  color: var(--color-tint-text);
}

.status-icon.error-icon {
  background: var(--color-warn-bg);
  color: #e0554f;
}

.result-link {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  margin-top: 0.5rem;
  color: var(--color-accent);
  font-weight: 600;
  text-decoration: none;
}

.spinner {
  width: 2.25rem;
  height: 2.25rem;
  margin: 0 auto 1rem;
  border-radius: 50%;
  border: 3px solid var(--color-border);
  border-top-color: var(--color-accent);
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.status-card button {
  margin-top: 1rem;
  height: 2.5rem;
  padding: 0 1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface-2);
  color: var(--color-text);
  cursor: pointer;
  font-weight: 500;
}

.status-card button:hover {
  box-shadow: var(--shadow-sm);
}
</style>
