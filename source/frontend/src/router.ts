import { createRouter, createWebHistory } from 'vue-router'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'repositories',
      component: () => import('./views/RepositoryListView.vue'),
    },
    {
      // Filters, sort and page will live in the query string, so a refresh keeps them.
      path: '/repositories/:repositoryId/contributors',
      name: 'contributors',
      component: () => import('./views/ContributorsView.vue'),
      props: true,
    },
    {
      path: '/repositories/:repositoryId/contributors/:authorId',
      name: 'contributor-detail',
      component: () => import('./views/ContributorDetailView.vue'),
      props: true,
    },
  ],
})
