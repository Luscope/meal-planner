<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { extractErrorMessage } from '@/lib/api'
import {
  fetchRecipe,
  RECIPE_CATEGORIES,
  RECIPE_CATEGORY_LABELS,
  updateRecipe,
  type RecipeCategory,
} from '@/lib/recipes'

const route = useRoute()
const router = useRouter()
const recipeId = route.params.id as string

const title = ref('')
const cuisine = ref('')
const category = ref<RecipeCategory | ''>('')
const description = ref('')
const instructionsText = ref('')
const servings = ref<number | null>(null)
const prepTimeMinutes = ref<number | null>(null)
const cookTimeMinutes = ref<number | null>(null)
const caloriesPerServing = ref<number | null>(null)
const proteinPerServingG = ref<number | null>(null)
const carbsPerServingG = ref<number | null>(null)
const fatPerServingG = ref<number | null>(null)

interface IngredientRow {
  name: string
  quantity: number | null
  unit: string
  notes: string
}

const ingredientRows = reactive<IngredientRow[]>([])

const isLoading = ref(false)
const isSubmitting = ref(false)
const errorMessage = ref('')

function addIngredientRow() {
  ingredientRows.push({ name: '', quantity: null, unit: '', notes: '' })
}

function removeIngredientRow(index: number) {
  ingredientRows.splice(index, 1)
}

onMounted(async () => {
  isLoading.value = true

  try {
    const recipe = await fetchRecipe(recipeId)

    title.value = recipe.title
    cuisine.value = recipe.cuisine ?? ''
    category.value = recipe.category ?? ''
    description.value = recipe.description ?? ''
    instructionsText.value = recipe.instructions.join('\n')
    servings.value = recipe.servings
    prepTimeMinutes.value = recipe.prep_time_minutes
    cookTimeMinutes.value = recipe.cook_time_minutes
    caloriesPerServing.value = recipe.calories_per_serving
    proteinPerServingG.value = recipe.protein_per_serving_g ? Number(recipe.protein_per_serving_g) : null
    carbsPerServingG.value = recipe.carbs_per_serving_g ? Number(recipe.carbs_per_serving_g) : null
    fatPerServingG.value = recipe.fat_per_serving_g ? Number(recipe.fat_per_serving_g) : null

    ingredientRows.push(
      ...recipe.ingredients.map((ingredient) => ({
        name: ingredient.name,
        quantity: Number(ingredient.quantity),
        unit: ingredient.unit,
        notes: ingredient.notes ?? '',
      })),
    )
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isLoading.value = false
  }
})

