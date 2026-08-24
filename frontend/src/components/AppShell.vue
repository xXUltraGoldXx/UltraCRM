<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import Icon from './Icon.vue';
import UiSheet from './ui/UiSheet.vue';

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

// Jeder Eintrag nennt das Recht, das ihn sichtbar macht. Fehlt es, wird er
// ausgeblendet — sonst klickt ein Mitarbeiter auf Menuepunkte, die ihm
// anschliessend 403 liefern. Die Uebersicht bleibt fuer alle sichtbar.
const alleNav = [
    { to: '/', icon: 'overview', label: 'Übersicht', tab: true },
    { to: '/kontakte', icon: 'contacts', label: 'Kontakte', tab: true, recht: 'contacts.view' },
    { to: '/firmen', icon: 'building', label: 'Firmen', recht: 'contacts.view' },
    { to: '/pipeline', icon: 'pipeline', label: 'Pipeline', tab: true, recht: 'deals.view' },
    { to: '/aktivitaeten', icon: 'activity', label: 'Aufgaben', tab: true, recht: 'activities.view' },
    { to: '/formulare', icon: 'search', label: 'Lead-Formulare', recht: 'leadforms.manage' },
    { to: '/einwilligungen', icon: 'consent', label: 'Einwilligungen', recht: 'privacy.view' },
    { to: '/import', icon: 'import', label: 'Import', recht: 'importexport.use' },
    { to: '/dubletten', icon: 'search', label: 'Dubletten', recht: 'contacts.view' },
    { to: '/auswertung', icon: 'pipeline', label: 'Auswertung', recht: 'reports.view' },
    { to: '/pipelines', icon: 'settings', label: 'Pipelines', recht: 'pipelines.manage' },
];

const nav = computed(() => alleNav.filter((n) => !n.recht || auth.darf(n.recht)));

// Auf dem Handy passen vier Ziele in die Leiste; alles Weitere liegt hinter
// "Mehr". Mehr als fuenf Tabs sind auf Daumenbreite nicht mehr treffsicher.
const tabs = computed(() => nav.value.filter((n) => n.tab));
const weitere = computed(() => nav.value.filter((n) => !n.tab));
const mehrOffen = ref(false);

