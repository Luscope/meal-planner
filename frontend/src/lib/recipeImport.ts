import { api } from '@/lib/api'

export type ImportStatus = 'pending' | 'processing' | 'completed' | 'failed'

export interface RecipeImportStatusResponse {
  id: number
  status: ImportStatus
  recipe_id: number | null
  error_message: string | null
}

export interface CreateRecipeImportPayload {
  url?: string
  text?: string
  image?: File
}

export async function createRecipeImport(
  payload: CreateRecipeImportPayload,
): Promise<{ id: number; status: ImportStatus }> {
  let body: FormData | Record<string, string>

  if (payload.image) {
    const formData = new FormData()
    formData.append('image', payload.image)
    body = formData
  } else if (payload.url) {
    body = { url: payload.url }
  } else {
    body = { text: payload.text ?? '' }
  }

  const response = await api.post('/recipe-imports', body)
  return response.data
}

export async function fetchRecipeImportStatus(id: number): Promise<RecipeImportStatusResponse> {
  const response = await api.get(`/recipe-imports/${id}`)
  return response.data
}
