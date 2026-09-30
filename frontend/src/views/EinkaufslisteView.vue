<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { extractErrorMessage } from '@/lib/api'
import { addDays, startOfWeek, toIsoDate, weekRangeLabel } from '@/lib/date'
import { fetchShoppingList, type ShoppingList } from '@/lib/shoppingList'
import AppIcon from '@/components/AppIcon.vue'

const weekStart = ref(startOfWeek(new Date()))
const shoppingList = ref<ShoppingList | null>(null)
const errorMessage = ref('')
const isLoading = ref(false)
const checkedItems = ref<Set<number>>(new Set())

async function loadList() {
  errorMessage.value = ''
  isLoading.value = true

  try {
    const startIso = toIsoDate(weekStart.value)
    const endIso = toIsoDate(addDays(weekStart.value, 6))
    shoppingList.value = await fetchShoppingList(startIso, endIso)
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isLoading.value = false
  }
}

onMounted(loadList)
watch(weekStart, loadList)

function previousWeek() {
  weekStart.value = addDays(weekStart.value, -7)
}

function nextWeek() {
  weekStart.value = addDays(weekStart.value, 7)
}

function goToCurrentWeek() {
  weekStart.value = startOfWeek(new Date())
}

function toggleChecked(ingredientId: number) {
  if (checkedItems.value.has(ingredientId)) {
    checkedItems.value.delete(ingredientId)
  } else {
    checkedItems.value.add(ingredientId)
  }
}

const doneCount = computed(() => checkedItems.value.size)

const progressPercent = computed(() => {
  const total = shoppingList.value?.items.length ?? 0
  return total === 0 ? 0 : Math.round((doneCount.value / total) * 100)
})
</script>

<template>
  <main class="einkaufsliste">
    <header class="page-header">
      <div>
        <div class="eyebrow">Aus dem Wochenplan</div>
        <h1>Einkaufsliste</h1>
      </div>
      <div class="week-nav">
        <button type="button" class="icon-button" aria-label="Vorherige Woche" @click="previousWeek">
          <AppIcon name="chevron-left" />
        </button>
        <span class="week-label">{{ weekRangeLabel(weekStart) }}</span>
        <button type="button" class="icon-button" aria-label="Nächste Woche" @click="nextWeek">
          <AppIcon name="chevron-right" />
        </button>
        <button type="button" class="today-button" @click="goToCurrentWeek">Heute</button>
      </div>
    </header>

    <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
    <p v-if="isLoading" class="hint">Lädt…</p>

    <template v-else-if="shoppingList">
      <p v-if="shoppingList.items.length === 0" class="hint">
        Keine Zutaten für diesen Zeitraum — leg im Wochenplan Mahlzeiten an, um hier eine Einkaufsliste
        zu sehen.
      </p>

      <template v-else>
        <div class="progress-block">
          <span class="progress-label">{{ doneCount }} von {{ shoppingList.items.length }} erledigt</span>
          <div class="progress-bar" role="progressbar" :aria-valuenow="progressPercent">
            <div class="progress-bar-fill" :style="{ width: progressPercent + '%' }"></div>
          </div>
        </div>

        <section class="item-card">
          <ul class="item-list">
            <li
              v-for="item in shoppingList.items"
              :key="item.ingredient_id"
              :class="{ checked: checkedItems.has(item.ingredient_id) }"
            >
              <label>
                <input
                  type="checkbox"
                  :checked="checkedItems.has(item.ingredient_id)"
                  @change="toggleChecked(item.ingredient_id)"
                />
                <span class="item-text-block">
                  <span class="item-text"><strong>{{ item.quantity }} {{ item.unit }}</strong> {{ item.name }}</span>
                  <span class="recipes">für {{ item.recipes.join(', ') }}</span>
                </span>
              </label>
            </li>
          </ul>
        </section>
      </template>
    </template>
  </main>
</template>

<style scoped>
.einkaufsliste {
  max-width: 720px;
  margin: 0 auto;
  padding: 2rem 1.5rem;
}

.page-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.eyebrow {
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-muted);
}

.page-header h1 {
  margin: 0.25rem 0 0;
  font-size: 2rem;
}

.week-nav {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.week-label {
  min-width: 8.5rem;
  text-align: center;
  font-size: 0.875rem;
  font-weight: 600;
}

.icon-button,
.today-button {
  height: 2.5rem;
  border-radius: var(--radius-sm);
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text);
  cursor: pointer;
  font-size: 0.875rem;
  font-weight: 500;
}

.icon-button {
  width: 2.5rem;
  padding: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.today-button {
  padding: 0 0.9rem;
}

.icon-button:hover,
.today-button:hover {
  box-shadow: var(--shadow-sm);
}

.error {
  color: #e0554f;
}

.hint {
  color: var(--color-muted);
}

.progress-block {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.progress-label {
  font-size: 0.9rem;
  font-weight: 600;
}

.progress-bar {
  height: 8px;
  border-radius: 4px;
  background: var(--color-surface-2);
  overflow: hidden;
}

.progress-bar-fill {
  height: 100%;
  border-radius: 4px;
  background: var(--color-accent);
  transition: width var(--transition-fast);
}

.item-card {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface);
  overflow: hidden;
}

.item-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.item-list li {
  border-top: 1px solid var(--color-border);
}

.item-list li:first-child {
  border-top: none;
}

.item-list li.checked .item-text {
  text-decoration: line-through;
}

.item-list li.checked label {
  opacity: 0.5;
}

label {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.7rem 1rem;
  min-height: 2.75rem;
  box-sizing: border-box;
  cursor: pointer;
  transition: opacity var(--transition-fast);
}

input[type='checkbox'] {
  width: 1.25rem;
  height: 1.25rem;
  flex-shrink: 0;
  accent-color: var(--color-accent);
}

.item-text-block {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.item-text {
  font-size: 0.9375rem;
}

.recipes {
  font-size: 0.75rem;
  color: var(--color-muted);
}
</style>
