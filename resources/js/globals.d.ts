import type { ComponentType } from 'react';

declare global {
    interface Window {
        G7Core?: {
            registerComponents?: (components: Record<string, ComponentType<any>>) => void;
            t?: (key: string) => string;
        };
        __JWPowerCache?: {
            identifier: string;
            initPlugin: () => void;
        };
        G7Config?: {
            plugins?: Record<string, Record<string, unknown>>;
        };
    }
}

export {};
