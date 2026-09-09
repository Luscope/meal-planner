<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import AddMealModal from '@/components/AddMealModal.vue'
import AutoPlanModal from '@/components/AutoPlanModal.vue'
import { extractErrorMessage } from '@/lib/api'
import { addDays, formatShortDate, startOfWeek, toIsoDate, weekRangeLabel, weekdayLabel } from '@/lib/date'
import {
  MEAL_TYPES,
  MEAL_TYPE_LABELS,
  deleteMealPlan,
  fetchFamilyMembers,
  fetchMealPlans,
  fetchRecipesForPicker,
  fetchSummary,
  type FamilyMember,
  type MealPlan,
  type MealType,
  type RecipePickerItem,
  type Summary,
} from '@/lib/mealPlanner'

const weekStart = ref(startOfWeek(new Date()))
const mealPlans = ref<MealPlan[]>([])
const familyMembers = ref<FamilyMember[]>([])
const recipes = ref<RecipePickerItem[]>([])
const summary = ref<Summary | null>(null)
const errorMessage = ref('')
const isLoading = ref(false)
const modalState = ref<{ date: Date; mealType: MealType; mealPlan?: MealPlan } | null>(null)
const isAutoPlanOpen = ref(false)

const weekDays = computed(() => Array.from({ length: 7 }, (_, index) => addDays(weekStart.value, index)))

async function loadWeek() {
  errorMessage.value = ''
  isLoading.value = true

  const startIso = toIsoDate(weekStart.value)
  const endIso = toIsoDate(addDays(weekStart.value, 6))

  try {
    const [plans, summaryData] = await Promise.all([
      fetchMealPlans(startIso, endIso),
      fetchSummary(startIso, endIso),
    ])
    mealPlans.value = plans
    summary.value = summaryData
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isLoading.value = false
  }
}

