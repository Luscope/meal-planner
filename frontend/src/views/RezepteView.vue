<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { extractErrorMessage } from '@/lib/api'
import { fetchCuisines, fetchRecipes, type PaginatedRecipes, type Recipe } from '@/lib/recipes'

const recipes = ref<Recipe[]>([])
const meta = ref<PaginatedRecipes['meta'] | null>(null)
const cuisines = ref<string[]>([])
const errorMessage = ref('')
const isLoading = ref(false)
const page = ref(1)

const filters = reactive({
  cuisine: '',
  search: '',
  minCalories: null as number | null,
  maxCalories: null as number | null,
})

const ingredientInput = ref('')
const ingredientChips = ref<string[]>([])

async function loadRecipes() {
  errorMessage.value = ''
  isLoading.value = true

  try {
    const result = await fetchRecipes({
      cuisine: filters.cuisine || undefined,
      search: filters.search || undefined,
      min_calories: filters.minCalories ?? undefined,
      max_calories: filters.maxCalories ?? undefined,
      ingredients: ingredientChips.value.length > 0 ? ingredientChips.value : undefined,
      page: page.value,
    })
    recipes.value = result.data
    meta.value = result.meta
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isLoading.value = false
  }
}

function applyFilters() {
  page.value = 1
  loadRecipes()
}

function resetFilters() {
  filters.cuisine = ''
  filters.search = ''
  filters.minCalories = null
  filters.maxCalories = null
  ingredientChips.value = []
  page.value = 1
  loadRecipes()
}

function addIngredientChip() {
  const value = ingredientInput.value.trim()
  if (value && !ingredientChips.value.includes(value)) {
    ingredientChips.value.push(value)
  }
  ingredientInput.value = ''
}

function removeIngredientChip(index: number) {
  ingredientChips.value.splice(index, 1)
}

function goToPage(delta: number) {
  page.value += delta
  loadRecipes()
}

onMounted(async () => {
  try {
    cuisines.value = await fetchCuisines()
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  }

  await loadRecipes()
})
</script>

<template>
  <main class="rezepte">
    <div class="page-header">
      <h1>Rezepte</h1>
      <RouterLink to="/rezepte/import" class="import-link">+ Rezept importieren</RouterLink>
    </div>

    <form class="filters" @submit.prevent="applyFilters">
      <label>
        Küche
        <select v-model="filters.cuisine">
          <option value="">Alle</option>
          <option v-for="cuisine in cuisines" :key="cuisine" :value="cuisine">{{ cuisine }}</option>
        </select>
      </label>

      <label>
        Suche
        <input v-model="filters.search" type="text" placeholder="Titel…" />
      </label>

      <label>
        Kalorien min.
        <input v-model.number="filters.minCalories" type="number" min="0" />
      </label>

      <label>
        Kalorien max.
        <input v-model.number="filters.maxCalories" type="number" min="0" />
      </label>

      <label class="ingredients-field">
        Zutaten
        <div class="chip-input">
          <span v-for="(chip, index) in ingredientChips" :key="chip" class="chip">
            {{ chip }}
            <button type="button" @click="removeIngredientChip(index)">×</button>
          </span>
          <input
            v-model="ingredientInput"
            type="text"
            placeholder="Zutat…"
            @keydown.enter.prevent="addIngredientChip"
          />
          <button type="button" class="add-chip" title="Zutat hinzufügen" @click="addIngredientChip">
            +
          </button>
        </div>
      </label>

      <div class="filter-actions">
        <button type="submit">Filtern</button>
        <button type="button" class="secondary" @click="resetFilters">Zurücksetzen</button>
      </div>
    </form>

    <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
    <p v-if="isLoading" class="hint">Lädt…</p>
    <p v-else-if="recipes.length === 0" class="hint">Keine Rezepte gefunden.</p>

    <div class="recipe-grid">
      <RouterLink
        v-for="recipe in recipes"
        :key="recipe.id"
        :to="{ name: 'recipe-detail', params: { id: recipe.id } }"
        class="recipe-card"
      >
        <h2>{{ recipe.title }}</h2>
        <p v-if="recipe.cuisine" class="cuisine-badge">{{ recipe.cuisine }}</p>
        <p class="meta">
          <span v-if="recipe.calories_per_serving">{{ recipe.calories_per_serving }} kcal/Portion</span>
          <span>{{ recipe.servings }} Portionen</span>
        </p>
      </RouterLink>
    </div>

    <div v-if="meta && meta.last_page > 1" class="pagination">
      <button type="button" :disabled="page <= 1" @click="goToPage(-1)">← Zurück</button>
      <span>Seite {{ meta.current_page }} von {{ meta.last_page }}</span>
      <button type="button" :disabled="page >= meta.last_page" @click="goToPage(1)">Weiter →</button>
    </div>
  </main>
