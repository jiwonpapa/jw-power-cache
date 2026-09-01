import { analyzeLayout, type LayoutNode, type SkeletonProfile } from './analyzeLayout';

export type LoadingUxAnimation = 'wave' | 'pulse' | 'none';

export interface JWPowerCacheSkeletonOptions {
    profile?: SkeletonProfile;
    components?: LayoutNode[];
    animation?: LoadingUxAnimation;
    iteration_count?: number;
    label?: string;
}

const clamp = (value: unknown, fallback: number, min: number, max: number): number => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? Math.min(max, Math.max(min, Math.trunc(parsed))) : fallback;
};

function element(tag: 'div' | 'span', className: string): HTMLElement {
    const node = document.createElement(tag);
    node.className = className;

    return node;
}

function line(width = '100%', size: 'sm' | 'md' | 'lg' = 'md'): HTMLElement {
    const node = element('span', `jwpc-skeleton__line jwpc-skeleton__line--${size}`);
    node.style.width = width;

    return node;
}

function rows(count: number, compact = false): HTMLElement {
    const root = element('div', 'jwpc-skeleton__rows');

    for (let index = 0; index < count; index += 1) {
        const row = element('div', 'jwpc-skeleton__row');
        const avatar = element('span', 'jwpc-skeleton__avatar');
        avatar.setAttribute('aria-hidden', 'true');
        const copy = element('div', 'jwpc-skeleton__row-copy');
        copy.append(line(index % 2 ? '72%' : '88%'));
        if (!compact) copy.append(line(index % 3 ? '42%' : '56%', 'sm'));
        row.append(avatar, copy, line('10%', 'sm'));
        root.append(row);
    }

    return root;
}

function grid(count: number, product = false): HTMLElement {
    const root = element('div', 'jwpc-skeleton__grid');

    for (let index = 0; index < count; index += 1) {
        const card = element('div', 'jwpc-skeleton__card');
        card.append(
            element('span', `jwpc-skeleton__media${product ? ' jwpc-skeleton__media--product' : ''}`),
            line(index % 2 ? '78%' : '90%'),
            line(product ? '38%' : '62%', 'sm'),
        );
        root.append(card);
    }

    return root;
}

function form(count: number, settings = false): HTMLElement {
    const root = element('div', `jwpc-skeleton__form${settings ? ' jwpc-skeleton__form--settings' : ''}`);

    for (let index = 0; index < Math.min(count, 6); index += 1) {
        const field = element('div', 'jwpc-skeleton__field');
        field.append(line(index % 2 ? '26%' : '18%', 'sm'), element('span', 'jwpc-skeleton__control'));
        root.append(field);
    }

    return root;
}

function content(profile: SkeletonProfile, count: number): HTMLElement {
    if (profile === 'datagrid') {
        const table = element('div', 'jwpc-skeleton__table');
        const head = element('div', 'jwpc-skeleton__table-head');
        head.append(line('30%'), line('18%'), line('14%'));
        table.append(head, rows(count, true));
        return table;
    }
    if (profile === 'board') return rows(count);
    if (profile === 'detail') {
        const detail = element('div', 'jwpc-skeleton__detail');
        detail.append(
            line('68%', 'lg'),
            line('32%', 'sm'),
            element('span', 'jwpc-skeleton__hero'),
            line(),
            line('92%'),
            line('70%'),
        );
        return detail;
    }
    if (profile === 'product') return grid(count, true);
    if (profile === 'form') return form(count);
    if (profile === 'settings') return form(count, true);

    return grid(count);
}

export function profileFromPath(pathname: string): SkeletonProfile {
    if (/^\/admin(?:\/|$)/.test(pathname)) {
        return /(?:settings|plugins|config)/.test(pathname) ? 'settings' : 'datagrid';
    }
    if (/^\/board(?:\/|$)/.test(pathname)) return 'board';
    if (/^\/shop(?:\/|$)/.test(pathname)) return 'product';
    if (/^\/mypage(?:\/|$)/.test(pathname)) {
        return /(?:settings|profile|edit)/.test(pathname) ? 'form' : 'detail';
    }

    return 'cards';
}

/**
 * G7 컴포넌트 레지스트리에 의존하지 않는 플러그인 소유 DOM 스켈레톤입니다.
 * 코어 전환 오버레이의 수명은 건드리지 않고 별도 형제 오버레이에 마운트합니다.
 */
export function createJWPowerCacheSkeleton(options: JWPowerCacheSkeletonOptions = {}): HTMLElement {
    const profile = options.profile ?? analyzeLayout(options.components ?? []);
    const animation: LoadingUxAnimation = ['wave', 'pulse', 'none'].includes(options.animation ?? '')
        ? options.animation as LoadingUxAnimation
        : 'wave';
    const count = clamp(options.iteration_count, 5, 1, 12);
    const label = options.label
        ?? window.G7Core?.t?.('jw-power_cache.loading_ux.loading')
        ?? '콘텐츠를 불러오는 중입니다.';

    const root = element('div', `jwpc-skeleton jwpc-skeleton--${profile} jwpc-skeleton--${animation}`);
    root.dataset.profile = profile;
    root.setAttribute('role', 'status');
    root.setAttribute('aria-busy', 'true');
    root.setAttribute('aria-live', 'polite');
    root.setAttribute('aria-label', label);

    const accessibleLabel = element('span', 'jwpc-skeleton__sr-only');
    accessibleLabel.textContent = label;
    root.append(accessibleLabel, content(profile, count));

    return root;
}
