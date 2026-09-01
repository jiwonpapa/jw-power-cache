declare global {
    interface Window {
        G7Core?: {
            t?: (key: string) => string;
            TransitionManager?: {
                subscribe?: (listener: (pending: boolean) => void) => (() => void);
            };
        };
        __JWPowerCache?: {
            identifier: string;
            initPlugin: () => void;
        };
        G7Config?: {
            templateType?: 'user' | 'admin';
            plugins?: Record<string, Record<string, unknown>>;
        };
    }
}

export {};
