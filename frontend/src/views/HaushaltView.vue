<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { fetchFamilyMembers, type FamilyMember } from '@/lib/mealPlanner'
import AppIcon from '@/components/AppIcon.vue'

const auth = useAuthStore()
const isCopied = ref(false)
const familyMembers = ref<FamilyMember[]>([])

onMounted(async () => {
  try {
    familyMembers.value = await fetchFamilyMembers()
  } catch {
    // Non-critical for this page — the invite-code section still works without it.
  }
})

function initial(name: string): string {
  return name.trim().charAt(0).toUpperCase() || '?'
}

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
      <div class="card-label">Euer Haushalt</div>
      <h2>{{ auth.user.household.name }}</h2>

      <p class="hint">
        Teile diesen Code mit weiteren Familienmitgliedern. Wer sich damit registriert, tritt
        eurem Haushalt bei und teilt sich Rezepte, Wochenplan und Einkaufsliste mit euch.
      </p>

      <div class="invite-code-box">
        <div class="code-chars" :aria-label="`Einladungscode ${auth.user.household.invite_code}`">
          <span v-for="(char, index) in auth.user.household.invite_code.split('')" :key="index" class="code-char">
            {{ char }}
          </span>
        </div>
        <button type="button" @click="copyCode">
          <AppIcon :name="isCopied ? 'check' : 'copy'" :size="17" :stroke-width="isCopied ? 2.25 : 1.75" />
          {{ isCopied ? 'Kopiert' : 'Code kopieren' }}
        </button>
      </div>
    </section>

    <section v-if="familyMembers.length" class="card members-card">
      <h2 class="members-title">Mitglieder</h2>
      <ul class="member-list">
        <li v-for="member in familyMembers" :key="member.id" class="member-row">
          <span class="avatar">{{ initial(member.name) }}</span>
          <span class="member-name">{{ member.name }}</span>
          <span v-if="member.name === auth.user?.name" class="you-badge">Du</span>
        </li>
      </ul>
    </section>
  </main>
</template>

<style scoped>
.haushalt {
  max-width: 640px;
  margin: 0 auto;
  padding: 2rem 1.5rem;
}

h1 {
  font-size: 2rem;
}

.card {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface);
  padding: 1.75rem;
  margin-top: 1.25rem;
}

.card-label {
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--color-muted);
}

h2 {
  margin: 0.25rem 0 0.75rem;
  font-size: 1.4rem;
}

.hint {
  font-size: 0.9375rem;
  color: var(--color-muted);
  line-height: 1.55;
  margin: 0;
}

.invite-code-box {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
  margin-top: 1.25rem;
}

.code-chars {
  display: flex;
  gap: 0.4rem;
}

.code-char {
  width: 2.4rem;
  height: 2.9rem;
  border-radius: var(--radius-sm);
  background: var(--color-surface-2);
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: ui-monospace, 'SF Mono', Menlo, monospace;
  font-size: 1.35rem;
  font-weight: 600;
}

button {
  height: 2.75rem;
  padding: 0 1.1rem;
  border: none;
  border-radius: var(--radius-md);
  background: var(--color-accent);
  color: var(--color-on-accent);
  font-size: 0.9375rem;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

button:hover {
  background: var(--color-accent-hover);
  box-shadow: var(--shadow-md);
}

.members-card {
  padding: 0;
  overflow: hidden;
}

.members-title {
  margin: 0;
  padding: 1.1rem 1.25rem 0.75rem;
  font-size: 1rem;
}

.member-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.member-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1.25rem;
  border-top: 1px solid var(--color-border);
}

.avatar {
  width: 2.25rem;
  height: 2.25rem;
  flex-shrink: 0;
  border-radius: var(--radius-pill);
  background: var(--color-tint);
  color: var(--color-tint-text);
  font-size: 0.875rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
}

.member-name {
  flex-grow: 1;
  font-size: 0.9375rem;
  font-weight: 500;
}

.you-badge {
  padding: 0.2rem 0.65rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-2);
  color: var(--color-muted);
  font-size: 0.75rem;
  font-weight: 600;
}
</style>
