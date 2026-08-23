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
    },
    // kein Getter, da Argument nötig — als Action-artige Methode

    actions: {
        async login(username, password) {
            const { data } = await api.post('/login', { username, password });
            this.token = data.token;
            localStorage.setItem('crm-token', data.token);
            await this.fetchMe();
        },
        async fetchMe() {
            const { data } = await api.get('/me');
            this.user = data;
        },
        logout() {
            this.token = null;
            this.user = null;
            localStorage.removeItem('crm-token');
        },
        // Prüft, ob der aktuelle Nutzer mindestens eines der Rechte hat (Admin = immer true)
        can(...keys) {
            if (this.isAdmin) return true;
            const mine = this.user?.permissions || [];
            return keys.some((k) => mine.includes(k));
        },
    },
});
