<script setup lang="ts">
import { reactive, ref } from 'vue'
import { extractErrorMessage } from '@/lib/api'
import {
  createMealPlan,
  updateMealPlan,
  MEAL_TYPE_LABELS,
  type FamilyMember,
  type MealPlan,
  type MealType,
  type RecipePickerItem,
} from '@/lib/mealPlanner'
import { formatShortDate, toIsoDate, weekdayLabel } from '@/lib/date'

const props = defineProps<{
  date: Date
  mealType: MealType
  recipes: RecipePickerItem[]
  familyMembers: FamilyMember[]
  mealPlan?: MealPlan | null
}>()

const emit = defineEmits<{
  close: []
  created: []
  updated: []
}>()

const isEditing = props.mealPlan != null

const selectedRecipeId = ref<number | null>(props.mealPlan?.recipe.id ?? null)
const plannedServings = ref<number | null>(
  props.mealPlan ? Number(props.mealPlan.planned_servings) : null,
)
const errorMessage = ref('')
const isSubmitting = ref(false)

const memberSelection = reactive<Record<number, { checked: boolean; multiplier: number }>>(
  Object.fromEntries(
    props.familyMembers.map((member) => {
      const assignment = props.mealPlan?.family_members.find((m) => m.id === member.id)

      return [
        member.id,
        {
          checked: assignment !== undefined,
          multiplier: assignment ? Number(assignment.portion_multiplier) : 1,
        },
      ]
    }),
  ),
)

function onRecipeChange() {
  const recipe = props.recipes.find((r) => r.id === selectedRecipeId.value)
  plannedServings.value = recipe?.servings ?? null
}

async function handleSubmit() {
  if (!selectedRecipeId.value) {
    errorMessage.value = 'Bitte ein Rezept auswählen.'
    return
  }

  errorMessage.value = ''
  isSubmitting.value = true

  const familyMembersPayload = Object.entries(memberSelection)
    .filter(([, value]) => value.checked)
    .map(([familyMemberId, value]) => ({
      family_member_id: Number(familyMemberId),
      portion_multiplier: value.multiplier,
    }))

  try {
    if (isEditing && props.mealPlan) {
      await updateMealPlan(props.mealPlan.id, {
        recipe_id: selectedRecipeId.value,
        planned_servings: plannedServings.value ?? undefined,
        family_members: familyMembersPayload,
      })
      emit('updated')
    } else {
      await createMealPlan({
        recipe_id: selectedRecipeId.value,
        date: toIsoDate(props.date),
        meal_type: props.mealType,
        planned_servings: plannedServings.value ?? undefined,
        family_members: familyMembersPayload,
      })
      emit('created')
    }
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <div class="overlay" @click.self="emit('close')">
    <div class="modal">
      <h2>
        <template v-if="isEditing">Eintrag bearbeiten · </template
        >{{ MEAL_TYPE_LABELS[mealType] }} · {{ weekdayLabel(date) }}, {{ formatShortDate(date) }}
      </h2>

      <form @submit.prevent="handleSubmit">
        <label>
          Rezept
          <select v-model.number="selectedRecipeId" required @change="onRecipeChange">
            <option :value="null" disabled>Bitte wählen…</option>
            <option v-for="recipe in recipes" :key="recipe.id" :value="recipe.id">
              {{ recipe.title }}
            </option>
          </select>
        </label>

        <label>
          Portionen
          <input v-model.number="plannedServings" type="number" min="0.5" step="0.5" />
        </label>

        <fieldset v-if="familyMembers.length > 0">
          <legend>Wer isst mit?</legend>
          <div v-for="member in familyMembers" :key="member.id" class="member-row">
            <label class="member-checkbox">
              <input v-model="memberSelection[member.id]!.checked" type="checkbox" />
              {{ member.name }}
            </label>
            <input
              v-model.number="memberSelection[member.id]!.multiplier"
              type="number"
              min="0.1"
              step="0.1"
              :disabled="!memberSelection[member.id]!.checked"
              title="Portionsfaktor (1 = normale Portion, 0.5 = halbe Portion)"
            />
          </div>
        </fieldset>

        <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

        <div class="actions">
          <button type="button" class="secondary" @click="emit('close')">Abbrechen</button>
          <button type="submit" :disabled="isSubmitting">
            {{ isSubmitting ? 'Wird gespeichert…' : isEditing ? 'Speichern' : 'Hinzufügen' }}
          </button>
        </div>
      </form>
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
  animation: fade-in 0.15s ease;
}

.modal {
  background: var(--color-background);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
  padding: 1.5rem;
  width: 100%;
  max-width: 380px;
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
  font-size: 1.1rem;
}

form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.9rem;
}

select,
input[type='number'] {
  padding: 0.5rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-background-soft);
  color: var(--color-text);
  font-size: 1rem;
}

fieldset {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 0.75rem;
}

legend {
  font-size: 0.85rem;
  padding: 0 0.35rem;
}

.member-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.25rem 0;
}

.member-checkbox {
  flex-direction: row;
  align-items: center;
  gap: 0.5rem;
}

.member-row input[type='number'] {
  width: 4.5rem;
}

.error {
  color: #e0554f;
  font-size: 0.9rem;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
}

button {
  padding: 0.5rem 1rem;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--color-button-bg);
  color: var(--color-button-text);
  cursor: pointer;
}

button:hover:not(:disabled) {
  box-shadow: var(--shadow-md);
}

button.secondary {
  background: var(--color-background-soft);
  color: var(--color-text);
  border: 1px solid var(--color-border);
}

button.secondary:hover {
  box-shadow: var(--shadow-sm);
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
