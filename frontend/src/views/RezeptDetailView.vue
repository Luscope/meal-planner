<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { extractErrorMessage } from '@/lib/api'
import { fetchRecipe, RECIPE_CATEGORY_LABELS, type Recipe } from '@/lib/recipes'

const route = useRoute()
const recipe = ref<Recipe | null>(null)
const errorMessage = ref('')
const isLoading = ref(false)

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
      <RouterLink :to="{ name: 'recipes' }" class="back-link">← Zurück zu den Rezepten</RouterLink>
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
      <h1>{{ recipe.title }}</h1>
      <div class="badges">
        <p v-if="recipe.cuisine" class="cuisine-badge">{{ recipe.cuisine }}</p>
        <p v-if="recipe.category" class="category-badge">{{ RECIPE_CATEGORY_LABELS[recipe.category] }}</p>
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
  max-width: 700px;
  margin: 0 auto;
  padding: 1.5rem;
}

.top-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
}

.back-link {
  color: var(--color-link);
  text-decoration: none;
  font-size: 0.9rem;
}

.back-link:hover {
  text-decoration: underline;
}

.edit-link {
  color: var(--color-text);
  text-decoration: none;
  font-size: 0.9rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 0.4rem 0.8rem;
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
  opacity: 0.7;
}

.badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0 0 1rem;
}

.cuisine-badge {
  display: inline-block;
  font-size: 0.8rem;
  background: var(--color-background-soft);
  border-radius: 999px;
  padding: 0.2rem 0.7rem;
  margin: 0;
}

.category-badge {
  display: inline-block;
  font-size: 0.8rem;
  background: var(--color-accent-soft, var(--color-background-soft));
  color: var(--color-accent, inherit);
  border: 1px solid var(--color-border);
  border-radius: 999px;
  padding: 0.2rem 0.7rem;
  margin: 0;
}

.description {
  opacity: 0.85;
}

.meta-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 1rem;
  margin: 1.5rem 0;
  padding: 1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
}

.meta-grid dt {
  font-size: 0.75rem;
  opacity: 0.7;
}

.meta-grid dd {
  margin: 0;
  font-weight: 600;
}

.ingredient-list,
.instructions {
  padding-left: 1.25rem;
}

.ingredient-list li,
.instructions li {
  margin-bottom: 0.4rem;
}

.notes {
  opacity: 0.7;
  font-size: 0.85rem;
}
</style>
