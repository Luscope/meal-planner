import { api } from '@/lib/api'

export type MealType = 'breakfast' | 'lunch' | 'dinner' | 'snack'

export const MEAL_TYPES: MealType[] = ['breakfast', 'lunch', 'dinner', 'snack']

export const MEAL_TYPE_LABELS: Record<MealType, string> = {
  breakfast: 'Frühstück',
  lunch: 'Mittagessen',
  dinner: 'Abendessen',
  snack: 'Snack',
}

export interface FamilyMember {
  id: number
  name: string
  daily_calorie_target: number | null
  daily_protein_target_g: number | null
}

export interface RecipePickerItem {
  id: number
  title: string
  servings: number
  calories_per_serving: number | null
}

export interface MealPlanRecipe {
  id: number
  title: string
  cuisine: string | null
  servings: number
  calories_per_serving: number | null
  protein_per_serving_g: string | null
}

export interface MealPlanFamilyMemberAssignment {
  id: number
  name: string
  portion_multiplier: string
}

export interface MealPlan {
  id: number
  date: string
  meal_type: MealType
  planned_servings: string
  recipe: MealPlanRecipe
  family_members: MealPlanFamilyMemberAssignment[]
}

export interface SummaryDay {
  calories: number
  protein_g: number
}

export interface SummaryFamilyMember {
  id: number
  name: string
  daily_calorie_target: number | null
  daily_protein_target_g: number | null
  days: Record<string, SummaryDay>
}

export interface Summary {
  start_date: string
  end_date: string
  family_members: SummaryFamilyMember[]
}

export interface CreateMealPlanPayload {
  recipe_id: number
  date: string
  meal_type: MealType
  planned_servings?: number
  family_members?: Array<{ family_member_id: number; portion_multiplier?: number }>
}

export interface UpdateMealPlanPayload {
  recipe_id?: number
  planned_servings?: number
  family_members?: Array<{ family_member_id: number; portion_multiplier?: number }>
}

export async function fetchFamilyMembers(): Promise<FamilyMember[]> {
  const response = await api.get('/family-members')
  return response.data
}

export async function fetchRecipesForPicker(): Promise<RecipePickerItem[]> {
  const response = await api.get('/recipes', { params: { per_page: 1000 } })
  return response.data.data
}

export async function fetchMealPlans(startDate: string, endDate: string): Promise<MealPlan[]> {
  const response = await api.get('/meal-plans', {
    params: { start_date: startDate, end_date: endDate },
  })
  return response.data.data
}

export async function fetchSummary(startDate: string, endDate: string): Promise<Summary> {
  const response = await api.get('/meal-plans/summary', {
    params: { start_date: startDate, end_date: endDate },
  })
  return response.data
}

export async function createMealPlan(payload: CreateMealPlanPayload): Promise<MealPlan> {
  const response = await api.post('/meal-plans', payload)
  return response.data.data
}

export async function updateMealPlan(id: number, payload: UpdateMealPlanPayload): Promise<MealPlan> {
  const response = await api.patch(`/meal-plans/${id}`, payload)
  return response.data.data
}

export async function deleteMealPlan(id: number): Promise<void> {
  await api.delete(`/meal-plans/${id}`)
}
