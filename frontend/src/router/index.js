import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
    { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { public: true } },
    {
        path: '/',
        component: () => import('../components/AppShell.vue'),
        children: [
            { path: '', name: 'dashboard', component: () => import('../views/DashboardView.vue') },
            { path: 'kunden', name: 'customers', component: () => import('../views/CustomersView.vue'), meta: { titleKey: 'nav.customers' } },
            { path: 'vorlagen', name: 'templates', component: () => import('../views/TemplatesView.vue'), meta: { titleKey: 'nav.templates' } },
            { path: 'vorlagen/:id', name: 'template-designer', component: () => import('../views/TemplateDesignerView.vue'), meta: { titleKey: 'nav.templateDesigner' } },
            { path: 'scheine', name: 'submissions', component: () => import('../views/SubmissionsView.vue'), meta: { titleKey: 'nav.submissions' } },
            { path: 'scheine/neu/:templateId', name: 'submission-new', component: () => import('../views/FillSubmissionView.vue'), meta: { titleKey: 'nav.submissionNew' } },
            { path: 'scheine/:id/bearbeiten', name: 'submission-edit', component: () => import('../views/FillSubmissionView.vue'), meta: { titleKey: 'nav.submissionEdit' } },
            { path: 'kalender', name: 'calendar', component: () => import('../views/CalendarView.vue'), meta: { titleKey: 'nav.calendar' } },
            { path: 'urlaub', name: 'holiday', component: () => import('../views/UrlaubView.vue'), meta: { titleKey: 'nav.holiday' } },
            { path: 'benutzer', name: 'users', component: () => import('../views/UsersView.vue'), meta: { titleKey: 'nav.users', admin: true } },
            { path: 'einstellungen', name: 'settings', component: () => import('../views/PlaceholderView.vue'), meta: { titleKey: 'nav.settings', admin: true } },
        ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (to.meta.public) return true;
    if (!auth.isAuthenticated) return { name: 'login' };
    if (!auth.user) {
        try {
            await auth.fetchMe();
        } catch {
            auth.logout();
            return { name: 'login' };
        }
    }
    if (to.meta.admin && !auth.isAdmin) return { name: 'dashboard' };
    return true;
});

export default router;
