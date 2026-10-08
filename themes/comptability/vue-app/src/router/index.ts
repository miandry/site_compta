import { createRouter, createWebHashHistory } from 'vue-router'
import type { RouteLocationNormalized } from 'vue-router'
import AppLayout from '@/components/layout/AppLayout.vue'
import BulkOperationView from '@/views/BulkOperationView.vue'
import DashboardView from '@/views/DashboardView.vue'
import EntityDetailView from '@/views/EntityDetailView.vue'
import EntityListView from '@/views/EntityListView.vue'
import LoginView from '@/views/LoginView.vue'
import NotFoundView from '@/views/NotFoundView.vue'
import ReglageView from '@/views/ReglageView.vue'
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
        {
          path: 'operations/saisie-multiple',
          name: 'operation-bulk',
          component: BulkOperationView,
          meta: { title: 'Saisie multiple' },
        },
        entityRoute('personnes', 'person'),
        detailRoute('personnes', 'person', 'Fiche personne'),
        entityRoute('evenements', 'event'),
        detailRoute('evenements', 'event', "Détail de l'événement"),
        { path: 'reglage', name: 'reglage', component: ReglageView, meta: { title: 'Réglages IA', adminOnly: true } },
        { path: 'reglages-ia', redirect: '/reglage' },
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
  // Tableau de bord (activité de tous) : administrateurs seulement ; les autres arrivent sur leurs opérations.
  const home = auth.isAdmin ? { name: 'tableau-de-bord' } : { path: '/operations' }
  if (to.meta.guest && auth.isAuthenticated) {
    return home
  }
  if (to.name === 'tableau-de-bord' && auth.isAuthenticated && !auth.isAdmin) {
    return home
  }
  if (((vocabulary && VOCABULARIES[vocabulary]?.adminOnly) || to.meta.adminOnly) && !auth.isAdmin) {
    return home
  }
  return true
})

export default router
