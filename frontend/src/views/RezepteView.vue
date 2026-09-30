<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { extractErrorMessage } from '@/lib/api'
import {
  DIET_TYPES,
  DIET_TYPE_LABELS,
  fetchCuisines,
  fetchRecipes,
  RECIPE_CATEGORIES,
  RECIPE_CATEGORY_LABELS,
  UNASSIGNED_FILTER,
  type DietType,
  type PaginatedRecipes,
  type Recipe,
  type RecipeCategory,
} from '@/lib/recipes'
import AppIcon from '@/components/AppIcon.vue'

const recipes = ref<Recipe[]>([])
const meta = ref<PaginatedRecipes['meta'] | null>(null)
const cuisines = ref<string[]>([])
const errorMessage = ref('')
const isLoading = ref(false)
const page = ref(1)

const filters = reactive({
  cuisine: '',
  category: '' as RecipeCategory | typeof UNASSIGNED_FILTER | '',
  dietType: '' as DietType | typeof UNASSIGNED_FILTER | '',
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
      category: filters.category || undefined,
      diet_type: filters.dietType || undefined,
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
  filters.category = ''
  filters.dietType = ''
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

function dietBadgeClass(dietType: DietType): string {
  if (dietType === 'pescetarian') return 'diet-badge diet-badge-fish'
  if (dietType === 'vegan' || dietType === 'vegetarian') return 'diet-badge diet-badge-green'
  return 'diet-badge diet-badge-neutral'
}

function totalTime(recipe: Recipe): number | null {
  const total = (recipe.prep_time_minutes ?? 0) + (recipe.cook_time_minutes ?? 0)
  return total > 0 ? total : null
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
      <RouterLink to="/rezepte/import" class="import-link">
        <AppIcon name="import" :size="18" />
        Rezept importieren
      </RouterLink>
    </div>

    <form class="filters" @submit.prevent="applyFilters">
      <div class="filters-row">
        <label class="search-field">
          Titel
          <span class="input-with-icon">
            <AppIcon name="search" :size="17" />
            <input v-model="filters.search" type="search" placeholder="Rezept suchen" />
          </span>
        </label>

        <label class="ingredients-field">
          Zutaten
          <div class="chip-input">
            <span v-for="(chip, index) in ingredientChips" :key="chip" class="chip">
              {{ chip }}
              <button type="button" :aria-label="`${chip} entfernen`" @click="removeIngredientChip(index)">
                <AppIcon name="close" :size="12" />
              </button>
            </span>
            <input
              v-model="ingredientInput"
              type="text"
              :placeholder="ingredientChips.length ? 'weitere Zutat' : 'z. B. Kartoffel, Enter zum Hinzufügen'"
              @keydown.enter.prevent="addIngredientChip"
            />
            <button type="button" class="add-chip" title="Zutat hinzufügen" @click="addIngredientChip">
              <AppIcon name="plus" :size="15" />
            </button>
          </div>
        </label>
      </div>

      <div class="filters-row">
        <label>
          Küche
          <span class="select-wrapper">
            <select v-model="filters.cuisine">
              <option value="">Alle</option>
              <option :value="UNASSIGNED_FILTER">Ohne Zuordnung</option>
              <option v-for="cuisine in cuisines" :key="cuisine" :value="cuisine">{{ cuisine }}</option>
            </select>
            <AppIcon name="chevron-down" :size="16" />
          </span>
        </label>

        <label>
          Kategorie
          <span class="select-wrapper">
            <select v-model="filters.category">
              <option value="">Alle</option>
              <option :value="UNASSIGNED_FILTER">Ohne Zuordnung</option>
              <option v-for="category in RECIPE_CATEGORIES" :key="category" :value="category">
                {{ RECIPE_CATEGORY_LABELS[category] }}
              </option>
            </select>
            <AppIcon name="chevron-down" :size="16" />
          </span>
        </label>

        <label>
          Ernährungsform
          <span class="select-wrapper">
            <select v-model="filters.dietType">
              <option value="">Alle</option>
              <option :value="UNASSIGNED_FILTER">Ohne Zuordnung</option>
              <option v-for="dietType in DIET_TYPES" :key="dietType" :value="dietType">
                {{ DIET_TYPE_LABELS[dietType] }}
              </option>
            </select>
            <AppIcon name="chevron-down" :size="16" />
          </span>
        </label>

        <div class="calorie-range">
          <span class="calorie-range-label">Kalorien pro Portion</span>
          <div class="calorie-range-inputs">
            <input v-model.number="filters.minCalories" type="number" min="0" placeholder="min" />
            <span>–</span>
            <input v-model.number="filters.maxCalories" type="number" min="0" placeholder="max" />
            <span class="unit">kcal</span>
          </div>
        </div>
      </div>

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
        <p v-if="recipe.cuisine || recipe.category" class="card-eyebrow">
          {{ [recipe.cuisine, recipe.category && RECIPE_CATEGORY_LABELS[recipe.category]].filter(Boolean).join(' · ') }}
        </p>
        <h2>{{ recipe.title }}</h2>
        <div v-if="recipe.diet_type" class="badges">
          <span :class="dietBadgeClass(recipe.diet_type)">{{ DIET_TYPE_LABELS[recipe.diet_type] }}</span>
        </div>
        <p class="meta">
          <span v-if="totalTime(recipe)" class="meta-item">
            <AppIcon name="clock" :size="15" />
            {{ totalTime(recipe) }} Min
          </span>
          <span v-if="recipe.calories_per_serving" class="meta-item">
            <AppIcon name="flame" :size="15" />
            {{ recipe.calories_per_serving }} kcal
          </span>
          <span class="meta-item">
            <AppIcon name="users" :size="15" />
            {{ recipe.servings }} Port.
          </span>
        </p>
      </RouterLink>
    </div>

    <div v-if="meta && meta.last_page > 1" class="pagination">
      <button type="button" class="icon-button" :disabled="page <= 1" @click="goToPage(-1)">
        <AppIcon name="chevron-left" />
      </button>
      <span>Seite {{ meta.current_page }} von {{ meta.last_page }}</span>
      <button type="button" class="icon-button" :disabled="page >= meta.last_page" @click="goToPage(1)">
        <AppIcon name="chevron-right" />
      </button>
    </div>
  </main>
</template>

<style scoped>
.rezepte {
  max-width: 1200px;
  margin: 0 auto;
  padding: 2rem 2.5rem;
}

.page-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  margin-bottom: 1.25rem;
  gap: 1rem;
}

.page-header h1 {
  margin: 0;
  font-size: 2.1rem;
}

.import-link {
  height: 2.75rem;
  padding: 0 1.1rem;
  border-radius: var(--radius-md);
  background: var(--color-accent);
  color: var(--color-on-accent);
  text-decoration: none;
  font-size: 0.9375rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.import-link:hover {
  background: var(--color-accent-hover);
  box-shadow: var(--shadow-md);
}

.filters {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  margin-bottom: 1.25rem;
  padding: 1.1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface);
}

.filters-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.9rem;
  align-items: end;
}

.filters label {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--color-muted);
}

