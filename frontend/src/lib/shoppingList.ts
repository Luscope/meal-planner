import { api } from '@/lib/api'

export interface ShoppingListItem {
  ingredient_id: number
  name: string
  unit: string
  quantity: number
  recipes: string[]
}

export interface ShoppingList {
  start_date: string
  end_date: string
  items: ShoppingListItem[]
}

export async function fetchShoppingList(startDate: string, endDate: string): Promise<ShoppingList> {
  const response = await api.get('/shopping-list', {
    params: { start_date: startDate, end_date: endDate },
  })
  return response.data
}
