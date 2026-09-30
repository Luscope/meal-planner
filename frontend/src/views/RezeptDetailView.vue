<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { extractErrorMessage } from '@/lib/api'
import { DIET_TYPE_LABELS, fetchRecipe, RECIPE_CATEGORY_LABELS, type DietType, type Recipe } from '@/lib/recipes'
import AppIcon from '@/components/AppIcon.vue'

const route = useRoute()
const recipe = ref<Recipe | null>(null)
const errorMessage = ref('')
const isLoading = ref(false)

function dietBadgeClass(dietType: DietType): string {
  if (dietType === 'pescetarian') return 'diet-badge diet-badge-fish'
  if (dietType === 'vegan' || dietType === 'vegetarian') return 'diet-badge diet-badge-green'
  return 'diet-badge diet-badge-neutral'
}

onMounted(async () => {
  isLoading.value = true

  try {
    recipe.value = await fetchRecipe(route.params.id as string)
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isLoading.value = false
  }
})
</script>

<template>
  <main class="recipe-detail">
    <div class="top-row">
      <RouterLink :to="{ name: 'recipes' }" class="back-link">
        <AppIcon name="chevron-left" :size="16" />
        Zurück zu den Rezepten
      </RouterLink>
      <RouterLink
        v-if="recipe"
        :to="{ name: 'recipe-edit', params: { id: recipe.id } }"
        class="edit-link"
      >
        Bearbeiten
      </RouterLink>
    </div>

    <p v-if="isLoading" class="hint">Lädt…</p>
    <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

    <template v-if="recipe">
      <p v-if="recipe.cuisine || recipe.category" class="eyebrow">
        {{ [recipe.cuisine, recipe.category && RECIPE_CATEGORY_LABELS[recipe.category]].filter(Boolean).join(' · ') }}
      </p>
      <h1>{{ recipe.title }}</h1>
      <div v-if="recipe.diet_type" class="badges">
        <span :class="dietBadgeClass(recipe.diet_type)">{{ DIET_TYPE_LABELS[recipe.diet_type] }}</span>
      </div>
      <p v-if="recipe.description" class="description">{{ recipe.description }}</p>

      <dl class="meta-grid">
        <div>
          <dt>Portionen</dt>
          <dd>{{ recipe.servings }}</dd>
        </div>
        <div v-if="recipe.prep_time_minutes">
          <dt>Vorbereitung</dt>
          <dd>{{ recipe.prep_time_minutes }} Min.</dd>
        </div>
        <div v-if="recipe.cook_time_minutes">
          <dt>Kochzeit</dt>
          <dd>{{ recipe.cook_time_minutes }} Min.</dd>
        </div>
        <div v-if="recipe.calories_per_serving">
          <dt>Kalorien/Portion</dt>
          <dd>{{ recipe.calories_per_serving }} kcal</dd>
        </div>
        <div v-if="recipe.protein_per_serving_g">
          <dt>Protein/Portion</dt>
          <dd>{{ recipe.protein_per_serving_g }} g</dd>
        </div>
        <div v-if="recipe.carbs_per_serving_g">
          <dt>Kohlenhydrate/Portion</dt>
          <dd>{{ recipe.carbs_per_serving_g }} g</dd>
        </div>
        <div v-if="recipe.fat_per_serving_g">
          <dt>Fett/Portion</dt>
          <dd>{{ recipe.fat_per_serving_g }} g</dd>
        </div>
      </dl>

      <section>
        <h2>Zutaten</h2>
        <ul class="ingredient-list">
          <li v-for="ingredient in recipe.ingredients" :key="ingredient.id">
            {{ ingredient.quantity }} {{ ingredient.unit }} {{ ingredient.name }}
            <span v-if="ingredient.notes" class="notes">({{ ingredient.notes }})</span>
          </li>
        </ul>
      </section>

      <section>
        <h2>Zubereitung</h2>
        <ol class="instructions">
          <li v-for="(step, index) in recipe.instructions" :key="index">{{ step }}</li>
        </ol>
      </section>
    </template>
  </main>
</template>

<style scoped>
.recipe-detail {
  max-width: 720px;
  margin: 0 auto;
  padding: 2rem 1.5rem;
}

.top-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.25rem;
}

.back-link {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  color: var(--color-muted);
  text-decoration: none;
  font-size: 0.9rem;
  font-weight: 500;
}

.back-link:hover {
  color: var(--color-text);
}

.edit-link {
  color: var(--color-text);
  text-decoration: none;
  font-size: 0.9rem;
  font-weight: 500;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 0.5rem 1rem;
}

.edit-link:hover {
  border-color: var(--color-accent);
  color: var(--color-accent);
  box-shadow: var(--shadow-sm);
}

.error {
  color: #e0554f;
}

.hint {
  color: var(--color-muted);
}

.eyebrow {
  margin: 0;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-muted);
}

h1 {
  margin: 0.25rem 0 0;
  font-size: 2rem;
}

.badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0.75rem 0 0;
}

.diet-badge {
  display: inline-block;
  font-size: 0.8125rem;
  font-weight: 500;
  border-radius: var(--radius-pill);
  padding: 0.25rem 0.75rem;
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

.description {
  margin-top: 1rem;
  color: var(--color-muted);
  line-height: 1.6;
}

.meta-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 1rem;
  margin: 1.5rem 0;
  padding: 1.1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
}

.meta-grid dt {
  font-size: 0.75rem;
  color: var(--color-muted);
}

.meta-grid dd {
  margin: 0.15rem 0 0;
  font-weight: 600;
}

section {
  margin-top: 1.75rem;
}

section h2 {
  font-size: 1.15rem;
  margin: 0 0 0.75rem;
}

.ingredient-list,
.instructions {
  margin: 0;
  padding-left: 1.25rem;
  line-height: 1.7;
}

.ingredient-list li,
.instructions li {
  margin-bottom: 0.4rem;
}

.notes {
  color: var(--color-muted);
  font-size: 0.85rem;
}
</style>
