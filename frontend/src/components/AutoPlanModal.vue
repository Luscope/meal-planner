<script setup lang="ts">
import { ref } from 'vue'
import { extractErrorMessage } from '@/lib/api'
import { applyAutoPlan, previewAutoPlan, type AutoPlanAssignment } from '@/lib/autoPlan'
import { formatShortDate, weekdayLabel } from '@/lib/date'
import { MEAL_TYPES, MEAL_TYPE_LABELS, type MealType } from '@/lib/mealPlanner'
import AppIcon from '@/components/AppIcon.vue'

const props = defineProps<{
  startDate: string
  endDate: string
}>()

const emit = defineEmits<{
  close: []
  applied: []
}>()

const step = ref<'form' | 'preview'>('form')
const criteria = ref('')
const selectedMealTypes = ref<MealType[]>([...MEAL_TYPES])
const assignments = ref<AutoPlanAssignment[]>([])
const selectedIndices = ref<Set<number>>(new Set())

const isLoading = ref(false)
const isApplying = ref(false)
const errorMessage = ref('')
const infoMessage = ref('')

function toggleMealType(type: MealType) {
  const index = selectedMealTypes.value.indexOf(type)
  if (index === -1) {
    selectedMealTypes.value.push(type)
  } else {
    selectedMealTypes.value.splice(index, 1)
  }
}

function toggleSelected(index: number) {
  if (selectedIndices.value.has(index)) {
    selectedIndices.value.delete(index)
  } else {
    selectedIndices.value.add(index)
  }
}

async function generatePreview() {
  errorMessage.value = ''
  infoMessage.value = ''

  if (selectedMealTypes.value.length === 0) {
    errorMessage.value = 'Bitte mindestens eine Mahlzeit auswählen.'
    return
  }

  isLoading.value = true

  try {
    const result = await previewAutoPlan({
      start_date: props.startDate,
      end_date: props.endDate,
      meal_types: selectedMealTypes.value,
      criteria: criteria.value || undefined,
    })

    if (result.length === 0) {
      infoMessage.value =
        'Für den gewählten Zeitraum und die gewählten Mahlzeiten gibt es keine leeren Termine mehr.'
      return
    }

    assignments.value = result
    selectedIndices.value = new Set(result.map((_, index) => index))
    step.value = 'preview'
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isLoading.value = false
  }
}

async function handleApply() {
  errorMessage.value = ''
  isApplying.value = true

  try {
    const chosen = assignments.value.filter((_, index) => selectedIndices.value.has(index))
    await applyAutoPlan(chosen)
    emit('applied')
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isApplying.value = false
  }
}

function backToForm() {
  step.value = 'form'
}
</script>

