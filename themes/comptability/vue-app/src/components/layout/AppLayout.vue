<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { SITE_NAME } from '@/config'
import { VOCABULARIES } from '@/schema'
import type { Vocabulary } from '@/schema'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const nav = computed(() => [
  { title: '', links: auth.isAdmin ? [{ to: '/', label: 'Tableau de bord', exact: true }] : [] },
  {
    title: 'Relevé',
    links: [
      { to: '/operations', label: 'Opérations', exact: true },
      { to: '/operations/saisie-multiple', label: 'Saisie multiple' },
      { to: '/personnes', label: 'Personnes' },
      { to: '/evenements', label: 'Événements' },
    ],
  },
  {
    title: 'Paramètres',
    links: (Object.keys(VOCABULARIES) as Vocabulary[])
      .filter((vid) => auth.isAdmin || !VOCABULARIES[vid].adminOnly)
      .map((vid) => ({
        to: `/parametres/${vid}`,
        label: VOCABULARIES[vid].label,
      }))
      .concat(auth.isAdmin ? [{ to: '/reglage', label: 'Réglages IA' }] : []),
  },
].filter((group) => group.links.length))

const pageTitle = computed(() => (route.meta.title as string) || SITE_NAME)

const mainEl = ref<HTMLElement | null>(null)
watch(
  () => route.path,
  () => mainEl.value?.scrollTo(0, 0),
)

const QUICK_ACTIONS = [
  { path: '/personnes', label: '+ Personne' },
  { path: '/operations', label: '+ Opération' },
]

/** L'action principale suit la page courante (opérations par défaut). */
const primaryAction = computed(
  () => QUICK_ACTIONS.find((a) => isActive(a.path)) ?? QUICK_ACTIONS[QUICK_ACTIONS.length - 1],
)
const secondaryActions = computed(() => QUICK_ACTIONS.filter((a) => a !== primaryAction.value))

function isActive(to: string, exact?: boolean) {
  if (exact) return route.path === to
  return route.path === to || route.path.startsWith(`${to}/`)
}

/** Rechargement complet : aucune donnée (personnes, listes) du compte précédent ne reste en mémoire. */
async function logout() {
  await auth.logout()
  await router.replace({ name: 'connexion' })
  window.location.reload()
}
</script>

<template>
  <div
    class="flex h-[100dvh] flex-col overflow-hidden bg-neu-bg lg:grid lg:h-auto lg:min-h-screen lg:grid-cols-[260px_1fr] lg:gap-6 lg:overflow-visible lg:p-6"
  >
    <div v-if="ui.sidebarOpen" class="fixed inset-0 z-30 bg-neu-dark/25 lg:hidden" @click="ui.closeSidebar()" />

    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-[260px] flex-col overflow-y-auto bg-neu-bg p-4 transition-transform lg:sticky lg:top-6 lg:h-[calc(100vh-3rem)] lg:translate-x-0 lg:rounded-neu-xl lg:shadow-neu-out"
      :class="ui.sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="px-2 py-3">
        <RouterLink to="/" class="block" @click="ui.closeSidebar()">
          <p class="text-xl font-bold tracking-wide text-neu-dark">{{ SITE_NAME }}</p>
          <p class="mt-1 text-xs text-neu-muted">Relevé des opérations</p>
        </RouterLink>
      </div>

      <nav class="mt-2 flex-1 space-y-4">
        <div v-for="group in nav" :key="group.title" class="space-y-2">
          <RouterLink
            v-for="item in group.links"
            :key="item.to"
            :to="item.to"
            class="block rounded-pill px-4 py-2.5 text-sm font-semibold transition"
            :class="isActive(item.to, 'exact' in item && item.exact) ? 'nav-active' : 'text-neu-muted hover:text-accent'"
            @click="ui.closeSidebar()"
          >
            {{ item.label }}
          </RouterLink>
        </div>
      </nav>

      <div class="mt-4 rounded-neu p-4 shadow-neu-in">
        <p class="truncate text-sm font-semibold text-neu-dark">{{ auth.user?.name }}</p>
        <p class="truncate text-xs text-neu-muted">{{ auth.user?.email }}</p>
        <button type="button" class="btn-ghost mt-2 w-full justify-start px-0" @click="logout">
          Se déconnecter
        </button>
      </div>
    </aside>

    <div class="flex min-h-0 min-w-0 flex-1 flex-col">
      <header
        class="sticky top-0 z-20 mx-4 mt-4 flex shrink-0 items-center justify-between gap-3 rounded-neu-xl bg-neu-bg px-4 py-3 shadow-neu-out sm:mx-6 lg:mx-0 lg:mt-0"
      >
        <div class="flex min-w-0 items-center gap-3">
          <button type="button" class="btn-secondary shrink-0 px-3 lg:hidden" aria-label="Menu" @click="ui.toggleSidebar()">
            ☰
          </button>
          <h1 class="truncate text-lg font-bold tracking-wide text-neu-dark sm:text-xl">{{ pageTitle }}</h1>
        </div>
        <div class="flex shrink-0 gap-2 whitespace-nowrap">
          <RouterLink
            v-for="action in secondaryActions"
            :key="action.path"
            :to="{ path: action.path, query: { nouveau: '1' } }"
            class="btn-secondary hidden text-sm sm:inline-flex"
          >
            {{ action.label }}
          </RouterLink>
          <RouterLink
            :to="{ path: primaryAction.path, query: { nouveau: '1' } }"
            class="btn-primary btn-pill text-sm"
          >
            {{ primaryAction.label }}
          </RouterLink>
        </div>
      </header>

      <!-- Mobile / tablette : la page ne défile jamais, seul <main> (ou la liste qu'il contient) défile. -->
      <main ref="mainEl" class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-5 sm:px-6 lg:overflow-visible lg:px-0 lg:py-6">
        <RouterView />
      </main>
    </div>

    <Teleport to="body">
      <div
        v-if="ui.toast"
        class="card fixed bottom-5 right-5 z-[60] max-w-sm px-5 py-3 text-sm font-semibold"
        :class="{
          'text-emerald-700': ui.toast.type === 'success',
          'text-rose-600': ui.toast.type === 'error',
          'text-neu-dark': ui.toast.type === 'info',
        }"
      >
        {{ ui.toast.message }}
      </div>
    </Teleport>
  </div>
</template>
