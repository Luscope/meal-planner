<script setup lang="ts">
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { extractErrorMessage } from '@/lib/api'

const auth = useAuthStore()

const email = ref('')
const errorMessage = ref('')
const successMessage = ref('')
const isSubmitting = ref(false)

async function handleSubmit() {
  errorMessage.value = ''
  successMessage.value = ''
  isSubmitting.value = true

  try {
    successMessage.value = await auth.forgotPassword(email.value)
  } catch (error) {
    errorMessage.value = extractErrorMessage(error)
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <main class="auth-form">
    <h1>Passwort vergessen</h1>

    <template v-if="!successMessage">
      <p class="hint">
        Gib deine E-Mail-Adresse ein. Falls dazu ein Account existiert, schicken wir dir einen
        Link zum Zurücksetzen deines Passworts.
      </p>

      <form @submit.prevent="handleSubmit">
        <label>
          E-Mail
          <input v-model="email" type="email" required autocomplete="username" />
        </label>

        <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

        <button type="submit" :disabled="isSubmitting">
          {{ isSubmitting ? 'Wird gesendet…' : 'Link anfordern' }}
        </button>
      </form>
    </template>

    <p v-else class="success">{{ successMessage }}</p>

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
}

.hint {
  font-size: 0.9375rem;
  color: var(--color-muted);
  margin: 0.75rem 0 1.25rem;
  line-height: 1.55;
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
}

.success {
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