onMounted(async () => {
  try {
    const [members, recipeList] = await Promise.all([fetchFamilyMembers(), fetchRecipesForPicker()])
    familyMembers.value = members
    recipes.value = recipeList
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  }

  await loadWeek()

  await nextTick()
  if (window.matchMedia('(max-width: 640px)').matches) {
    document.getElementById('today-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }
})

watch(weekStart, loadWeek)

function isToday(day: Date): boolean {
  return toIsoDate(day) === toIsoDate(new Date())
}

function previousWeek() {
  weekStart.value = addDays(weekStart.value, -7)
}

function nextWeek() {
  weekStart.value = addDays(weekStart.value, 7)
}

function goToCurrentWeek() {
  weekStart.value = startOfWeek(new Date())
}

function entriesFor(day: Date, mealType: MealType): MealPlan[] {
  const iso = toIsoDate(day)
  return mealPlans.value.filter((plan) => plan.date === iso && plan.meal_type === mealType)
}

function openModal(day: Date, mealType: MealType) {
  modalState.value = { date: day, mealType }
}

function openEditModal(day: Date, mealType: MealType, entry: MealPlan) {
  modalState.value = { date: day, mealType, mealPlan: entry }
}

function closeModal() {
  modalState.value = null
}

async function handleCreated() {
  closeModal()
  await loadWeek()
}

async function handleAutoPlanApplied() {
  isAutoPlanOpen.value = false
  await loadWeek()
}

async function handleDelete(id: number) {
  try {
    await deleteMealPlan(id)
    await loadWeek()
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  }
}

function isOverTarget(calories: number, target: number | null): boolean {
  return target !== null && calories > target
}

function dayEntry(member: Summary['family_members'][number], day: Date) {
  return member.days[toIsoDate(day)]
}
</script>

<template>
  <main class="wochenplan">
    <div class="week-nav">
      <button type="button" @click="previousWeek">← Vorherige Woche</button>
      <div class="week-label">
        <strong>{{ weekRangeLabel(weekStart) }}</strong>
        <button type="button" class="link" @click="goToCurrentWeek">Heute</button>
      </div>
      <button type="button" @click="nextWeek">Nächste Woche →</button>
      <button type="button" class="auto-plan-button" @click="isAutoPlanOpen = true">
        🤖 Woche planen
      </button>
    </div>

    <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
    <p v-if="isLoading" class="hint">Lädt…</p>

    <div class="grid-wrapper week-grid-wrapper">
      <table class="grid">
        <thead>
          <tr>
            <th class="corner"></th>
            <th v-for="day in weekDays" :key="toIsoDate(day)">
              {{ weekdayLabel(day) }}<br />
              <span class="date">{{ formatShortDate(day) }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="mealType in MEAL_TYPES" :key="mealType">
            <th class="meal-type-label">{{ MEAL_TYPE_LABELS[mealType] }}</th>
            <td v-for="day in weekDays" :key="toIsoDate(day) + mealType">
              <div
                v-for="entry in entriesFor(day, mealType)"
                :key="entry.id"
                class="meal-chip"
                role="button"
                tabindex="0"
                @click="openEditModal(day, mealType, entry)"
                @keydown.enter="openEditModal(day, mealType, entry)"
              >
                <button class="remove" type="button" title="Entfernen" @click.stop="handleDelete(entry.id)">
                  ×
                </button>
                <div class="chip-title">{{ entry.recipe.title }}</div>
                <div v-if="entry.recipe.calories_per_serving" class="chip-meta">
                  {{ entry.recipe.calories_per_serving }} kcal/Portion
                </div>
                <div v-if="entry.family_members.length" class="chip-members">
                  {{ entry.family_members.map((member) => member.name).join(', ') }}
                </div>
              </div>
              <button class="add-button" type="button" @click="openModal(day, mealType)">
                + Mahlzeit
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="day-list">
      <section
        v-for="day in weekDays"
        :key="`mobile-${toIsoDate(day)}`"
        :id="isToday(day) ? 'today-card' : undefined"
        class="day-card"
        :class="{ 'is-today': isToday(day) }"
      >
        <h2 class="day-card-title">
          {{ weekdayLabel(day) }} <span class="date">{{ formatShortDate(day) }}</span>
          <span v-if="isToday(day)" class="today-badge">Heute</span>
        </h2>

        <div v-for="mealType in MEAL_TYPES" :key="mealType" class="meal-section">
          <h3 class="meal-section-title">{{ MEAL_TYPE_LABELS[mealType] }}</h3>

          <div
            v-for="entry in entriesFor(day, mealType)"
            :key="entry.id"
            class="meal-chip"
            role="button"
            tabindex="0"
            @click="openEditModal(day, mealType, entry)"
            @keydown.enter="openEditModal(day, mealType, entry)"
          >
            <button class="remove" type="button" title="Entfernen" @click.stop="handleDelete(entry.id)">
              ×
            </button>
            <div class="chip-title">{{ entry.recipe.title }}</div>
            <div v-if="entry.recipe.calories_per_serving" class="chip-meta">
              {{ entry.recipe.calories_per_serving }} kcal/Portion
            </div>
            <div v-if="entry.family_members.length" class="chip-members">
              {{ entry.family_members.map((member) => member.name).join(', ') }}
            </div>
          </div>

          <button class="add-button" type="button" @click="openModal(day, mealType)">+ Mahlzeit</button>
        </div>
      </section>
    </div>

    <section v-if="summary && summary.family_members.length" class="summary">
      <h2>Kalorien &amp; Protein pro Tag</h2>
      <div class="grid-wrapper">
        <table class="summary-table">
          <thead>
            <tr>
              <th>Familienmitglied</th>
              <th v-for="day in weekDays" :key="`summary-${toIsoDate(day)}`">
                {{ weekdayLabel(day).slice(0, 2) }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in summary.family_members" :key="member.id">
              <td>
                {{ member.name }}
                <div v-if="member.daily_calorie_target" class="target">
                  Ziel: {{ member.daily_calorie_target }} kcal
                </div>
              </td>
              <td
                v-for="day in weekDays"
                :key="`cell-${member.id}-${toIsoDate(day)}`"
                :class="{
                  over: isOverTarget(dayEntry(member, day)?.calories ?? 0, member.daily_calorie_target),
                }"
              >
                <template v-if="dayEntry(member, day)">
                  {{ dayEntry(member, day)!.calories }} kcal<br />
                  <small>{{ dayEntry(member, day)!.protein_g }}g Protein</small>
                </template>
                <span v-else class="muted">–</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <AddMealModal
      v-if="modalState"
      :date="modalState.date"
      :meal-type="modalState.mealType"
      :recipes="recipes"
      :family-members="familyMembers"
      :meal-plan="modalState.mealPlan"
      @close="closeModal"
      @created="handleCreated"
      @updated="handleCreated"
    />

    <AutoPlanModal
      v-if="isAutoPlanOpen"
      :start-date="toIsoDate(weekStart)"
      :end-date="toIsoDate(addDays(weekStart, 6))"
      @close="isAutoPlanOpen = false"
      @applied="handleAutoPlanApplied"
    />
  </main>
</template>

<style scoped>
.wochenplan {
  max-width: 1100px;
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

.auto-plan-button {
  background: var(--color-button-bg);
  color: var(--color-button-text);
  border-color: var(--color-button-bg);
}

.auto-plan-button:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow-md);
}

.error {
  color: #e0554f;
}

.hint {
  opacity: 0.7;
}

.grid-wrapper {
  overflow-x: auto;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
}

.grid,
.summary-table {
  width: 100%;
  border-collapse: collapse;
  border-radius: var(--radius-md);
  overflow: hidden;
}

.grid th,
.grid td,
.summary-table th,
.summary-table td {
  border: 1px solid var(--color-border);
  padding: 0.6rem;
  vertical-align: top;
  text-align: left;
  font-size: 0.85rem;
}

.grid th {
  background: var(--color-background-soft);
  text-align: center;
  font-weight: 600;
}

.grid .date {
  font-weight: 400;
  opacity: 0.7;
}

.meal-type-label {
  white-space: nowrap;
}

.grid td {
  min-width: 130px;
}

.meal-chip {
  position: relative;
  background: var(--color-background-soft);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 0.4rem 1.2rem 0.4rem 0.5rem;
  margin-bottom: 0.35rem;
  cursor: pointer;
  box-shadow: var(--shadow-sm);
}

.meal-chip:hover,
.meal-chip:focus-visible {
  border-color: var(--color-accent);
  outline: none;
  transform: translateY(-1px);
  box-shadow: var(--shadow-md);
}

.chip-title {
  font-weight: 600;
}

.chip-meta,
.chip-members {
  font-size: 0.75rem;
  opacity: 0.75;
}

.remove {
  position: absolute;
  top: 0.15rem;
  right: 0.25rem;
  border: none;
  background: none;
  padding: 0;
  font-size: 1rem;
  line-height: 1;
  color: #e0554f;
  opacity: 0.6;
}

.remove:hover {
  opacity: 1;
  box-shadow: none;
  transform: scale(1.15);
}

.add-button {
  width: 100%;
  font-size: 0.75rem;
  padding: 0.3rem;
}

.summary {
  margin-top: 2rem;
}

.target {
  font-size: 0.75rem;
  opacity: 0.7;
  font-weight: 400;
}

.summary-table td.over {
  background: rgba(224, 85, 79, 0.12);
}

.muted {
  opacity: 0.4;
}

.day-list {
  display: none;
}

@media (max-width: 640px) {
  .wochenplan {
    padding: 1rem;
  }

  .week-nav {
    justify-content: center;
  }

  .week-grid-wrapper {
    display: none;
  }

  .day-list {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
  }

  .day-card {
    scroll-margin-top: 76px;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    padding: 1rem;
  }

  .day-card.is-today {
    border-color: var(--color-accent);
    box-shadow: 0 0 0 1px var(--color-accent);
  }

  .day-card-title {
    margin: 0 0 0.85rem;
    font-size: 1.15rem;
  }

  .day-card-title .date {
    font-weight: 400;
    opacity: 0.7;
  }

  .today-badge {
    margin-left: 0.5rem;
    padding: 0.15rem 0.55rem;
    border-radius: 999px;
    background: var(--color-accent);
    color: var(--color-button-text);
    font-size: 0.7rem;
    font-weight: 600;
    vertical-align: middle;
  }

  .meal-section {
    margin-bottom: 1rem;
  }

  .meal-section:last-child {
    margin-bottom: 0;
  }

  .meal-section-title {
    margin: 0 0 0.5rem;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    opacity: 0.65;
  }

  .day-list .meal-chip {
    padding: 0.65rem 2.25rem 0.65rem 0.75rem;
    margin-bottom: 0.5rem;
  }

  .day-list .chip-title {
    font-size: 1rem;
  }

  .day-list .chip-meta,
  .day-list .chip-members {
    font-size: 0.85rem;
    margin-top: 0.2rem;
  }

  .day-list .remove {
    font-size: 1.3rem;
    top: 0.5rem;
    right: 0.6rem;
  }

  .day-list .add-button {
    font-size: 0.9rem;
    padding: 0.6rem;
    min-height: 44px;
  }

  .summary-table th,
  .summary-table td {
    padding: 0.5rem 0.4rem;
    font-size: 0.8rem;
  }
}
</style>
