import { describe, expect, it, vi } from 'vitest';
import { registerLoadingUxComponents } from '../../resources/js/loading-ux/register';
import { JWPowerCacheSkeleton } from '../../resources/js/loading-ux/JWPowerCacheSkeleton';

describe('public component registration', () => {
    it('registers only through window.G7Core.registerComponents', () => {
        const registerComponents = vi.fn();
        window.G7Core = { registerComponents };

        expect(registerLoadingUxComponents()).toBe(true);
        expect(registerComponents).toHaveBeenCalledWith({ JWPowerCacheSkeleton });
    });

    it('does not fail while the public API is unavailable', () => {
        window.G7Core = {};

        expect(registerLoadingUxComponents()).toBe(false);
    });

    it('contains no private registry dependency', async () => {
        const source = await import('../../resources/js/loading-ux/register.ts?raw');

        expect(source.default).not.toContain('ComponentRegistry');
        expect(source.default).not.toContain('getComponentRegistry');
        expect(source.default).not.toContain('__G7_COMPONENTS__');
    });
});
