<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import AddMealModal from '@/components/AddMealModal.vue'
import AutoPlanModal from '@/components/AutoPlanModal.vue'
import AppIcon from '@/components/AppIcon.vue'
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
  updateMealPlan,
  type FamilyMember,
  type MealPlan,
  type MealRating,
  type MealType,
  type RecipePickerItem,
  type Summary,
} from '@/lib/mealPlanner'

type ViewMode = 'grid' | 'list'

const VIEW_MODE_STORAGE_KEY = 'wochenplan-view-mode'

function loadInitialViewMode(): ViewMode {
  try {
    const stored = localStorage.getItem(VIEW_MODE_STORAGE_KEY)
    if (stored === 'grid' || stored === 'list') return stored
  } catch {
    // localStorage unavailable (private mode etc.) — fall back to the default below
  }
  return window.matchMedia('(max-width: 640px)').matches ? 'list' : 'grid'
}

const viewMode = ref<ViewMode>(loadInitialViewMode())

function setViewMode(mode: ViewMode) {
  viewMode.value = mode
  try {
    localStorage.setItem(VIEW_MODE_STORAGE_KEY, mode)
  } catch {
    // ignore — the choice just won't persist across visits
  }
}

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
  if (viewMode.value === 'list') {
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

async function handleRate(entry: MealPlan, rating: MealRating) {
  const nextRating = entry.rating === rating ? null : rating

  try {
    const updated = await updateMealPlan(entry.id, { rating: nextRating })
    entry.rating = updated.rating
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
    <header class="page-header">
      <div>
        <div class="week-range">{{ weekRangeLabel(weekStart) }}</div>
        <h1>Diese Woche</h1>
      </div>

      <div class="header-actions">
        <div class="mode-switch">
          <button type="button" :class="{ active: viewMode === 'grid' }" @click="setViewMode('grid')">
            <AppIcon name="calendar" :size="17" />
            Kalender
          </button>
          <button type="button" :class="{ active: viewMode === 'list' }" @click="setViewMode('list')">
            <AppIcon name="list" :size="17" />
            Liste
          </button>
        </div>

        <div class="week-stepper">
          <button type="button" class="icon-button" aria-label="Vorherige Woche" @click="previousWeek">
            <AppIcon name="chevron-left" />
          </button>
          <button type="button" class="today-button" @click="goToCurrentWeek">Heute</button>
          <button type="button" class="icon-button" aria-label="Nächste Woche" @click="nextWeek">
            <AppIcon name="chevron-right" />
          </button>
        </div>

        <button type="button" class="auto-plan-button" @click="isAutoPlanOpen = true">
          <AppIcon name="auto-plan" :size="18" />
          Woche planen
        </button>
      </div>
    </header>

    <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
    <p v-if="isLoading" class="hint">Lädt…</p>

    <div v-show="viewMode === 'grid'" class="grid-wrapper week-grid-wrapper">
      <table class="grid">
        <thead>
          <tr>
            <th class="corner"></th>
            <th v-for="day in weekDays" :key="toIsoDate(day)" :class="{ today: isToday(day) }">
              {{ weekdayLabel(day) }}
              <span v-if="isToday(day)" class="today-badge">Heute</span>
              <br />
              <span class="date">{{ formatShortDate(day) }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="mealType in MEAL_TYPES" :key="mealType">
            <th class="meal-type-label">{{ MEAL_TYPE_LABELS[mealType] }}</th>
            <td v-for="day in weekDays" :key="toIsoDate(day) + mealType" :class="{ today: isToday(day) }">
              <article
                v-for="entry in entriesFor(day, mealType)"
                :key="entry.id"
                class="meal-chip"
                role="button"
                tabindex="0"
                @click="openEditModal(day, mealType, entry)"
                @keydown.enter="openEditModal(day, mealType, entry)"
              >
                <div class="chip-top">
                  <div class="chip-title">{{ entry.recipe.title }}</div>
                  <button class="remove" type="button" title="Entfernen" @click.stop="handleDelete(entry.id)">
                    <AppIcon name="close" :size="14" />
                  </button>
                </div>
                <div v-if="entry.recipe.calories_per_serving" class="chip-meta">
                  {{ entry.recipe.calories_per_serving }} kcal/Portion
                </div>
                <div v-if="entry.family_members.length" class="chip-members">
                  {{ entry.family_members.map((member) => member.name).join(', ') }}
                </div>
                <div class="chip-rating">
                  <button
                    type="button"
                    class="rate-button"
                    :class="{ active: entry.rating === 'liked' }"
                    title="Hat geschmeckt"
                    @click.stop="handleRate(entry, 'liked')"
                  >
                    <AppIcon name="thumbs-up" :size="15" />
                  </button>
                  <button
                    type="button"
                    class="rate-button"
                    :class="{ active: entry.rating === 'disliked' }"
                    title="Hat nicht geschmeckt"
                    @click.stop="handleRate(entry, 'disliked')"
                  >
                    <AppIcon name="thumbs-down" :size="15" />
                  </button>
                </div>
              </article>
              <button class="add-button" type="button" @click="openModal(day, mealType)">
                <AppIcon name="plus" :size="14" />
                Mahlzeit
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-show="viewMode === 'list'" class="day-list">
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

          <article
            v-for="entry in entriesFor(day, mealType)"
            :key="entry.id"
            class="meal-chip"
            role="button"
            tabindex="0"
            @click="openEditModal(day, mealType, entry)"
            @keydown.enter="openEditModal(day, mealType, entry)"
          >
            <div class="chip-top">
              <div class="chip-title">{{ entry.recipe.title }}</div>
              <button class="remove" type="button" title="Entfernen" @click.stop="handleDelete(entry.id)">
                <AppIcon name="close" :size="16" />
              </button>
            </div>
            <div v-if="entry.recipe.calories_per_serving" class="chip-meta">
              {{ entry.recipe.calories_per_serving }} kcal/Portion
            </div>
            <div v-if="entry.family_members.length" class="chip-members">
              {{ entry.family_members.map((member) => member.name).join(', ') }}
            </div>
            <div class="chip-rating">
              <button
                type="button"
                class="rate-button"
                :class="{ active: entry.rating === 'liked' }"
                title="Hat geschmeckt"
                @click.stop="handleRate(entry, 'liked')"
              >
                <AppIcon name="thumbs-up" :size="16" />
              </button>
              <button
                type="button"
                class="rate-button"
                :class="{ active: entry.rating === 'disliked' }"
                title="Hat nicht geschmeckt"
                @click.stop="handleRate(entry, 'disliked')"
              >
                <AppIcon name="thumbs-down" :size="16" />
              </button>
            </div>
          </article>

          <button class="add-button" type="button" @click="openModal(day, mealType)">
            <AppIcon name="plus" :size="14" />
            Mahlzeit
          </button>
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
              <th v-for="day in weekDays" :key="`summary-${toIsoDate(day)}`" :class="{ today: isToday(day) }">
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
                  today: isToday(day),
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
  max-width: 1200px;
  margin: 0 auto;
  padding: 2rem 2.5rem;
}

.page-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1.25rem;
  margin-bottom: 1.5rem;
}

.week-range {
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-muted);
}

.page-header h1 {
  margin: 0.25rem 0 0;
  font-size: 2.1rem;
}

.header-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.mode-switch {
  display: inline-grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.25rem;
  padding: 0.25rem;
  border-radius: var(--radius-md);
  background: var(--color-surface-2);
}

.mode-switch button {
  height: 2.25rem;
  padding: 0 1rem;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: var(--color-muted);
  font-size: 0.875rem;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.mode-switch button.active {
  background: var(--color-surface);
  color: var(--color-text);
  font-weight: 600;
  box-shadow: var(--shadow-sm);
}

.week-stepper {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.icon-button {
  width: 2.5rem;
  height: 2.5rem;
  padding: 0;
  border-radius: var(--radius-sm);
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text);
  display: flex;
  align-items: center;
  justify-content: center;
}

.today-button {
  height: 2.5rem;
  padding: 0 0.9rem;
  border-radius: var(--radius-sm);
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text);
  font-size: 0.875rem;
  font-weight: 500;
}

.icon-button:hover,
.today-button:hover {
  box-shadow: var(--shadow-sm);
}

.auto-plan-button {
  height: 2.75rem;
  padding: 0 1.1rem;
  border-radius: var(--radius-md);
  border: none;
  background: var(--color-accent);
  color: var(--color-on-accent);
  font-size: 0.9375rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.auto-plan-button:hover {
  background: var(--color-accent-hover);
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
  border-radius: var(--radius-xl);
  border: 1px solid var(--color-border);
}

.grid,
.summary-table {
  width: 100%;
  border-collapse: collapse;
}

.grid th,
.grid td,
.summary-table th,
.summary-table td {
  border-left: 1px solid var(--color-border);
  border-bottom: 1px solid var(--color-border);
  padding: 0.6rem;
  vertical-align: top;
  text-align: left;
  font-size: 0.85rem;
}

.grid thead th,
.summary-table thead th {
  border-bottom: 1px solid var(--color-border);
}

.grid tbody tr:last-child th,
.grid tbody tr:last-child td,
.summary-table tbody tr:last-child td {
  border-bottom: none;
}

.grid th:first-child,
.grid td:first-child,
.summary-table th:first-child,
.summary-table td:first-child {
  border-left: none;
}

.grid th {
  background: var(--color-surface);
  text-align: left;
  font-weight: 600;
  font-size: 0.9375rem;
}

.grid th.today,
.grid td.today,
.summary-table th.today,
.summary-table td.today {
  background: color-mix(in srgb, var(--color-accent) 7%, var(--color-surface));
}

.grid .date {
  font-weight: 400;
  color: var(--color-muted);
}

.meal-type-label {
  white-space: nowrap;
  color: var(--color-muted);
  font-weight: 600;
}

.grid td {
  min-width: 150px;
}

.meal-chip {
  position: relative;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 0.6rem 0.55rem;
  margin-bottom: 0.5rem;
  cursor: pointer;
  box-shadow: var(--shadow-sm);
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.meal-chip:hover,
.meal-chip:focus-visible {
  border-color: var(--color-accent);
  outline: none;
  transform: translateY(-1px);
  box-shadow: var(--shadow-md);
}

.chip-top {
  display: flex;
  align-items: flex-start;
  gap: 0.25rem;
}

.chip-title {
  flex-grow: 1;
  min-width: 0;
  font-weight: 600;
  line-height: 1.3;
}

.chip-meta,
.chip-members {
  font-size: 0.75rem;
  color: var(--color-muted);
}

.remove {
  flex-shrink: 0;
  margin: -0.15rem -0.1rem 0 0;
  width: 1.5rem;
  height: 1.5rem;
  border: none;
  background: none;
  border-radius: 8px;
  color: var(--color-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}

.remove:hover {
  color: #e0554f;
  background: var(--color-surface-2);
  box-shadow: none;
}

.chip-rating {
  display: flex;
  gap: 0.15rem;
}

.rate-button {
  width: 1.75rem;
  height: 1.75rem;
  border: none;
  background: none;
  padding: 0;
  border-radius: 8px;
  color: var(--color-muted);
  display: flex;
  align-items: center;
  justify-content: center;
}

.rate-button:hover {
  color: var(--color-text);
  background: var(--color-surface-2);
  box-shadow: none;
}

.rate-button.active {
  color: var(--color-tint-text);
  background: var(--color-tint);
}

.add-button {
  width: 100%;
  font-size: 0.8rem;
  padding: 0.4rem;
  border-radius: var(--radius-sm);
  border: 1.5px dashed var(--color-border);
  background: transparent;
  color: var(--color-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.3rem;
}

.add-button:hover {
  border-style: solid;
  border-color: var(--color-accent);
  color: var(--color-accent);
  box-shadow: none;
}

.summary {
  margin-top: 2.5rem;
}

.summary h2 {
  margin: 0 0 0.75rem;
  font-size: 1.1rem;
}

.target {
  font-size: 0.75rem;
  color: var(--color-muted);
  font-weight: 400;
}

.summary-table td.over {
  background: rgba(224, 85, 79, 0.12);
}

.muted {
  color: var(--color-muted);
}

.today-badge {
  margin-left: 0.5rem;
  padding: 0.15rem 0.55rem;
  border-radius: var(--radius-pill);
  background: var(--color-accent);
  color: var(--color-on-accent);
  font-size: 0.7rem;
  font-weight: 600;
  vertical-align: middle;
}

.day-list {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.day-card {
  scroll-margin-top: 76px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-sm);
  padding: 1.1rem;
}

.day-card.is-today {
  border-color: var(--color-accent);
  box-shadow: 0 0 0 1px var(--color-accent);
}

.day-card-title {
  margin: 0 0 0.85rem;
  font-size: 1.2rem;
}

.day-card-title .date {
  font-weight: 400;
  color: var(--color-muted);
}

.meal-section {
  margin-bottom: 1rem;
}

.meal-section:last-child {
  margin-bottom: 0;
}

.meal-section-title {
  margin: 0 0 0.5rem;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--color-muted);
}

.day-list .meal-chip {
  padding: 0.75rem 0.75rem;
  margin-bottom: 0.5rem;
}

.day-list .chip-title {
  font-size: 1rem;
}

.day-list .chip-meta,
.day-list .chip-members {
  font-size: 0.85rem;
}

.day-list .add-button {
  font-size: 0.9rem;
  padding: 0.6rem;
  min-height: 44px;
}

@media (max-width: 640px) {
  .wochenplan {
    padding: 1.25rem;
  }

  .page-header {
    justify-content: center;
    text-align: center;
  }

  .header-actions {
    justify-content: center;
  }

  .summary-table th,
  .summary-table td {
    padding: 0.5rem 0.4rem;
    font-size: 0.8rem;
  }
}
</style>
