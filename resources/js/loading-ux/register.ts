import { JWPowerCacheSkeleton } from './JWPowerCacheSkeleton';

const COMPONENTS = { JWPowerCacheSkeleton };
let retryTimer: number | undefined;
let retryCount = 0;

export function registerLoadingUxComponents(): boolean {
    const registerComponents = window.G7Core?.registerComponents;
    if (typeof registerComponents !== 'function') return false;

    registerComponents(COMPONENTS);
    retryCount = 0;
    if (retryTimer !== undefined) window.clearTimeout(retryTimer);
    retryTimer = undefined;

    return true;
}

function tryRegister(): void {
    if (registerLoadingUxComponents()) return;
    if (retryCount >= 50 || retryTimer !== undefined) return;

    retryCount += 1;
    retryTimer = window.setTimeout(() => {
        retryTimer = undefined;
        tryRegister();
    }, 100);
}

export function initPlugin(): void {
    if (retryTimer !== undefined) window.clearTimeout(retryTimer);
    retryTimer = undefined;
    retryCount = 0;
    tryRegister();
}
