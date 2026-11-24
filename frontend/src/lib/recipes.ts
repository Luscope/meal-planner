import { api } from '@/lib/api'

export type RecipeCategory =
  | 'appetizer'
  | 'main_course'
  | 'side_dish'
  | 'dessert'
  | 'snack'
  | 'drink'

export const RECIPE_CATEGORIES: RecipeCategory[] = [
  'appetizer',
  'main_course',
  'side_dish',
  'dessert',
  'snack',
  'drink',
]

export const RECIPE_CATEGORY_LABELS: Record<RecipeCategory, string> = {
  appetizer: 'Vorspeise',
  main_course: 'Hauptspeise',
  side_dish: 'Beilage',
  dessert: 'Dessert',
  snack: 'Snack',
  drink: 'Getränk',
}

/**
 * Sentinel filter value meaning "this field has no value assigned".
 * Must match the backend's RecipeController::UNASSIGNED constant exactly.
 */
export const UNASSIGNED_FILTER = '__unassigned__'

export type DietType = 'omnivore' | 'pescetarian' | 'vegetarian' | 'vegan'

export const DIET_TYPES: DietType[] = ['omnivore', 'pescetarian', 'vegetarian', 'vegan']

export const DIET_TYPE_LABELS: Record<DietType, string> = {
  omnivore: 'Omnivor',
  pescetarian: 'Pescetarisch',
  vegetarian: 'Vegetarisch',
  vegan: 'Vegan',
}

export type Season = 'spring' | 'summer' | 'autumn' | 'winter'

export const SEASONS: Season[] = ['spring', 'summer', 'autumn', 'winter']

export const SEASON_LABELS: Record<Season, string> = {
  spring: 'Frühling',
  summer: 'Sommer',
  autumn: 'Herbst',
  winter: 'Winter',
}

/** All four seasons selected means "year-round". */
export function isYearRound(seasons: Season[] | null): boolean {
  return seasons !== null && SEASONS.every((season) => seasons.includes(season))
}

/** Human-readable label, e.g. "Herbst, Winter" or "Ganzjährig"; null if unclassified. */
export function seasonsLabel(seasons: Season[] | null): string | null {
  if (!seasons || seasons.length === 0) return null
  if (isYearRound(seasons)) return 'Ganzjährig'
  return SEASONS.filter((season) => seasons.includes(season))
    .map((season) => SEASON_LABELS[season])
    .join(', ')
}

export interface RecipeIngredient {
  id: number
  name: string
  quantity: string
  unit: string
  notes: string | null
}

export interface Recipe {
  id: number
  title: string
  cuisine: string | null
  category: RecipeCategory | null
  diet_type: DietType | null
  seasons: Season[] | null
  description: string | null
  servings: number
  prep_time_minutes: number | null
  cook_time_minutes: number | null
  calories_per_serving: number | null
  protein_per_serving_g: string | null
  carbs_per_serving_g: string | null
  fat_per_serving_g: string | null
  instructions: string[]
  source_type: string
  source_url: string | null
  ingredients: RecipeIngredient[]
  created_at: string
}

export interface RecipeFilters {
  cuisine?: string
  category?: RecipeCategory | typeof UNASSIGNED_FILTER
  diet_type?: DietType | typeof UNASSIGNED_FILTER
  season?: Season | typeof UNASSIGNED_FILTER
  search?: string
  min_calories?: number
  max_calories?: number
  ingredients?: string[]
  page?: number
  per_page?: number
}

export interface PaginatedRecipes {
  data: Recipe[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export async function fetchRecipes(filters: RecipeFilters = {}): Promise<PaginatedRecipes> {
  const response = await api.get('/recipes', { params: filters })
  return response.data
}

export async function fetchCuisines(): Promise<string[]> {
  const response = await api.get('/recipes/cuisines')
  return response.data
}

export async function fetchUnits(): Promise<string[]> {
  const response = await api.get('/recipes/units')
  return response.data
}

export async function fetchRecipe(id: number | string): Promise<Recipe> {
  const response = await api.get(`/recipes/${id}`)
  return response.data.data
}

export interface UpdateRecipeIngredient {
  name: string
  quantity: number
  unit: string
  notes?: string | null
}

export interface UpdateRecipePayload {
  title?: string
  cuisine?: string | null
  category?: RecipeCategory | null
  diet_type?: DietType | null
  seasons?: Season[] | null
  description?: string | null
  instructions?: string[]
  servings?: number
  prep_time_minutes?: number | null
  cook_time_minutes?: number | null
  calories_per_serving?: number | null
  protein_per_serving_g?: number | null
  carbs_per_serving_g?: number | null
  fat_per_serving_g?: number | null
  ingredients?: UpdateRecipeIngredient[]
}

export async function updateRecipe(
  id: number | string,
  payload: UpdateRecipePayload,
): Promise<Recipe> {
  const response = await api.patch(`/recipes/${id}`, payload)
  return response.data.data
}