async function handleSubmit() {
  const instructions = instructionsText.value
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.length > 0)

  if (instructions.length === 0) {
    errorMessage.value = 'Bitte mindestens einen Zubereitungsschritt angeben.'
    return
  }

  const invalidIngredient = ingredientRows.find(
    (row) => !row.name.trim() || !row.unit.trim() || row.quantity === null,
  )

  if (invalidIngredient) {
    errorMessage.value = 'Bitte bei allen Zutaten Name, Menge und Einheit angeben.'
    return
  }

  errorMessage.value = ''
  isSubmitting.value = true

  try {
    await updateRecipe(recipeId, {
      title: title.value,
      cuisine: cuisine.value || null,
      category: category.value || null,
      description: description.value || null,
      instructions,
      servings: servings.value ?? undefined,
      prep_time_minutes: prepTimeMinutes.value,
      cook_time_minutes: cookTimeMinutes.value,
      calories_per_serving: caloriesPerServing.value,
      protein_per_serving_g: proteinPerServingG.value,
      carbs_per_serving_g: carbsPerServingG.value,
      fat_per_serving_g: fatPerServingG.value,
      ingredients: ingredientRows.map((row) => ({
        name: row.name.trim(),
        quantity: row.quantity as number,
        unit: row.unit.trim(),
        notes: row.notes.trim() || null,
      })),
    })

    await router.push({ name: 'recipe-detail', params: { id: recipeId } })
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <main class="recipe-edit">
    <RouterLink :to="{ name: 'recipe-detail', params: { id: recipeId } }" class="back-link">
      ← Zurück zum Rezept
    </RouterLink>

    <h1>Rezept bearbeiten</h1>

    <p v-if="isLoading" class="hint">Lädt…</p>

    <form v-else @submit.prevent="handleSubmit">
      <label>
        Titel
        <input v-model="title" type="text" required />
      </label>

      <label>
        Küche (optional)
        <input v-model="cuisine" type="text" />
      </label>

      <label>
        Kategorie
        <select v-model="category">
          <option value="">Keine Angabe</option>
          <option v-for="option in RECIPE_CATEGORIES" :key="option" :value="option">
            {{ RECIPE_CATEGORY_LABELS[option] }}
          </option>
        </select>
      </label>

      <label>
        Beschreibung (optional)
        <textarea v-model="description" rows="2"></textarea>
      </label>

      <div class="grid">
        <label>
          Portionen
          <input v-model.number="servings" type="number" min="1" required />
        </label>
        <label>
          Vorbereitung (Min.)
          <input v-model.number="prepTimeMinutes" type="number" min="0" />
        </label>
        <label>
          Kochzeit (Min.)
          <input v-model.number="cookTimeMinutes" type="number" min="0" />
        </label>
        <label>
          Kalorien/Portion
          <input v-model.number="caloriesPerServing" type="number" min="0" />
        </label>
        <label>
          Protein/Portion (g)
          <input v-model.number="proteinPerServingG" type="number" min="0" step="0.01" />
        </label>
        <label>
          Kohlenhydrate/Portion (g)
          <input v-model.number="carbsPerServingG" type="number" min="0" step="0.01" />
        </label>
        <label>
          Fett/Portion (g)
          <input v-model.number="fatPerServingG" type="number" min="0" step="0.01" />
        </label>
      </div>

      <label>
        Zubereitung (ein Schritt pro Zeile)
        <textarea v-model="instructionsText" rows="6" required></textarea>
      </label>

      <fieldset>
        <legend>Zutaten</legend>

        <div v-for="(row, index) in ingredientRows" :key="index" class="ingredient-row">
          <input v-model="row.name" type="text" placeholder="Name" required />
          <input v-model.number="row.quantity" type="number" min="0" step="0.1" placeholder="Menge" required />
          <input v-model="row.unit" type="text" placeholder="Einheit" required />
          <input v-model="row.notes" type="text" placeholder="Notiz (optional)" />
          <button type="button" class="remove" title="Entfernen" @click="removeIngredientRow(index)">
            ×
          </button>
        </div>

        <button type="button" class="secondary add-row" @click="addIngredientRow">
          + Zutat hinzufügen
        </button>
      </fieldset>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <div class="actions">
        <RouterLink :to="{ name: 'recipe-detail', params: { id: recipeId } }" class="secondary-link">
          Abbrechen
        </RouterLink>
        <button type="submit" :disabled="isSubmitting">
          {{ isSubmitting ? 'Wird gespeichert…' : 'Speichern' }}
        </button>
      </div>
    </form>
  </main>
</template>

<style scoped>
.recipe-edit {
  max-width: 700px;
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

.hint {
  opacity: 0.7;
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

input,
textarea,
select {
  padding: 0.5rem 0.6rem;
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

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 1rem;
}

fieldset {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

legend {
  font-size: 0.9rem;
  padding: 0 0.35rem;
}

.ingredient-row {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr 2fr auto;
  gap: 0.5rem;
  align-items: center;
}

.ingredient-row input {
  font-size: 0.9rem;
}

.remove {
  border: none;
  background: none;
  color: #e0554f;
  font-size: 1.2rem;
  cursor: pointer;
  padding: 0 0.3rem;
  opacity: 0.7;
}

.remove:hover {
  opacity: 1;
  transform: scale(1.15);
  box-shadow: none;
}

.add-row {
  align-self: flex-start;
}

.error {
  color: #e0554f;
  font-size: 0.9rem;
}

.actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 1rem;
}

.secondary-link {
  color: var(--color-text);
  text-decoration: none;
  font-size: 0.9rem;
  opacity: 0.8;
}

button {
  padding: 0.6rem 1.1rem;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--color-button-bg);
  color: var(--color-button-text);
  font-size: 0.95rem;
  cursor: pointer;
}

button:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: var(--shadow-md);
}

button.secondary {
  background: var(--color-background-soft);
  color: var(--color-text);
  border: 1px solid var(--color-border);
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