.search-field {
  flex: 1.2;
  min-width: 200px;
}

.ingredients-field {
  flex: 1.5;
  min-width: 240px;
}

.calorie-range {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.calorie-range-label {
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--color-muted);
}

.calorie-range-inputs {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--color-muted);
}

.calorie-range-inputs input {
  width: 5rem;
}

.unit {
  font-size: 0.85rem;
}

select,
input[type='search'],
input[type='text'],
input[type='number'] {
  height: 2.75rem;
  box-sizing: border-box;
  padding: 0 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
  font-size: 0.9375rem;
}

.select-wrapper {
  position: relative;
  display: flex;
}

.select-wrapper select {
  flex-grow: 1;
  padding-right: 2.25rem;
  appearance: none;
  -webkit-appearance: none;
}

.select-wrapper svg {
  position: absolute;
  right: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-muted);
  pointer-events: none;
}

.input-with-icon {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  height: 2.75rem;
  box-sizing: border-box;
  padding: 0 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-muted);
}

.input-with-icon input {
  height: auto;
  padding: 0;
  border: none;
  flex: 1;
  min-width: 0;
  color: var(--color-text);
}

.chip-input {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  align-items: center;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  min-height: 2.75rem;
  box-sizing: border-box;
  padding: 0.35rem 0.5rem;
  background: var(--color-surface);
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  background: var(--color-tint);
  color: var(--color-tint-text);
  border-radius: var(--radius-sm);
  padding: 0.3rem 0.3rem 0.3rem 0.6rem;
  font-size: 0.8125rem;
  font-weight: 500;
}

.chip button {
  border: none;
  background: none;
  color: inherit;
  cursor: pointer;
  padding: 0.2rem;
  border-radius: 6px;
  display: flex;
}

.chip button:hover {
  box-shadow: none;
  background: rgba(0, 0, 0, 0.08);
}

.chip-input input {
  border: none;
  background: none;
  height: auto;
  padding: 0.2rem;
  flex: 1;
  min-width: 100px;
}

.filter-actions {
  display: flex;
  gap: 0.6rem;
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

button.add-chip {
  height: 2rem;
  width: 2rem;
  padding: 0;
  flex-shrink: 0;
  background: var(--color-surface-2);
  color: var(--color-text);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}

button.icon-button {
  width: 2.75rem;
  padding: 0;
  background: var(--color-surface);
  color: var(--color-text);
  border: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: center;
}

button.icon-button:hover:not(:disabled) {
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
}

button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.error {
  color: #e0554f;
}

.hint {
  color: var(--color-muted);
}

.recipe-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 1rem;
}

.recipe-card {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  min-height: 150px;
  box-sizing: border-box;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: 1rem 1.1rem;
  text-decoration: none;
  color: var(--color-text);
  background: var(--color-surface);
}

.recipe-card:hover {
  border-color: var(--color-accent);
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}

.card-eyebrow {
  margin: 0;
  font-size: 0.8125rem;
  color: var(--color-muted);
}

.recipe-card h2 {
  margin: 0;
  font-size: 1.0625rem;
  line-height: 1.3;
}

.badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.diet-badge {
  display: inline-block;
  font-size: 0.8125rem;
  font-weight: 500;
  border-radius: var(--radius-pill);
  padding: 0.2rem 0.65rem;
  margin: 0;
}

.diet-badge-green {
  background: var(--color-tint);
  color: var(--color-tint-text);
}

.diet-badge-fish {
  background: var(--color-fish-bg);
  color: var(--color-fish-text);
}

.diet-badge-neutral {
  background: var(--color-surface-2);
  color: var(--color-muted);
}

.meta {
  margin: auto 0 0;
  display: flex;
  flex-wrap: wrap;
  gap: 0.8rem;
  font-size: 0.8125rem;
  color: var(--color-muted);
}

.meta-item {
  display: flex;
  align-items: center;
  gap: 0.3rem;
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
