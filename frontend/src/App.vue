<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function handleLogout() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <div class="app-shell">
    <header>
      <RouterLink to="/" class="brand">Familien-Rezept-Planer</RouterLink>

      <nav v-if="auth.isAuthenticated">
        <RouterLink to="/">Wochenplan</RouterLink>
        <RouterLink to="/rezepte">Rezepte</RouterLink>
        <RouterLink to="/einkaufsliste">Einkaufsliste</RouterLink>
        <RouterLink to="/haushalt">Haushalt</RouterLink>
        <span class="user">{{ auth.user?.name }}</span>
        <button type="button" @click="handleLogout">Abmelden</button>
      </nav>
      <nav v-else>
        <RouterLink to="/login">Anmelden</RouterLink>
        <RouterLink to="/register">Registrieren</RouterLink>
      </nav>
    </header>

    <div class="app-content">
      <RouterView />
    </div>
  </div>
</template>

<style scoped>
.app-shell {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.75rem;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid var(--color-border);
  position: sticky;
  top: 0;
  background: var(--color-background);
  box-shadow: var(--shadow-sm);
  z-index: 10;
}

.app-content {
  flex: 1;
  width: 100%;
}

.brand {
  font-weight: 700;
  color: var(--color-heading);
  text-decoration: none;
  white-space: nowrap;
}

nav {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
  font-size: 0.9rem;
}

nav a {
  color: var(--color-text);
  text-decoration: none;
  padding: 0.25rem 0.5rem;
  margin: -0.25rem -0.5rem;
  border-radius: 6px;
}

nav a:hover {
  color: var(--color-link);
  background: var(--color-link-hover-bg);
}

nav a.router-link-exact-active {
  color: var(--color-link);
  font-weight: 600;
}

.user {
  font-weight: 600;
}

button {
  padding: 0.4rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-background-soft);
  color: var(--color-text);
  cursor: pointer;
}

button:hover {
  box-shadow: var(--shadow-sm);
}
</style>
