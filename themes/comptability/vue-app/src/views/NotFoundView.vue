<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center px-4 text-center">
    <p class="font-display text-6xl font-bold text-accent">404</p>
    <h1 class="mt-3 font-display text-2xl font-semibold text-neu-dark">Page introuvable</h1>
    <p class="mt-2 text-neu-muted">La page demandée n’existe pas ou a été déplacée.</p>
    <RouterLink to="/" class="btn-primary mt-6">Retour au tableau de bord</RouterLink>
  </div>
</template>
