<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { SITE_NAME } from '@/config'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const username = ref('')
const password = ref('')
const submitting = ref(false)

async function onSubmit() {
  submitting.value = true
  auth.error = ''
  try {
    await auth.login(username.value, password.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    await router.replace(redirect)
  } catch {
    // Message affiché via auth.error.
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="flex min-h-screen items-center bg-neu-bg px-4 py-10">
    <div class="mx-auto w-full max-w-md">
      <div class="card p-7 sm:p-9">
        <div class="pb-1 text-center">
          <p class="text-xs font-bold uppercase tracking-[0.2em] text-accent">Bienvenue</p>
          <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-neu-dark">{{ SITE_NAME }}</h1>
          <p class="mt-1 text-sm text-neu-muted">Connectez-vous pour continuer</p>
        </div>

        <form class="mt-7 space-y-5" @submit.prevent="onSubmit">
          <div>
            <label class="label" for="username">Identifiant</label>
            <div class="input-group">
              <span class="input-group-text" aria-hidden="true">@</span>
              <input
                id="username"
                v-model="username"
                class="input"
                name="name"
                autocomplete="username"
                required
                placeholder="Nom d'utilisateur"
              />
            </div>
          </div>

          <div>
            <label class="label" for="password">Mot de passe</label>
            <div class="input-group">
              <span class="input-group-text" aria-hidden="true">*</span>
              <input
                id="password"
                v-model="password"
                class="input"
                name="pass"
                type="password"
                autocomplete="current-password"
                required
                placeholder="Mot de passe"
              />
            </div>
          </div>

          <p
            v-if="auth.error"
            class="rounded-pill bg-accent-lighter px-4 py-3 text-center text-sm font-semibold text-accent"
          >
            {{ auth.error }}
          </p>

          <button type="submit" class="btn-primary btn-block" :disabled="submitting">
            {{ submitting ? 'Connexion…' : 'Se connecter' }}
          </button>
        </form>
      </div>
    </div>
  </section>
</template>
