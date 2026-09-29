import { api } from '@/lib/api'
import type { MealType } from '@/lib/mealPlanner'

export interface AutoPlanFamilyMember {
  family_member_id: number
  portion_multiplier: number
  name: string
}

export interface AutoPlanAssignment {
  date: string
  meal_type: MealType
  recipe_id: number
  recipe_title: string
  family_members: AutoPlanFamilyMember[]
}

export interface PreviewAutoPlanPayload {
  start_date: string
  end_date: string
  meal_types: MealType[]
  criteria?: string
}

export async function previewAutoPlan(payload: PreviewAutoPlanPayload): Promise<AutoPlanAssignment[]> {
  const { start_date, end_date, ...body } = payload
  const response = await api.post(
    '/meal-plans/auto-plan',
    body,
    { params: { start_date, end_date } },
  )
  return response.data.assignments
}

export async function applyAutoPlan(assignments: AutoPlanAssignment[]): Promise<number> {
  const response = await api.post('/meal-plans/auto-plan/apply', { assignments })
  return response.data.created
}
