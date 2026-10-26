<script setup lang="ts">
import { RouterLink } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
</script>

<template>
  <nav aria-label="Hauptnavigation" class="tab-bar">
    <RouterLink to="/" class="tab">
      <AppIcon name="calendar" :size="22" />
      Wochenplan
    </RouterLink>
    <RouterLink to="/rezepte" class="tab">
      <AppIcon name="book" :size="22" />
      Rezepte
    </RouterLink>
    <RouterLink to="/einkaufsliste" class="tab">
      <AppIcon name="basket" :size="22" />
      Einkauf
    </RouterLink>
    <RouterLink to="/haushalt" class="tab">
      <AppIcon name="home" :size="22" />
      Haushalt
    </RouterLink>
  </nav>
</template>

<style scoped>
.tab-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 10;
  height: 84px;
  box-sizing: border-box;
  padding: 8px 8px calc(env(safe-area-inset-bottom, 0px) + 8px);
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  background: var(--color-surface);
  border-top: 1px solid var(--color-border);
}

.tab {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  color: var(--color-muted);
  text-decoration: none;
  font-size: 12px;
  font-weight: 500;
  border-radius: var(--radius-sm);
}

.tab.router-link-exact-active {
  color: var(--color-accent);
  font-weight: 600;
}
</style>