const title = computed(() => nav.value.find((n) => n.to === route.path)?.label ?? 'UltraCRM');
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

            <RouterLink v-if="auth.isAdmin || auth.isSuperadmin" to="/benutzer" class="navlink">
                <Icon name="contacts" :size="19" /><span>Benutzer</span>
            </RouterLink>
            <RouterLink v-if="auth.isAdmin || auth.isSuperadmin" to="/gruppen" class="navlink">
                <Icon name="consent" :size="19" /><span>Berechtigungsgruppen</span>
            </RouterLink>
            <RouterLink v-if="auth.isAdmin || auth.isSuperadmin" to="/zusatzfelder" class="navlink">
                <Icon name="settings" :size="19" /><span>Zusatzfelder</span>
            </RouterLink>
            <RouterLink v-if="auth.isAdmin || auth.isSuperadmin" to="/versand" class="navlink">
                <Icon name="mail" :size="19" /><span>Versand</span>
            </RouterLink>
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

            <!-- Tab-Leiste: nur auf dem Handy sichtbar -->
            <nav class="tabbar">
                <RouterLink v-for="t in tabs" :key="t.to" :to="t.to" class="tab">
                    <Icon :name="t.icon" :size="22" />
                    <span>{{ t.label }}</span>
                </RouterLink>
                <button class="tab" :class="{ aktiv: mehrOffen }" @click="mehrOffen = true">
                    <Icon name="settings" :size="22" />
                    <span>Mehr</span>
                </button>
            </nav>
        </div>

        <!-- Mehr-Menü: nutzt dasselbe Blatt wie alle anderen Dialoge -->
        <UiSheet :offen="mehrOffen" ohneAktionen @schliessen="mehrOffen = false">
            <RouterLink v-for="w in weitere" :key="w.to" :to="w.to" class="mehr__link" @click="mehrOffen = false">
                <Icon :name="w.icon" :size="20" /><span>{{ w.label }}</span>
            </RouterLink>
            <RouterLink v-if="auth.isAdmin || auth.isSuperadmin" to="/benutzer" class="mehr__link" @click="mehrOffen = false">
                <Icon name="contacts" :size="20" /><span>Benutzer</span>
            </RouterLink>
            <RouterLink v-if="auth.isAdmin || auth.isSuperadmin" to="/gruppen" class="mehr__link" @click="mehrOffen = false">
                <Icon name="consent" :size="20" /><span>Berechtigungsgruppen</span>
            </RouterLink>
            <RouterLink v-if="auth.isAdmin || auth.isSuperadmin" to="/zusatzfelder" class="mehr__link" @click="mehrOffen = false">
                <Icon name="settings" :size="20" /><span>Zusatzfelder</span>
            </RouterLink>
            <RouterLink v-if="auth.isAdmin || auth.isSuperadmin" to="/versand" class="mehr__link" @click="mehrOffen = false">
                <Icon name="mail" :size="20" /><span>Versand</span>
            </RouterLink>
            <RouterLink v-if="auth.isSuperadmin" to="/mandanten" class="mehr__link" @click="mehrOffen = false">
                <Icon name="building" :size="20" /><span>Mandanten</span>
            </RouterLink>
            <button class="mehr__link" @click="toggleTheme">
                <Icon :name="theme === 'dark' ? 'sun' : 'moon'" :size="20" />
                <span>{{ theme === 'dark' ? 'Helles Erscheinungsbild' : 'Dunkles Erscheinungsbild' }}</span>
            </button>
            <button class="mehr__link mehr__link--weg" @click="logout">
                <Icon name="logout" :size="20" /><span>Abmelden</span>
            </button>
        </UiSheet>

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

/* Tab-Leiste: unten, mit Blur, ueber dem Home-Indikator freigehalten. */
.tabbar { display: none; }

@media (max-width: 900px) {
    .shell { grid-template-columns: 1fr; }
    .sidebar { display: none; }
    .navbar { padding: var(--sp-3) var(--sp-4); }
    /* Theme und Abmelden liegen auf dem Handy im Mehr-Menue — hier waeren
       sie doppelt und wuerden die Leiste unnoetig fuellen. */
    .navbar .iconbtn { display: none; }
    .content {
        padding: var(--sp-4) var(--sp-4) calc(76px + env(safe-area-inset-bottom));
    }

    .tabbar {
        position: fixed; left: 0; right: 0; bottom: 0; z-index: 20;
        display: grid; grid-template-columns: repeat(5, 1fr);
        background: var(--nav-bg);
        backdrop-filter: var(--nav-blur);
        -webkit-backdrop-filter: var(--nav-blur);
        border-top: 1px solid var(--separator);
        padding-bottom: env(safe-area-inset-bottom);
    }
    .tab {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 3px;
        min-height: 56px;
        border: 0; background: transparent;
        color: var(--label-tertiary);
        font-family: inherit; font-size: 10px; font-weight: 550;
        text-decoration: none; cursor: pointer;
        -webkit-tap-highlight-color: transparent;
    }
    .tab span { letter-spacing: -0.01em; }
    .tab.router-link-exact-active, .tab.aktiv { color: var(--accent); }
}

.mehr__link {
    display: flex; align-items: center; gap: var(--sp-4);
    min-height: 52px; padding: 0 var(--sp-3);
    border: 0; background: transparent;
    color: var(--label-primary);
    font-family: inherit; font-size: var(--text-body); text-align: left;
    border-radius: var(--radius-m);
    text-decoration: none; cursor: pointer;
}
.mehr__link:active { background: var(--fill-quaternary); }
.mehr__link--weg { color: var(--danger); }

</style>
