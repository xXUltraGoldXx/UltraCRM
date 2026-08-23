import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    headers: { Accept: 'application/ld+json' },
});

// JWT automatisch mitschicken
api.interceptors.request.use((config) => {
    const token = localStorage.getItem('crm-token');
    if (token && !config.url.endsWith('/login')) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

// Bei 401 zurück zum Login
api.interceptors.response.use(
    (res) => res,
    (err) => {
        if (err.response?.status === 401 && !err.config.url.endsWith('/login')) {
            localStorage.removeItem('crm-token');
            window.location.href = '/login';
        }
        return Promise.reject(err);
    }
);

export default api;