</template>

<style scoped>
.rezepte {
  max-width: 1100px;
  margin: 0 auto;
  padding: 1.5rem;
}

.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
}

.import-link {
  padding: 0.5rem 1rem;
  border-radius: var(--radius-sm);
  background: var(--color-button-bg);
  color: var(--color-button-text);
  text-decoration: none;
  font-size: 0.9rem;
}

.import-link:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow-md);
}

.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  align-items: end;
  margin-bottom: 1.5rem;
  padding: 1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
}

.filters label {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.85rem;
}

.ingredients-field {
  flex: 1;
  min-width: 220px;
}

select,
input[type='text'],
input[type='number'] {
  padding: 0.45rem 0.55rem;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-background-soft);
  color: var(--color-text);
  font-size: 0.9rem;
}

input[type='number'] {
  width: 6rem;
}

.chip-input {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  padding: 0.35rem;
  background: var(--color-background-soft);
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: 999px;
  padding: 0.15rem 0.5rem;
  font-size: 0.8rem;
  box-shadow: var(--shadow-sm);
}

.chip button {
  border: none;
  background: none;
  color: #e0554f;
  cursor: pointer;
  padding: 0;
}

.chip button:hover {
  box-shadow: none;
}

.chip-input input {
  border: none;
  background: none;
  flex: 1;
  min-width: 100px;
}

.filter-actions {
  display: flex;
  gap: 0.5rem;
}

button {
  padding: 0.5rem 1rem;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--color-button-bg);
  color: var(--color-button-text);
  cursor: pointer;
  font-size: 0.9rem;
}

button:hover:not(:disabled) {
  box-shadow: var(--shadow-md);
}

button.secondary {
  background: var(--color-background-soft);
  color: var(--color-text);
  border: 1px solid var(--color-border);
}

button.secondary:hover:not(:disabled) {
  box-shadow: var(--shadow-sm);
}

button.add-chip {
  padding: 0.25rem 0.6rem;
  font-size: 0.85rem;
  line-height: 1;
}

button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.error {
  color: #e0554f;
}

.hint {
  opacity: 0.7;
}

.recipe-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 1rem;
}

.recipe-card {
  display: block;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 1rem;
  text-decoration: none;
  color: var(--color-text);
  box-shadow: var(--shadow-sm);
}

.recipe-card:hover {
  border-color: var(--color-accent);
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}

.recipe-card h2 {
  margin: 0 0 0.5rem;
  font-size: 1.05rem;
}

.cuisine-badge {
  display: inline-block;
  font-size: 0.75rem;
  background: var(--color-background-soft);
  border-radius: 999px;
  padding: 0.15rem 0.6rem;
  margin: 0 0 0.5rem;
}

.meta {
  display: flex;
  gap: 0.75rem;
  font-size: 0.8rem;
  opacity: 0.75;
  margin: 0;
}

.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  margin-top: 1.5rem;
  font-size: 0.9rem;
}
</style>
