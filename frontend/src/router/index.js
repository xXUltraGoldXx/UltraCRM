import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import AppShell from '../components/AppShell.vue';
import LoginView from '../views/LoginView.vue';
import OverviewView from '../views/OverviewView.vue';
import ContactsView from '../views/ContactsView.vue';
import PipelineView from '../views/PipelineView.vue';
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
            { path: 'pipeline', component: PipelineView },
            { path: 'aktivitaeten', component: PlaceholderView, props: { titel: 'Aktivitäten', text: 'Anrufe, Notizen und Wiedervorlagen — in Arbeit (Paket 5).' } },
            { path: 'einwilligungen', component: PlaceholderView, props: { titel: 'Einwilligungen', text: 'Auskunft, Löschung und Einwilligungs-Historie nach DSGVO — in Arbeit (Paket 7).' } },
            { path: 'mandanten', component: PlaceholderView, props: { titel: 'Mandanten', text: 'Mandantenverwaltung für Superadmins — Oberfläche folgt, die API steht bereits.' } },
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
