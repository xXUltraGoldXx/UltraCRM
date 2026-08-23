<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import { NAV_PERMISSIONS } from '../permissions';
import { setLocale, currentLocale } from '../i18n';
import Icon from './Icon.vue';
import PasswordModal from './PasswordModal.vue';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const { t, locale } = useI18n();

const theme = ref(localStorage.getItem('crm-theme') || 'dark');
const sidebarOpen = ref(false);
// Paket 1, Punkt 3: Passwort-Selbstbedienung. Nach Erfolg Logout (kein
// token_version-Mechanismus -- ein bereits ausgestelltes JWT bleibt bis zum
// Ablauf gueltig, siehe MeController-Kommentar; der Logout hier ist die
// UI-seitige Konsequenz, kein Sicherheitsmechanismus).
const showPasswordModal = ref(false);
function onPasswordChanged() {
    showPasswordModal.value = false;
    logout();
}

function applyTheme() {
    document.documentElement.dataset.theme = theme.value;
    localStorage.setItem('crm-theme', theme.value);
}
function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    applyTheme();
}
onMounted(applyTheme);

// Modul #11 Mehrsprachigkeit: kompakter DE/EN-Toggle neben dem Theme-Toggle,
// gleiche icon-btn-Optik (hier als Text statt Icon, da DE/EN selbst schon
// das Icon ist -- ein zusaetzliches Icon waere redundant).
function toggleLocale() {
    setLocale(currentLocale() === 'de' ? 'en' : 'de');
}

function logout() {
    auth.logout();
    router.push({ name: 'login' });
}

// Icon/Permission-Schluessel bleiben stabil (key), Labels kommen jetzt aus
// den Locale-Dateien -- siehe nav.* in src/locales/{de,en}.json.
const allNav = [
    { key: 'dashboard', to: { name: 'dashboard' }, labelKey: 'nav.dashboard', icon: 'dashboard' },
    { key: 'customers', to: { name: 'customers' }, labelKey: 'nav.customers', icon: 'users' },
    { key: 'submissions', to: { name: 'submissions' }, labelKey: 'nav.submissions', icon: 'file' },
    { key: 'templates', to: { name: 'templates' }, labelKey: 'nav.templates', icon: 'templates' },
    { key: 'calendar', to: { name: 'calendar' }, labelKey: 'nav.calendar', icon: 'calendar' },
    { key: 'holiday', to: { name: 'holiday' }, labelKey: 'nav.holiday', icon: 'umbrella' },
];
const adminNav = [
    { to: { name: 'users' }, labelKey: 'nav.users', icon: 'shield' },
    { to: { name: 'settings' }, labelKey: 'nav.settings', icon: 'settings' },
];

// Menüpunkte nach Rechten filtern (Übersicht immer sichtbar; Admin sieht alles)
const nav = computed(() =>
    allNav.filter((item) => {
        const req = NAV_PERMISSIONS[item.key];
        return !req || auth.can(...req);
    })
);

// Seitentitel: aus der Route-Meta (titleKey) oder Uebersicht als Default --
// siehe router/index.js, das frueher rohe deutsche Strings in meta.title hatte.
const pageTitle = computed(() => t(route.meta.titleKey || 'nav.dashboard'));

// document.title mitziehen (Punkt 5 des Auftrags) -- bei Routenwechsel UND
// bei Sprachwechsel (derselbe Titel muss in der neuen Sprache neu uebersetzt werden).
watch([pageTitle], () => {
    document.title = `${pageTitle.value} · ${t('app.name')}`;
}, { immediate: true });
</script>

