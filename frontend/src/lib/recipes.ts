import { api } from '@/lib/api'

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
