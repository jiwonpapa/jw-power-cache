import { describe, expect, it } from 'vitest';
import {
    createJWPowerCacheSkeleton,
    profileFromPath,
} from '../../resources/js/loading-ux/JWPowerCacheSkeleton';

describe('JWPowerCacheSkeleton DOM renderer', () => {
    it('renders an accessible structure without React or a G7 component registry', () => {
        window.G7Core = { t: () => 'Loading content.' };
        const skeleton = createJWPowerCacheSkeleton({
            profile: 'product',
            animation: 'none',
            iteration_count: 99,
        });

        expect(skeleton).toHaveAttribute('data-profile', 'product');
        expect(skeleton).toHaveAttribute('aria-busy', 'true');
        expect(skeleton).toHaveAttribute('aria-label', 'Loading content.');
        expect(skeleton).toHaveClass('jwpc-skeleton--none');
        expect(skeleton.querySelectorAll('.jwpc-skeleton__card')).toHaveLength(12);
    });

    it('analyzes a supplied component tree', () => {
        const skeleton = createJWPowerCacheSkeleton({ components: [{ name: 'BoardList' }] });

        expect(skeleton).toHaveAttribute('data-profile', 'board');
    });

    it.each([
        ['/admin/plugins/jw-power_cache/settings', 'settings'],
        ['/admin/users', 'datagrid'],
        ['/board/free', 'board'],
        ['/shop/products', 'product'],
        ['/mypage/profile/edit', 'form'],
        ['/mypage/posts', 'detail'],
        ['/', 'cards'],
    ] as const)('classifies route %s as %s', (path, expected) => {
        expect(profileFromPath(path)).toBe(expected);
    });
});