<template>
    <div class="shell">
        <aside class="sidebar" :class="{ open: sidebarOpen }">
            <div class="side-logo">
                <div class="logo-mark">U</div>
                <b>Ultra<span>CRM</span></b>
            </div>

            <nav class="side-nav">
                <router-link v-for="item in nav" :key="item.labelKey" :to="item.to" @click="sidebarOpen = false">
                    <Icon :name="item.icon" :size="18" /><span>{{ $t(item.labelKey) }}</span>
                </router-link>

                <template v-if="auth.isAdmin">
                    <div class="nav-divider">{{ $t('nav.administration') }}</div>
                    <router-link v-for="item in adminNav" :key="item.labelKey" :to="item.to" @click="sidebarOpen = false">
                        <Icon :name="item.icon" :size="18" /><span>{{ $t(item.labelKey) }}</span>
                    </router-link>
                </template>
            </nav>

            <div class="side-user">
                <div class="avatar">{{ (auth.user?.displayName || '?').slice(0, 2).toUpperCase() }}</div>
                <div class="side-user-info">
                    <b>{{ auth.user?.displayName }}</b>
                    <span>{{ auth.isAdmin ? $t('users.roleAdmin') : $t('users.roleUser') }}</span>
                </div>
                <button class="icon-btn locale-btn" :title="$t('locale.switchTo', { lang: locale === 'de' ? $t('locale.en') : $t('locale.de') })" @click="toggleLocale">
                    {{ locale === 'de' ? $t('locale.de') : $t('locale.en') }}
                </button>
                <button class="icon-btn" :title="$t('password.changeButtonTitle')" @click="showPasswordModal = true"><Icon name="key" :size="17" /></button>
                <button class="icon-btn" :title="$t('nav.logout')" @click="logout"><Icon name="logout" :size="17" /></button>
            </div>
        </aside>

        <div v-if="sidebarOpen" class="sidebar-backdrop" @click="sidebarOpen = false"></div>

        <div class="main">
            <header class="topbar">
                <button class="icon-btn burger" @click="sidebarOpen = !sidebarOpen"><Icon name="menu" /></button>
                <h1>{{ pageTitle }}</h1>
                <button class="icon-btn" :title="theme === 'dark' ? $t('theme.toLight') : $t('theme.toDark')" @click="toggleTheme">
                    <Icon :name="theme === 'dark' ? 'sun' : 'moon'" :size="18" />
                </button>
            </header>
            <main class="content">
                <router-view />
            </main>
        </div>

        <PasswordModal v-if="showPasswordModal" @close="showPasswordModal = false" @changed="onPasswordChanged" />
    </div>
</template>

<style scoped>
.shell { display: flex; min-height: 100vh; }

.sidebar {
    width: 250px;
    flex-shrink: 0;
    background: var(--surface);
    border-right: 1px solid var(--line);
    display: flex;
    flex-direction: column;
    padding: 22px 14px;
    position: sticky;
    top: 0;
    height: 100vh;
}
.side-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 10px 24px;
}
.logo-mark {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    color: #fff;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
}
.side-logo b { font-size: 1.02rem; }
.side-logo span { color: var(--accent); }

.side-nav { display: flex; flex-direction: column; gap: 3px; flex: 1; overflow-y: auto; }
.side-nav a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 13px;
    border-radius: 10px;
    color: var(--ink-soft);
    text-decoration: none;
    font-size: 0.92rem;
    font-weight: 500;
    transition: background 0.18s, color 0.18s;
}
.side-nav a :deep(svg) { opacity: 0.85; }
.side-nav a:hover { background: var(--surface-2); color: var(--ink); }
.side-nav a.router-link-exact-active { background: var(--accent); color: #fff; }
.nav-divider {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--ink-soft);
    padding: 18px 13px 6px;
}

.side-user {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border-radius: 12px;
    background: var(--surface-2);
    margin-top: 12px;
}
.avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    color: #fff;
    font-weight: 700;
    font-size: 0.78rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.side-user-info { flex: 1; min-width: 0; }
.side-user-info b { display: block; font-size: 0.86rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.side-user-info span { color: var(--ink-soft); font-size: 0.74rem; }

.icon-btn {
    background: none;
    border: none;
    color: var(--ink-soft);
    font-size: 1.05rem;
    cursor: pointer;
    padding: 6px;
    border-radius: 8px;
    transition: color 0.2s, background 0.2s;
}
.icon-btn:hover { color: var(--ink); background: var(--surface-2); }
.locale-btn { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.03em; padding: 6px 8px; }

.main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.topbar {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 28px;
    background: var(--surface);
    border-bottom: 1px solid var(--line);
    position: sticky;
    top: 0;
    z-index: 20;
}
.topbar h1 { font-size: 1.1rem; font-weight: 700; flex: 1; }
.burger { display: none; }

.content { padding: 28px; }

.sidebar-backdrop { display: none; }

@media (max-width: 800px) {
    .sidebar {
        position: fixed;
        z-index: 60;
        transform: translateX(-100%);
        transition: transform 0.3s;
    }
    .sidebar.open { transform: none; }
    .sidebar-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.4);
        z-index: 50;
    }
    .burger { display: block; }
    .content { padding: 18px; }
}
</style>