<template>
  <div class="overlay" @click.self="emit('close')">
    <div class="modal">
      <h2>
        <AppIcon name="auto-plan" :size="19" />
        Woche automatisch planen
      </h2>

      <template v-if="step === 'form'">
        <p class="hint">
          Claude schlägt Rezepte aus eurer Datenbank für alle noch leeren Termine im aktuellen
          Zeitraum vor. Bereits geplante Mahlzeiten werden nicht verändert.
        </p>

        <label>
          Kriterien (optional)
          <textarea
            v-model="criteria"
            rows="3"
            placeholder="z. B. wenig Fleisch, viel Gemüse, montags schnell, freitags Fisch, Kinder mögen nichts Scharfes…"
          ></textarea>
        </label>

        <fieldset>
          <legend>Mahlzeiten</legend>
          <label v-for="mealType in MEAL_TYPES" :key="mealType" class="meal-type-checkbox">
            <input
              type="checkbox"
              :checked="selectedMealTypes.includes(mealType)"
              @change="toggleMealType(mealType)"
            />
            {{ MEAL_TYPE_LABELS[mealType] }}
          </label>
        </fieldset>

        <p v-if="infoMessage" class="info">{{ infoMessage }}</p>
        <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

        <div class="actions">
          <button type="button" class="secondary" @click="emit('close')">Abbrechen</button>
          <button type="button" :disabled="isLoading" @click="generatePreview">
            {{ isLoading ? 'Claude plant…' : 'Vorschlag erstellen' }}
          </button>
        </div>
      </template>

      <template v-else>
        <p class="hint">
          {{ selectedIndices.size }} von {{ assignments.length }} Vorschlägen ausgewählt. Häkchen
          entfernen, um einzelne Vorschläge zu verwerfen.
        </p>

        <ul class="suggestion-list">
          <li v-for="(assignment, index) in assignments" :key="index">
            <label>
              <input
                type="checkbox"
                :checked="selectedIndices.has(index)"
                @change="toggleSelected(index)"
              />
              <span class="suggestion-text">
                <strong
                  >{{ weekdayLabel(new Date(assignment.date)) }},
                  {{ formatShortDate(new Date(assignment.date)) }} –
                  {{ MEAL_TYPE_LABELS[assignment.meal_type] }}</strong
                >
                <br />
                {{ assignment.recipe_title }}
                <span v-if="assignment.family_members.length" class="members">
                  ({{ assignment.family_members.map((m) => m.name).join(', ') }})
                </span>
              </span>
            </label>
          </li>
        </ul>

        <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

        <div class="actions">
          <button type="button" class="secondary" @click="backToForm">Zurück</button>
          <button type="button" :disabled="isApplying || selectedIndices.size === 0" @click="handleApply">
            {{ isApplying ? 'Wird übernommen…' : `${selectedIndices.size} Vorschläge übernehmen` }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
  padding: 1rem;
  animation: fade-in 0.15s ease;
}

.modal {
  background: var(--color-surface);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-lg);
  padding: 1.5rem;
  width: 100%;
  max-width: 480px;
  max-height: 90vh;
  overflow-y: auto;
  animation: modal-in 0.18s ease;
}

@keyframes fade-in {
  from {
    opacity: 0;
  }
}

@keyframes modal-in {
  from {
    opacity: 0;
    transform: translateY(8px) scale(0.98);
  }
}

h2 {
  margin-top: 0;
  font-size: 1.2rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.hint {
  font-size: 0.85rem;
  opacity: 0.75;
  margin-bottom: 1rem;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.9rem;
  margin-bottom: 1rem;
}

textarea {
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface-2);
  color: var(--color-text);
  font-size: 0.9375rem;
  font-family: inherit;
  resize: vertical;
}

fieldset {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 0.75rem;
  margin-bottom: 1rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

legend {
  font-size: 0.85rem;
  padding: 0 0.35rem;
}

.meal-type-checkbox {
  flex-direction: row;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.85rem;
}

.info {
  font-size: 0.85rem;
  color: #b8860b;
}

.error {
  color: #e0554f;
  font-size: 0.9rem;
}

.suggestion-list {
  list-style: none;
  margin: 0 0 1rem;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-height: 50vh;
  overflow-y: auto;
}

.suggestion-list li {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 0.6rem 0.75rem;
  box-shadow: var(--shadow-sm);
}

.suggestion-list label {
  flex-direction: row;
  align-items: flex-start;
  gap: 0.6rem;
  margin: 0;
  cursor: pointer;
}

.suggestion-list input[type='checkbox'] {
  margin-top: 0.2rem;
}

.suggestion-text {
  font-size: 0.85rem;
}

.members {
  opacity: 0.7;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
}

button {
  height: 2.75rem;
  padding: 0 1.1rem;
  border: none;
  border-radius: var(--radius-md);
  background: var(--color-accent);
  color: var(--color-on-accent);
  cursor: pointer;
  font-weight: 600;
  font-size: 0.9375rem;
}

button:hover:not(:disabled) {
  background: var(--color-accent-hover);
  box-shadow: var(--shadow-md);
}

button.secondary {
  background: var(--color-surface-2);
  color: var(--color-text);
  border: 1px solid var(--color-border);
  font-weight: 500;
}

button.secondary:hover:not(:disabled) {
  background: var(--color-surface-2);
  box-shadow: var(--shadow-sm);
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
