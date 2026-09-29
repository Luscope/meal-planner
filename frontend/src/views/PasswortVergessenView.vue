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
  max-width: 360px;
  margin: 3rem auto;
  padding: 2rem 1.75rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-md);
}

.hint {
  font-size: 0.9rem;
  opacity: 0.8;
  margin-bottom: 1rem;
  line-height: 1.5;
}

form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.9rem;
}

input {
  padding: 0.5rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-background-soft);
  color: var(--color-text);
  font-size: 1rem;
}

button {
  padding: 0.6rem 1rem;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--color-button-bg);
  color: var(--color-button-text);
  font-size: 1rem;
  cursor: pointer;
}

button:hover:not(:disabled) {
  transform: translateY(-1px);
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
