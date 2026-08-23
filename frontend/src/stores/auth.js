import { defineStore } from 'pinia';
import api from '../api';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem('crm-token') || null,
        user: null,
    }),
    getters: {
        isAuthenticated: (s) => !!s.token,
        isAdmin: (s) => s.user?.roles?.includes('ROLE_ADMIN') ?? false,
        isSuperadmin: (s) => s.user?.roles?.includes('ROLE_SUPERADMIN') ?? false,
    },
    // kein Getter, da Argument nötig — als Action-artige Methode

    actions: {
        async login(username, password) {
            const { data } = await api.post('/login', { username, password });
            this.token = data.token;
            localStorage.setItem('crm-token', data.token);
            await this.loadMe();
        },
        async loadMe() {
            const { data } = await api.get('/me');
            this.user = data;
        },
        logout() {
            this.token = null;
            this.user = null;
            localStorage.removeItem('crm-token');
        },
        /**
         * Darf der angemeldete Benutzer das? Bildet die Regel der API nach:
         * Admins duerfen alles, und wer aendern darf, darf auch ansehen.
         *
         * Wichtig: Das hier ist nur fuer die Anzeige. Die verbindliche
         * Pruefung steht im PermissionVoter auf dem Server — das Frontend
         * blendet nur aus, was ohnehin 403 liefern wuerde.
         */
        darf(recht) {
            if (this.isAdmin || this.isSuperadmin) return true;

            const meine = this.user?.permissions || [];
            if (meine.includes(recht)) return true;

            // manage schliesst view ein — dieselbe Zuordnung wie serverseitig.
            const einschluss = {
                'contacts.view': 'contacts.manage',
                'deals.view': 'deals.manage',
                'activities.view': 'activities.manage',
                'privacy.view': 'privacy.manage',
            };

            return einschluss[recht] ? meine.includes(einschluss[recht]) : false;
        },
    },
});
