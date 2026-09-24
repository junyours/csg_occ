import axios from 'axios';

import { useAuthStore } from '@/application/auth/auth.store';

export const httpClient = axios.create({
    baseURL: '/api',
    headers: {
        Accept: 'application/json',
    },
});

httpClient.interceptors.request.use((config) => {
    const token = useAuthStore.getState().token;
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

httpClient.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            useAuthStore.getState().clear();
        }
        return Promise.reject(error);
    },
);
