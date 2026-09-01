import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    startLoadingUxObserver,
    stopLoadingUxObserver,
} from '../../resources/js/loading-ux/register';

function enable(settings: Record<string, unknown> = {}): void {
    window.G7Config = {
        plugins: {
            'jw-power_cache': {
                loading_ux_enabled: true,
                loading_ux_scope: 'all',
                loading_ux_animation: 'wave',
                loading_ux_delay_ms: 120,
                loading_ux_iteration_count: 5,
                ...settings,
            },
        },
    };
}

function mountCoreOverlay(): HTMLElement {
    const target = document.createElement('main');
    target.id = 'main_content_area';
    const overlay = document.createElement('div');
    overlay.id = 'g7-skeleton-overlay';
    overlay.innerHTML = '<div class="core-spinner">spinner</div>';
    target.append(overlay);
    document.body.append(target);

    return overlay;
}

afterEach(() => stopLoadingUxObserver());

describe('G7 7.0.9 runtime observer', () => {
    it('replaces only the core transition overlay after the configured delay', async () => {
        vi.useFakeTimers();
        enable();
        expect(startLoadingUxObserver()).toBe(true);

        const overlay = mountCoreOverlay();
        await vi.advanceTimersByTimeAsync(0);
        const pluginOverlay = overlay.parentElement?.querySelector<HTMLElement>('.jwpc-transition-skeleton');

        expect(document.documentElement).toHaveClass('jwpc-loading-ux-active');
        expect(overlay).toHaveAttribute('aria-hidden', 'true');
        expect(pluginOverlay).toHaveAttribute('data-jwpc-visible', 'false');

        await vi.advanceTimersByTimeAsync(119);
        expect(pluginOverlay?.querySelector('.jwpc-skeleton')).toBeNull();

        await vi.advanceTimersByTimeAsync(1);
        expect(pluginOverlay).toHaveAttribute('data-jwpc-visible', 'true');
        expect(pluginOverlay?.querySelector('.jwpc-skeleton')).not.toBeNull();
        expect(overlay.querySelector('.core-spinner')).not.toBeNull();
    });

    it('cancels a delayed skeleton when a fast response removes the core overlay', async () => {
        vi.useFakeTimers();
        enable();
        startLoadingUxObserver();
        const overlay = mountCoreOverlay();
        await vi.advanceTimersByTimeAsync(0);

        overlay.remove();
        await vi.advanceTimersByTimeAsync(120);

        expect(document.querySelector('.jwpc-transition-skeleton')).toBeNull();
        expect(document.querySelector('.jwpc-skeleton')).toBeNull();
    });

    it('uses the public TransitionManager completion signal to remove the plugin overlay', async () => {
        vi.useFakeTimers();
        enable();
        const listeners = new Set<(pending: boolean) => void>();
        window.G7Core = {
            TransitionManager: {
                subscribe: (listener) => {
                    listeners.add(listener);
                    return () => listeners.delete(listener);
                },
            },
        };
        startLoadingUxObserver();
        const coreOverlay = mountCoreOverlay();
        listeners.forEach((listener) => listener(true));
        await vi.advanceTimersByTimeAsync(120);

        expect(document.querySelector('.jwpc-transition-skeleton')).not.toBeNull();
        listeners.forEach((listener) => listener(false));
        await vi.advanceTimersByTimeAsync(0);

        expect(document.querySelector('.jwpc-transition-skeleton')).toBeNull();
        expect(coreOverlay.querySelector('.core-spinner')).not.toBeNull();
        expect(coreOverlay).not.toHaveAttribute('aria-hidden');
        expect(coreOverlay).not.toHaveAttribute('aria-busy');
    });

    it('does not touch button or modal action spinners', async () => {
        vi.useFakeTimers();
        enable();
        startLoadingUxObserver();
        const actionSpinner = document.createElement('span');
        actionSpinner.className = 'animate-spin';
        document.body.append(actionSpinner);
        await vi.advanceTimersByTimeAsync(200);

        expect(actionSpinner).not.toHaveAttribute('data-jwpc-observed');
        expect(actionSpinner).toHaveClass('animate-spin');
    });

    it('honors disabled and admin-only scope on a user route', () => {
        enable({ loading_ux_enabled: false });
        expect(startLoadingUxObserver()).toBe(false);

        enable({ loading_ux_scope: 'admin' });
        expect(startLoadingUxObserver()).toBe(false);
        expect(document.documentElement).not.toHaveClass('jwpc-loading-ux-active');
    });

    it('contains no component registry or undocumented registration dependency', async () => {
        const source = await import('../../resources/js/loading-ux/register.ts?raw');

        expect(source.default).not.toContain('registerComponents');
        expect(source.default).not.toContain('ComponentRegistry');
        expect(source.default).not.toContain('getComponentRegistry');
    });
});
