<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
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

const recipeSearch = ref(props.mealPlan?.recipe.title ?? '')
const isRecipeListOpen = ref(false)
const highlightedIndex = ref(-1)

const filteredRecipes = computed(() => {
  const query = recipeSearch.value.trim().toLowerCase()
  if (!query) return props.recipes
  return props.recipes.filter((recipe) => recipe.title.toLowerCase().includes(query))
})

watch(recipeSearch, () => {
  highlightedIndex.value = -1

  const selected = props.recipes.find((recipe) => recipe.id === selectedRecipeId.value)
  if (!selected || selected.title !== recipeSearch.value) {
    selectedRecipeId.value = null
  }
})

function openRecipeList(event: FocusEvent) {
  isRecipeListOpen.value = true
  ;(event.target as HTMLInputElement).select()
}

function closeRecipeListDelayed() {
  window.setTimeout(() => {
    isRecipeListOpen.value = false
  }, 150)
}

function selectRecipe(recipe: RecipePickerItem) {
  selectedRecipeId.value = recipe.id
  recipeSearch.value = recipe.title
  plannedServings.value = recipe.servings
  isRecipeListOpen.value = false
}

function moveHighlight(delta: number) {
  if (!isRecipeListOpen.value) {
    isRecipeListOpen.value = true
    return
  }

  const maxIndex = filteredRecipes.value.length - 1
  if (maxIndex < 0) return

  highlightedIndex.value = Math.min(maxIndex, Math.max(0, highlightedIndex.value + delta))
}

function selectHighlighted() {
  const recipe = filteredRecipes.value[highlightedIndex.value]
  if (recipe) selectRecipe(recipe)
}

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
          <div class="combobox">
            <input
              v-model="recipeSearch"
              type="text"
              placeholder="Rezept suchen…"
              autocomplete="off"
              @focus="openRecipeList"
              @blur="closeRecipeListDelayed"
              @keydown.down.prevent="moveHighlight(1)"
              @keydown.up.prevent="moveHighlight(-1)"
              @keydown.enter.prevent="selectHighlighted"
              @keydown.esc="isRecipeListOpen = false"
            />
            <ul v-if="isRecipeListOpen" class="combobox-list">
              <li
                v-for="(recipe, index) in filteredRecipes"
                :key="recipe.id"
                :class="{ highlighted: index === highlightedIndex, selected: recipe.id === selectedRecipeId }"
                @mousedown.prevent="selectRecipe(recipe)"
              >
                {{ recipe.title }}
              </li>
              <li v-if="filteredRecipes.length === 0" class="combobox-empty">
                Keine Rezepte gefunden.
              </li>
            </ul>
          </div>
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

input[type='text'],
input[type='number'] {
  padding: 0.5rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-background-soft);
  color: var(--color-text);
  font-size: 1rem;
  width: 100%;
}

.combobox {
  position: relative;
}

.combobox-list {
  position: absolute;
  z-index: 10;
  top: calc(100% + 0.25rem);
  left: 0;
  right: 0;
  max-height: 240px;
  overflow-y: auto;
  margin: 0;
  padding: 0.25rem;
  list-style: none;
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-md);
}

.combobox-list li {
  padding: 0.45rem 0.6rem;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.9rem;
}

.combobox-list li.highlighted,
.combobox-list li:hover {
  background: var(--color-background-soft);
}

.combobox-list li.selected {
  font-weight: 600;
  color: var(--color-accent);
}

.combobox-empty {
  opacity: 0.7;
  cursor: default !important;
}

.combobox-empty:hover {
  background: none !important;
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
