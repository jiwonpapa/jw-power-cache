import React, { useLayoutEffect, useMemo, useRef, useState } from 'react';
import { analyzeLayout, type LayoutNode, type SkeletonProfile } from './analyzeLayout';

type Animation = 'wave' | 'pulse' | 'none';

export interface JWPowerCacheSkeletonProps {
    profile?: SkeletonProfile;
    components?: LayoutNode[];
    options?: {
        animation?: Animation;
        iteration_count?: number;
        delay_ms?: number;
    };
    className?: string;
}

const clamp = (value: unknown, fallback: number, min: number, max: number): number => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? Math.min(max, Math.max(min, Math.trunc(parsed))) : fallback;
};

function frontendDelay(): number {
    const settings = window.G7Config?.plugins?.['jw-power_cache'];
    return clamp(settings?.loading_ux_delay_ms, 120, 0, 1000);
}

function Line({ width = '100%', size = 'md' }: { width?: string; size?: 'sm' | 'md' | 'lg' }) {
    return <span className={`jwpc-skeleton__line jwpc-skeleton__line--${size}`} style={{ width }} />;
}

function Rows({ count, compact = false }: { count: number; compact?: boolean }) {
    return (
        <div className="jwpc-skeleton__rows">
            {Array.from({ length: count }, (_, index) => (
                <div className="jwpc-skeleton__row" key={index}>
                    <span className="jwpc-skeleton__avatar" aria-hidden="true" />
                    <div className="jwpc-skeleton__row-copy">
                        <Line width={index % 2 ? '72%' : '88%'} />
                        {!compact && <Line width={index % 3 ? '42%' : '56%'} size="sm" />}
                    </div>
                    <Line width="10%" size="sm" />
                </div>
            ))}
        </div>
    );
}

function Grid({ count, product = false }: { count: number; product?: boolean }) {
    return (
        <div className="jwpc-skeleton__grid">
            {Array.from({ length: count }, (_, index) => (
                <div className="jwpc-skeleton__card" key={index}>
                    <span className={`jwpc-skeleton__media${product ? ' jwpc-skeleton__media--product' : ''}`} />
                    <Line width={index % 2 ? '78%' : '90%'} />
                    <Line width={product ? '38%' : '62%'} size="sm" />
                </div>
            ))}
        </div>
    );
}

function Form({ count, settings = false }: { count: number; settings?: boolean }) {
    return (
        <div className={`jwpc-skeleton__form${settings ? ' jwpc-skeleton__form--settings' : ''}`}>
            {Array.from({ length: Math.min(count, 6) }, (_, index) => (
                <div className="jwpc-skeleton__field" key={index}>
                    <Line width={index % 2 ? '26%' : '18%'} size="sm" />
                    <span className="jwpc-skeleton__control" />
                </div>
            ))}
        </div>
    );
}

function Content({ profile, count }: { profile: SkeletonProfile; count: number }) {
    if (profile === 'datagrid') {
        return <div className="jwpc-skeleton__table"><div className="jwpc-skeleton__table-head"><Line width="30%" /><Line width="18%" /><Line width="14%" /></div><Rows count={count} compact /></div>;
    }
    if (profile === 'board') return <Rows count={count} />;
    if (profile === 'detail') {
        return <div className="jwpc-skeleton__detail"><Line width="68%" size="lg" /><Line width="32%" size="sm" /><span className="jwpc-skeleton__hero" /><Line /><Line width="92%" /><Line width="70%" /></div>;
    }
    if (profile === 'product') return <Grid count={count} product />;
    if (profile === 'form') return <Form count={count} />;
    if (profile === 'settings') return <Form count={count} settings />;
    return <Grid count={count} />;
}

export function JWPowerCacheSkeleton({ profile, components = [], options = {}, className = '' }: JWPowerCacheSkeletonProps) {
    const rootRef = useRef<HTMLDivElement>(null);
    const [visible, setVisible] = useState(false);
    const resolvedProfile = useMemo(() => profile ?? analyzeLayout(components), [profile, components]);
    const count = clamp(options.iteration_count, 5, 1, 12);
    const animation: Animation = ['wave', 'pulse', 'none'].includes(options.animation ?? '')
        ? options.animation as Animation
        : 'wave';
    const delay = clamp(options.delay_ms, frontendDelay(), 0, 1000);

    useLayoutEffect(() => {
        const root = rootRef.current;
        const overlay = root?.closest<HTMLElement>('#g7-skeleton-overlay');
        const style = document.getElementById('g7-skeleton-overlay-style') as HTMLStyleElement | null;
        const previousStyleDisabled = style?.disabled ?? false;
        const previousOpacity = overlay?.style.opacity ?? '';
        const previousAriaHidden = overlay ? overlay.getAttribute('aria-hidden') : null;
        const previousAriaBusy = overlay ? overlay.getAttribute('aria-busy') : null;

        if (style) style.disabled = true;
        if (overlay) {
            overlay.style.opacity = '0';
            overlay.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('aria-busy', 'false');
        }

        const reveal = () => {
            if (!root?.isConnected) return;
            if (style) style.disabled = previousStyleDisabled;
            if (overlay) {
                overlay.style.opacity = '1';
                overlay.removeAttribute('aria-hidden');
                overlay.setAttribute('aria-busy', 'true');
            }
            setVisible(true);
        };
        const timer = window.setTimeout(reveal, delay);

        return () => {
            window.clearTimeout(timer);
            if (style?.isConnected) style.disabled = previousStyleDisabled;
            if (overlay?.isConnected) {
                overlay.style.opacity = previousOpacity;
                if (previousAriaHidden === null) overlay.removeAttribute('aria-hidden');
                else overlay.setAttribute('aria-hidden', previousAriaHidden);
                if (previousAriaBusy === null) overlay.removeAttribute('aria-busy');
                else overlay.setAttribute('aria-busy', previousAriaBusy);
            }
        };
    }, [delay]);

    const label = window.G7Core?.t?.('jw-power_cache.loading_ux.loading') ?? '콘텐츠를 불러오는 중입니다.';

    return (
        <div
            ref={rootRef}
            className={`jwpc-skeleton jwpc-skeleton--${resolvedProfile} jwpc-skeleton--${animation} ${className}`.trim()}
            data-profile={resolvedProfile}
            data-visible={visible ? 'true' : 'false'}
            role="status"
            aria-busy="true"
            aria-live="polite"
            aria-label={label}
            style={{ visibility: visible ? 'visible' : 'hidden' }}
        >
            <span className="jwpc-skeleton__sr-only">{label}</span>
            <Content profile={resolvedProfile} count={count} />
        </div>
    );
}

export default JWPowerCacheSkeleton;
