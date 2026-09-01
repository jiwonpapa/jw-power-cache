import React from 'react';
import { act, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { JWPowerCacheSkeleton } from '../../resources/js/loading-ux/JWPowerCacheSkeleton';

describe('JWPowerCacheSkeleton', () => {
    it('delays paint without forcing a minimum display duration', () => {
        vi.useFakeTimers();
        const style = document.createElement('style');
        style.id = 'g7-skeleton-overlay-style';
        document.head.appendChild(style);
        const overlay = document.createElement('div');
        overlay.id = 'g7-skeleton-overlay';
        document.body.appendChild(overlay);

        const view = render(
            <JWPowerCacheSkeleton components={[{ name: 'BoardList' }]} options={{ delay_ms: 120 }} />,
            { container: overlay },
        );
        const status = overlay.querySelector('[role="status"]') as HTMLElement;

        expect(status).toHaveAttribute('data-profile', 'board');
        expect(status).toHaveAttribute('data-visible', 'false');
        expect(style.disabled).toBe(true);
        expect(overlay.style.opacity).toBe('0');
        expect(overlay).toHaveAttribute('aria-hidden', 'true');
        expect(overlay).toHaveAttribute('aria-busy', 'false');

        act(() => vi.advanceTimersByTime(119));
        expect(status).toHaveAttribute('data-visible', 'false');

        act(() => vi.advanceTimersByTime(1));
        expect(status).toHaveAttribute('data-visible', 'true');
        expect(style.disabled).toBe(false);
        expect(overlay.style.opacity).toBe('1');
        expect(overlay).not.toHaveAttribute('aria-hidden');
        expect(overlay).toHaveAttribute('aria-busy', 'true');

        view.unmount();
    });

    it('cancels the delayed reveal when a fast response removes the overlay', () => {
        vi.useFakeTimers();
        const setTimeoutSpy = vi.spyOn(window, 'setTimeout');
        const clearTimeoutSpy = vi.spyOn(window, 'clearTimeout');
        const view = render(<JWPowerCacheSkeleton options={{ delay_ms: 120 }} />);

        view.unmount();
        act(() => vi.runAllTimers());

        expect(setTimeoutSpy).toHaveBeenCalled();
        expect(clearTimeoutSpy).toHaveBeenCalled();
        expect(document.querySelector('.jwpc-skeleton')).toBeNull();
    });

    it('exposes accessible busy status and clamps iteration count', () => {
        vi.useFakeTimers();
        render(<JWPowerCacheSkeleton profile="product" options={{ delay_ms: 0, iteration_count: 99, animation: 'none' }} />);
        act(() => vi.runAllTimers());

        const status = screen.getByRole('status');
        expect(status).toHaveAttribute('aria-busy', 'true');
        expect(status).toHaveClass('jwpc-skeleton--none');
        expect(document.querySelectorAll('.jwpc-skeleton__card')).toHaveLength(12);
    });
});
