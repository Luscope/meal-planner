<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import { extractErrorMessage } from '@/lib/api'
import {
  createRecipeImport,
  fetchRecipeImportStatus,
  type ImportStatus,
} from '@/lib/recipeImport'

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
    <RouterLink to="/rezepte" class="back-link">← Zurück zu den Rezepten</RouterLink>
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
          <p>✅ Rezept erfolgreich importiert!</p>
          <RouterLink :to="{ name: 'recipe-detail', params: { id: recipeId! } }">
            Zum Rezept →
          </RouterLink>
        </template>
        <template v-else-if="importStatus === 'failed'">
          <p class="error">❌ Import fehlgeschlagen: {{ errorMessage }}</p>
          <button type="button" @click="reset">Erneut versuchen</button>
        </template>
      </div>
    </template>
  </main>
</template>

<style scoped>
.import {
  max-width: 560px;
  margin: 0 auto;
  padding: 1.5rem;
}

.back-link {
  display: inline-block;
  margin-bottom: 1rem;
  color: var(--color-link);
  text-decoration: none;
  font-size: 0.9rem;
}

.back-link:hover {
  text-decoration: underline;
}

.tabs {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1.25rem;
}

.tabs button {
  padding: 0.5rem 1rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  background: var(--color-background-soft);
  color: var(--color-text);
  cursor: pointer;
  font-size: 0.9rem;
}

.tabs button:hover {
  box-shadow: var(--shadow-sm);
}

.tabs button.active {
  background: var(--color-button-bg);
  border-color: var(--color-button-bg);
  color: var(--color-button-text);
}

.tabs button.active:hover {
  box-shadow: var(--shadow-md);
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
  font-size: 0.9rem;
}

textarea,
input[type='url'],
input[type='file'] {
  padding: 0.55rem 0.65rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-background-soft);
  color: var(--color-text);
  font-size: 1rem;
  font-family: inherit;
}

textarea {
  resize: vertical;
}

.hint {
  font-size: 0.75rem;
  opacity: 0.7;
}

.error {
  color: #e0554f;
}

button.submit {
  padding: 0.6rem 1rem;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--color-button-bg);
  color: var(--color-button-text);
  font-size: 1rem;
  cursor: pointer;
}

button.submit:hover:not(:disabled) {
  box-shadow: var(--shadow-md);
}

button.submit:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.status-card {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  padding: 2rem 1.5rem;
  text-align: center;
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
  padding: 0.5rem 1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-background-soft);
  color: var(--color-text);
  cursor: pointer;
}

.status-card button:hover {
  box-shadow: var(--shadow-sm);
}
</style>
