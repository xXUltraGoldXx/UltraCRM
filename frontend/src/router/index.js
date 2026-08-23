import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import AppShell from '../components/AppShell.vue';
import LoginView from '../views/LoginView.vue';
import OverviewView from '../views/OverviewView.vue';
import ContactsView from '../views/ContactsView.vue';
import ContactDetailView from '../views/ContactDetailView.vue';
import CompanyDetailView from '../views/CompanyDetailView.vue';
import CompaniesView from '../views/CompaniesView.vue';
import PipelineView from '../views/PipelineView.vue';
import ActivitiesView from '../views/ActivitiesView.vue';
import LeadFormsView from '../views/LeadFormsView.vue';
import PrivacyView from '../views/PrivacyView.vue';
import ImportView from '../views/ImportView.vue';
import ReportsView from '../views/ReportsView.vue';
import TenantsView from '../views/TenantsView.vue';
import PlaceholderView from '../views/PlaceholderView.vue';

// Platzhalter sind bewusst benannt: sie sagen, was dort entsteht, statt eine
// leere Seite zu zeigen.

const routes = [
    { path: '/login', component: LoginView, meta: { oeffentlich: true } },
    {
        path: '/',
        component: AppShell,
        children: [
            { path: '', component: OverviewView },
            { path: 'kontakte', component: ContactsView },
            { path: 'kontakte/:id', component: ContactDetailView },
            { path: 'firmen', component: CompaniesView },
            { path: 'firmen/:id', component: CompanyDetailView },
            { path: 'pipeline', component: PipelineView },
            { path: 'aktivitaeten', component: ActivitiesView },
            { path: 'formulare', component: LeadFormsView },
            { path: 'einwilligungen', component: PrivacyView },
            { path: 'import', component: ImportView },
            { path: 'auswertung', component: ReportsView },
            { path: 'mandanten', component: TenantsView },
        ],
    },
];

const router = createRouter({ history: createWebHistory(), routes });

router.beforeEach((to) => {
    const auth = useAuthStore();
    if (!to.meta.oeffentlich && !auth.token) return '/login';
    if (to.path === '/login' && auth.token) return '/';
    return true;
});

export default router;
