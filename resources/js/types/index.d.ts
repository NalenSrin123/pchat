export interface User {
    id: number;
    name: string;
    username: string;
    email: string;
    email_verified_at?: string;
    avatar_url?: string | null;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
};
