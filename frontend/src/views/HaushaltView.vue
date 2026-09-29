<script setup lang="ts">
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const isCopied = ref(false)

async function copyCode() {
  const code = auth.user?.household?.invite_code
  if (!code) {
    return
  }

  try {
    await navigator.clipboard.writeText(code)
    isCopied.value = true
    setTimeout(() => {
      isCopied.value = false
    }, 2000)
  } catch {
    // Clipboard access can be denied by the browser; the code stays visible to copy manually.
  }
}
</script>

<template>
  <main class="haushalt">
    <h1>Haushalt</h1>

    <section v-if="auth.user?.household" class="card">
      <h2>{{ auth.user.household.name }}</h2>

      <p class="hint">
        Teile diesen Code mit weiteren Familienmitgliedern. Wer sich damit registriert, tritt
        eurem Haushalt bei und teilt sich Rezepte, Wochenplan und Einkaufsliste mit euch.
      </p>

      <div class="invite-code-box">
        <span class="code">{{ auth.user.household.invite_code }}</span>
        <button type="button" @click="copyCode">{{ isCopied ? 'Kopiert!' : 'Kopieren' }}</button>
      </div>
    </section>
  </main>
</template>

<style scoped>
.haushalt {
  max-width: 560px;
  margin: 0 auto;
  padding: 1.5rem;
}

.card {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  padding: 1.5rem;
  margin-top: 1rem;
}

h2 {
  margin-top: 0;
}

.hint {
  font-size: 0.9rem;
  opacity: 0.8;
  line-height: 1.5;
}

.invite-code-box {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-top: 1rem;
}

.code {
  font-family: monospace;
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: 0.15em;
  background: var(--color-background-soft);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 0.5rem 1rem;
}

button {
  padding: 0.6rem 1.1rem;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--color-button-bg);
  color: var(--color-button-text);
  font-size: 0.9rem;
  cursor: pointer;
}

button:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow-md);
}
</style>
