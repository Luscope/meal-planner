<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { extractErrorMessage } from '@/lib/api'

const auth = useAuthStore()
const router = useRouter()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const householdName = ref('')
const inviteCode = ref('')
const mode = ref<'create' | 'join'>('create')
const errorMessage = ref('')
const isSubmitting = ref(false)

async function handleSubmit() {
  errorMessage.value = ''
  isSubmitting.value = true

  try {
    await auth.register({
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
      household_name: mode.value === 'create' ? householdName.value || undefined : undefined,
      invite_code: mode.value === 'join' ? inviteCode.value : undefined,
    })
    await router.push({ name: 'wochenplan' })
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <main class="auth-form">
    <h1>Registrieren</h1>

    <form @submit.prevent="handleSubmit">
      <label>
        Name
        <input v-model="name" type="text" required autocomplete="name" />
      </label>

      <label>
        E-Mail
        <input v-model="email" type="email" required autocomplete="username" />
      </label>

      <label>
        Passwort
        <input v-model="password" type="password" required autocomplete="new-password" />
      </label>

      <label>
        Passwort bestätigen
        <input
          v-model="passwordConfirmation"
          type="password"
          required
          autocomplete="new-password"
        />
      </label>

      <div class="mode-toggle">
        <button
          type="button"
          :class="{ active: mode === 'create' }"
          @click="mode = 'create'"
        >
          Neue Familie anlegen
        </button>
        <button type="button" :class="{ active: mode === 'join' }" @click="mode = 'join'">
          Bestehender Familie beitreten
        </button>
      </div>

      <label v-if="mode === 'create'">
        Haushaltsname (optional)
        <input v-model="householdName" type="text" placeholder="z. B. Andrejess-Familie" />
      </label>

      <label v-else>
        Einladungscode
        <input
          v-model="inviteCode"
          type="text"
          required
          placeholder="z. B. AB12CD34"
          class="invite-code-input"
        />
        <span class="field-hint">Den Code bekommst du von einem Familienmitglied.</span>
      </label>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <button type="submit" :disabled="isSubmitting">
        {{ isSubmitting ? 'Wird registriert…' : 'Registrieren' }}
      </button>
    </form>

    <p class="switch">
      Schon einen Account? <RouterLink to="/login">Anmelden</RouterLink>
    </p>
  </main>
</template>

<style scoped>
.auth-form {
  max-width: 380px;
  margin: 3rem auto;
  padding: 2.25rem 2rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface);
  box-shadow: var(--shadow-md);
}

h1 {
  font-size: 1.85rem;
  margin-bottom: 1.5rem;
}

form {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--color-muted);
}

input {
  height: 2.75rem;
  box-sizing: border-box;
  padding: 0 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface-2);
  color: var(--color-text);
  font-size: 0.9375rem;
}

button {
  height: 2.75rem;
  padding: 0 1rem;
  border: none;
  border-radius: var(--radius-md);
  background: var(--color-accent);
  color: var(--color-on-accent);
  font-size: 0.9375rem;
  font-weight: 600;
  cursor: pointer;
}

button:hover:not(:disabled) {
  background: var(--color-accent-hover);
  box-shadow: var(--shadow-md);
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.mode-toggle {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.25rem;
  padding: 0.25rem;
  border-radius: var(--radius-md);
  background: var(--color-surface-2);
}

.mode-toggle button {
  height: 2.5rem;
  background: transparent;
  color: var(--color-muted);
  border: none;
  border-radius: 9px;
  font-size: 0.8125rem;
  font-weight: 500;
  padding: 0.4rem;
  white-space: normal;
}

.mode-toggle button.active {
  background: var(--color-surface);
  color: var(--color-text);
  font-weight: 600;
  box-shadow: var(--shadow-sm);
}

.mode-toggle button:hover:not(:disabled) {
  box-shadow: none;
}

.invite-code-input {
  text-transform: uppercase;
}

.field-hint {
  font-size: 0.8rem;
  color: var(--color-muted);
  font-weight: 400;
}

.error {
  color: #e0554f;
  font-size: 0.9rem;
}

.switch {
  margin-top: 1.5rem;
  font-size: 0.9rem;
}

.switch a {
  color: var(--color-link);
}
</style>
