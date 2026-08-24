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
    // Not a getter, since it needs an argument — implemented as an action-like method instead.

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
         * Is the logged-in user allowed to do this? Mirrors the API's rule:
         * admins can do anything, and whoever may edit may also view.
         *
         * Important: this is for display purposes only. The binding check
         * lives in the PermissionVoter on the server — the frontend only
         * hides what would return 403 anyway.
         */
        darf(recht) {
            if (this.isAdmin || this.isSuperadmin) return true;

            const meine = this.user?.permissions || [];
            if (meine.includes(recht)) return true;

            // manage implies view — the same mapping as on the server.
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
