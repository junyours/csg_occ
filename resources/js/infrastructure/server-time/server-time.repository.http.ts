import { httpClient } from '@/infrastructure/http/client';

interface RawServerTime {
    now: string;
}

export const httpServerTimeRepository = {
    async now(): Promise<Date> {
        const { data } = await httpClient.get<RawServerTime>('/server-time');
        return new Date(data.now);
    },
};
