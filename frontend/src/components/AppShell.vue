<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import Icon from './Icon.vue';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const theme = ref(localStorage.getItem('crm-theme') || 'dark');
function applyTheme() {
    document.documentElement.setAttribute('data-theme', theme.value);
    localStorage.setItem('crm-theme', theme.value);
}
function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    applyTheme();
}
onMounted(applyTheme);

const nav = [
    { to: '/', icon: 'overview', label: 'Übersicht' },
    { to: '/kontakte', icon: 'contacts', label: 'Kontakte' },
    { to: '/pipeline', icon: 'pipeline', label: 'Pipeline' },
    { to: '/aktivitaeten', icon: 'activity', label: 'Aktivitäten' },
    { to: '/formulare', icon: 'search', label: 'Lead-Formulare' },
    { to: '/einwilligungen', icon: 'consent', label: 'Einwilligungen' },
    { to: '/auswertung', icon: 'pipeline', label: 'Auswertung' },
];

const title = computed(() => nav.find((n) => n.to === route.path)?.label ?? 'UltraCRM');
const initials = computed(() => (auth.user?.displayName || auth.user?.username || '?')
    .split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase());

function logout() {
    auth.logout();
    router.push('/login');
}
</script>

<template>
    <div class="shell">
        <aside class="sidebar">
            <div class="brand">
                <span class="brand__mark">U</span>
                <span class="brand__name">UltraCRM</span>
            </div>

            <nav>
                <RouterLink v-for="n in nav" :key="n.to" :to="n.to" class="navlink">
                    <Icon :name="n.icon" :size="19" />
                    <span>{{ n.label }}</span>
                </RouterLink>
            </nav>

            <div class="spacer" />

            <RouterLink v-if="auth.isSuperadmin" to="/mandanten" class="navlink">
                <Icon name="building" :size="19" /><span>Mandanten</span>
            </RouterLink>

            <div class="account">
                <span class="avatar">{{ initials }}</span>
                <span class="account__text">
                    <span class="t-footnote account__name">{{ auth.user?.displayName }}</span>
                    <span class="t-caption">{{ auth.isSuperadmin ? 'Superadmin' : 'Angemeldet' }}</span>
                </span>
            </div>
        </aside>

        <div class="main">
            <header class="navbar">
                <span class="t-footnote crumb">{{ title }}</span>
                <div class="spacer" />
                <button class="iconbtn" :title="theme === 'dark' ? 'Helles Erscheinungsbild' : 'Dunkles Erscheinungsbild'" @click="toggleTheme">
                    <Icon :name="theme === 'dark' ? 'sun' : 'moon'" :size="18" />
                </button>
                <button class="iconbtn" title="Abmelden" @click="logout">
                    <Icon name="logout" :size="18" />
                </button>
            </header>

            <main class="content">
                <RouterView />
            </main>
        </div>
    </div>
</template>

<style scoped>
.shell { display: grid; grid-template-columns: 264px 1fr; min-height: 100vh; }

.sidebar {
    display: flex;
    flex-direction: column;
    gap: var(--sp-2);
    padding: var(--sp-5) var(--sp-4);
    background: var(--bg-base);
    border-right: 1px solid var(--separator);
    position: sticky;
    top: 0;
    height: 100vh;
}

.brand { display: flex; align-items: center; gap: var(--sp-3); padding: 0 var(--sp-2) var(--sp-6); }
.brand__mark {
    width: 30px; height: 30px;
    display: grid; place-items: center;
    border-radius: 8px;
    background: var(--label-primary);
    color: var(--bg-base);
    font-weight: 700; font-size: var(--text-subhead);
}
.brand__name { font-weight: 600; letter-spacing: -0.02em; }

nav { display: flex; flex-direction: column; gap: 2px; }
.navlink {
    display: flex; align-items: center; gap: var(--sp-3);
    padding: 9px var(--sp-3);
    border-radius: var(--radius-s);
    color: var(--label-secondary);
    font-size: var(--text-subhead);
    text-decoration: none;
    transition: background-color .16s ease, color .16s ease;
}
.navlink:hover { background: var(--fill-quaternary); color: var(--label-primary); text-decoration: none; }
.navlink.router-link-exact-active { background: var(--accent-quiet); color: var(--accent); font-weight: 550; }

.account { display: flex; align-items: center; gap: var(--sp-3); padding: var(--sp-3) var(--sp-2) 0; border-top: 1px solid var(--separator); margin-top: var(--sp-3); }
.account__text { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
.account__name { color: var(--label-primary); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.avatar {
    width: 32px; height: 32px; flex: none;
    display: grid; place-items: center;
    border-radius: var(--radius-pill);
    background: var(--fill-tertiary);
    font-size: var(--text-caption); font-weight: 700;
}

.main { display: flex; flex-direction: column; min-width: 0; background: var(--bg-grouped); }

/* Navigation-Bar mit dezentem Blur — der Inhalt scrollt darunter durch. */
.crumb { color: var(--label-tertiary); font-weight: 500; }
.navbar {
    position: sticky; top: 0; z-index: 10;
    display: flex; align-items: center; gap: var(--sp-2);
    padding: var(--sp-4) var(--sp-8);
    background: var(--nav-bg);
    backdrop-filter: var(--nav-blur);
    -webkit-backdrop-filter: var(--nav-blur);
    border-bottom: 1px solid var(--separator);
}
.iconbtn {
    display: grid; place-items: center;
    width: 34px; height: 34px;
    border: 1px solid var(--separator);
    border-radius: var(--radius-pill);
    background: transparent;
    color: var(--label-secondary);
    cursor: pointer;
    transition: background-color .16s ease, color .16s ease;
}
.iconbtn:hover { background: var(--fill-quaternary); color: var(--label-primary); }

.content { padding: var(--sp-8); max-width: 1180px; width: 100%; }

@media (max-width: 900px) {
    .shell { grid-template-columns: 1fr; }
    .sidebar { position: static; height: auto; flex-direction: row; align-items: center; overflow-x: auto; }
    .sidebar nav { flex-direction: row; }
    .brand { padding: 0 var(--sp-3) 0 0; }
    .account, .sidebar > .navlink { display: none; }
    .content { padding: var(--sp-5) var(--sp-4); }
}
</style>
