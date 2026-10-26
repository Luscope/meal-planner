<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { extractErrorMessage } from '@/lib/api'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const token = (route.query.token as string) ?? ''
const email = ref((route.query.email as string) ?? '')
const password = ref('')
const passwordConfirmation = ref('')
const errorMessage = ref('')
const isSubmitting = ref(false)

async function handleSubmit() {
  errorMessage.value = ''
  isSubmitting.value = true

  try {
    await auth.resetPassword({
      token,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    await router.push({ name: 'login' })
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <main class="auth-form">
    <h1>Neues Passwort</h1>

    <p v-if="!token" class="error">
      Dieser Link ist unvollständig. Bitte fordere über
      <RouterLink to="/passwort-vergessen">Passwort vergessen</RouterLink> einen neuen Link an.
    </p>

    <form v-else @submit.prevent="handleSubmit">
      <label>
        E-Mail
        <input v-model="email" type="email" required autocomplete="username" />
      </label>

      <label>
        Neues Passwort
        <input v-model="password" type="password" required autocomplete="new-password" />
      </label>

      <label>
        Neues Passwort bestätigen
        <input
          v-model="passwordConfirmation"
          type="password"
          required
          autocomplete="new-password"
        />
      </label>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <button type="submit" :disabled="isSubmitting">
        {{ isSubmitting ? 'Wird gespeichert…' : 'Passwort ändern' }}
      </button>
    </form>

    <p class="switch">
      <RouterLink to="/login">Zurück zur Anmeldung</RouterLink>
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

.error {
  color: #e0554f;
  font-size: 0.9rem;
  line-height: 1.5;
}

.switch {
  margin-top: 1.5rem;
  font-size: 0.9rem;
}

.switch a {
  color: var(--color-link);
}
</style>
