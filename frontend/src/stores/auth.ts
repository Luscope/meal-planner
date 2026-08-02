import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '@/lib/api'

export interface Household {
  id: number
  name: string
  invite_code: string
}

export interface User {
  id: number
  name: string
  email: string
  household_id: number | null
  household?: Household
}

interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  household_name?: string
  invite_code?: string
}

const TOKEN_STORAGE_KEY = 'auth_token'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem(TOKEN_STORAGE_KEY))
  const user = ref<User | null>(null)

  const isAuthenticated = computed(() => token.value !== null)

  function setSession(newToken: string, newUser: User) {
    token.value = newToken
    user.value = newUser
    localStorage.setItem(TOKEN_STORAGE_KEY, newToken)
  }

  function clearSession() {
    token.value = null
    user.value = null
    localStorage.removeItem(TOKEN_STORAGE_KEY)
  }

  async function login(email: string, password: string) {
    const response = await api.post('/login', { email, password })
    setSession(response.data.token, response.data.user)
  }

  async function register(payload: RegisterPayload) {
    const response = await api.post('/register', payload)
    setSession(response.data.token, response.data.user)
  }

  async function logout() {
    try {
      await api.post('/logout')
    } finally {
      clearSession()
    }
  }

  async function fetchUser() {
    if (!token.value) {
      return
    }

    const response = await api.get('/user')
    user.value = response.data
  }

  return {
    token,
    user,
    isAuthenticated,
    login,
    register,
    logout,
    fetchUser,
    clearSession,
  }
})
