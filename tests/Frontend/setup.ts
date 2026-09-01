import '@testing-library/jest-dom/vitest';
import { afterEach, vi } from 'vitest';

afterEach(() => {
    delete window.G7Core;
    delete window.G7Config;
    delete window.__JWPowerCache;
    document.body.innerHTML = '';
    document.documentElement.className = '';
    document.head.querySelector('#g7-skeleton-overlay-style')?.remove();
    vi.useRealTimers();
});
