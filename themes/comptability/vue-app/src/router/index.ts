import { createRouter, createWebHashHistory } from 'vue-router'
import type { RouteLocationNormalized } from 'vue-router'
import AppLayout from '@/components/layout/AppLayout.vue'
import DashboardView from '@/views/DashboardView.vue'
import EntityDetailView from '@/views/EntityDetailView.vue'
import EntityListView from '@/views/EntityListView.vue'
import LoginView from '@/views/LoginView.vue'
import NotFoundView from '@/views/NotFoundView.vue'
import TaxonomyView from '@/views/TaxonomyView.vue'
import { BUNDLES, VOCABULARIES } from '@/schema'
import type { BundleName, Vocabulary } from '@/schema'
import { SITE_NAME } from '@/config'
import { useAuthStore } from '@/stores/auth'

const entityRoute = (path: string, bundle: BundleName) => ({
  path,
  name: bundle,
  component: EntityListView,
  props: { bundle },
  meta: { title: BUNDLES[bundle].plural },
})

const detailRoute = (path: string, bundle: BundleName, title: string) => ({
  path: `${path}/:id(\\d+)`,
  name: `${bundle}-detail`,
  component: EntityDetailView,
  props: (route: RouteLocationNormalized) => ({ bundle, id: String(route.params.id), listPath: `/${path}` }),
  meta: { title },
})

// Hash history : Drupal ne sert le thème que sur ses propres routes (front page).
const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/connexion', name: 'connexion', component: LoginView, meta: { guest: true, title: 'Connexion' } },
    {
      path: '/',
      component: AppLayout,
      meta: { requiresAuth: true },
      children: [
        { path: '', name: 'tableau-de-bord', component: DashboardView, meta: { title: 'Tableau de bord' } },
        entityRoute('operations', 'operation'),
        detailRoute('operations', 'operation', "Détail de l'opération"),
        entityRoute('personnes', 'person'),
        detailRoute('personnes', 'person', 'Fiche personne'),
        {
          path: 'parametres/:vocabulary',
          name: 'parametres',
          component: TaxonomyView,
          props: (route) => ({ vocabulary: route.params.vocabulary }),
          beforeEnter: (to) => (String(to.params.vocabulary) in VOCABULARIES ? true : { path: '/introuvable' }),
          meta: { title: 'Paramètres' },
        },
      ],
    },
    { path: '/:pathMatch(.*)*', name: 'introuvable', component: NotFoundView, meta: { title: 'Page introuvable' } },
  ],
  scrollBehavior() {
    return { top: 0 }
  },
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.initialized) await auth.init()

  const vocabulary = to.params.vocabulary as Vocabulary | undefined
  const title = vocabulary && VOCABULARIES[vocabulary] ? VOCABULARIES[vocabulary].label : (to.meta.title as string)
  to.meta.title = title
  document.title = title ? `${title} · ${SITE_NAME}` : SITE_NAME

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'connexion', query: { redirect: to.fullPath } }
  }
  if (to.meta.guest && auth.isAuthenticated) {
    return { name: 'tableau-de-bord' }
  }
  if (vocabulary && VOCABULARIES[vocabulary]?.adminOnly && !auth.isAdmin) {
    return { name: 'tableau-de-bord' }
  }
  return true
})

export default router
