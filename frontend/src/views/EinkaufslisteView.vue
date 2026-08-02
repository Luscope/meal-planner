<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { extractErrorMessage } from '@/lib/api'
import { addDays, startOfWeek, toIsoDate, weekRangeLabel } from '@/lib/date'
import { fetchShoppingList, type ShoppingList } from '@/lib/shoppingList'

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

const remainingCount = computed(() => {
  const total = shoppingList.value?.items.length ?? 0
  return total - checkedItems.value.size
})
</script>

<template>
  <main class="einkaufsliste">
    <div class="week-nav">
      <button type="button" @click="previousWeek">← Vorherige Woche</button>
      <div class="week-label">
        <strong>{{ weekRangeLabel(weekStart) }}</strong>
        <button type="button" class="link" @click="goToCurrentWeek">Heute</button>
      </div>
      <button type="button" @click="nextWeek">Nächste Woche →</button>
    </div>

    <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
    <p v-if="isLoading" class="hint">Lädt…</p>

    <template v-else-if="shoppingList">
      <p v-if="shoppingList.items.length === 0" class="hint">
        Keine Zutaten für diesen Zeitraum — leg im Wochenplan Mahlzeiten an, um hier eine Einkaufsliste
        zu sehen.
      </p>

      <template v-else>
        <p class="progress">{{ remainingCount }} von {{ shoppingList.items.length }} noch zu besorgen</p>

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
              <span class="item-text">
                <strong>{{ item.quantity }} {{ item.unit }}</strong> {{ item.name }}
              </span>
            </label>
            <span class="recipes">für {{ item.recipes.join(', ') }}</span>
          </li>
        </ul>
      </template>
    </template>
  </main>
</template>

<style scoped>
.einkaufsliste {
  max-width: 640px;
  margin: 0 auto;
  padding: 1.5rem;
}

.week-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.5rem;
}

.week-label {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
}

button {
  padding: 0.5rem 0.9rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-background-soft);
  color: var(--color-text);
  cursor: pointer;
  font-size: 0.9rem;
}

button:hover {
  box-shadow: var(--shadow-sm);
}

button.link {
  border: none;
  background: none;
  color: var(--color-link);
  padding: 0;
  font-size: 0.8rem;
}

button.link:hover {
  text-decoration: underline;
  box-shadow: none;
}

.error {
  color: #e0554f;
}

.hint {
  opacity: 0.7;
}

.progress {
  font-size: 0.9rem;
  opacity: 0.7;
  margin-bottom: 1rem;
}

.item-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.item-list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.6rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-sm);
  transition: opacity var(--transition-fast);
}

.item-list li.checked {
  opacity: 0.5;
  box-shadow: none;
}

.item-list li.checked .item-text {
  text-decoration: line-through;
}

label {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  cursor: pointer;
}

input[type='checkbox'] {
  width: 1.1rem;
  height: 1.1rem;
}

.recipes {
  font-size: 0.75rem;
  opacity: 0.6;
  white-space: nowrap;
}
</style>
