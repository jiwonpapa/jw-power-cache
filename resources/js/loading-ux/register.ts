import {
    createJWPowerCacheSkeleton,
    profileFromPath,
    type LoadingUxAnimation,
} from './JWPowerCacheSkeleton';

interface RuntimeSettings {
    enabled: boolean;
    scope: 'user' | 'admin' | 'all';
    animation: LoadingUxAnimation;
    delay: number;
    count: number;
}

interface ObservedLoadingNode {
    kind: 'transition' | 'inline';
    cleanup: () => void;
}

const observed = new Map<HTMLElement, ObservedLoadingNode>();
let observer: MutationObserver | null = null;
let unsubscribeTransition: (() => void) | null = null;

const clamp = (value: unknown, fallback: number, min: number, max: number): number => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? Math.min(max, Math.max(min, Math.trunc(parsed))) : fallback;
};

function settings(): RuntimeSettings {
    const raw = window.G7Config?.plugins?.['jw-power_cache'] ?? {};
    const scope = ['user', 'admin', 'all'].includes(String(raw.loading_ux_scope))
        ? String(raw.loading_ux_scope) as RuntimeSettings['scope']
        : 'all';
    const animation = ['wave', 'pulse', 'none'].includes(String(raw.loading_ux_animation))
        ? String(raw.loading_ux_animation) as LoadingUxAnimation
        : 'wave';

    return {
        enabled: raw.loading_ux_enabled === true,
        scope,
        animation,
        delay: clamp(raw.loading_ux_delay_ms, 120, 0, 1000),
        count: clamp(raw.loading_ux_iteration_count, 5, 1, 12),
    };
}

function scopeMatches(scope: RuntimeSettings['scope']): boolean {
    if (scope === 'all') return true;
    const templateType = window.G7Config?.templateType;
    const isAdmin = templateType === 'admin' || /^\/admin(?:\/|$)/.test(window.location.pathname);

    return scope === 'admin' ? isAdmin : !isAdmin;
}

function cleanupDisconnected(): void {
    for (const [node, state] of observed) {
        if (!node.isConnected) state.cleanup();
    }
}

function cleanupTransitionOverlays(): void {
    for (const state of [...observed.values()]) {
        if (state.kind === 'transition') state.cleanup();
    }
}

function observeTransitionOverlay(container: HTMLElement, runtime: RuntimeSettings): void {
    if (observed.has(container)) return;

    const host = container.parentElement;
    if (!host) return;

    const previousObserved = container.dataset.jwpcObserved;
    const previousAriaHidden = container.getAttribute('aria-hidden');
    const previousAriaBusy = container.getAttribute('aria-busy');
    container.dataset.jwpcObserved = 'true';
    container.setAttribute('aria-hidden', 'true');
    container.setAttribute('aria-busy', 'false');

    const pluginOverlay = document.createElement('div');
    pluginOverlay.className = 'jwpc-transition-skeleton';
    pluginOverlay.dataset.jwpcVisible = 'false';
    pluginOverlay.setAttribute('aria-hidden', 'true');
    host.append(pluginOverlay);

    const timer = window.setTimeout(() => {
        if (!container.isConnected || !pluginOverlay.isConnected) return;
        pluginOverlay.append(createJWPowerCacheSkeleton({
            profile: profileFromPath(window.location.pathname),
            animation: runtime.animation,
            iteration_count: runtime.count,
        }));
        pluginOverlay.dataset.jwpcVisible = 'true';
        pluginOverlay.removeAttribute('aria-hidden');
    }, runtime.delay);

    const cleanup = () => {
        window.clearTimeout(timer);
        pluginOverlay.remove();
        if (container.isConnected) {
            if (previousObserved === undefined) delete container.dataset.jwpcObserved;
            else container.dataset.jwpcObserved = previousObserved;
            if (previousAriaHidden === null) container.removeAttribute('aria-hidden');
            else container.setAttribute('aria-hidden', previousAriaHidden);
            if (previousAriaBusy === null) container.removeAttribute('aria-busy');
            else container.setAttribute('aria-busy', previousAriaBusy);
        }
        observed.delete(container);
    };
    observed.set(container, { kind: 'transition', cleanup });
}

function observeInlineSkeleton(container: HTMLElement, runtime: RuntimeSettings): void {
    if (observed.has(container)) return;

    container.dataset.jwpcObserved = 'true';
    container.dataset.jwpcVisible = 'false';
    container.setAttribute('aria-hidden', 'true');
    container.setAttribute('aria-busy', 'false');
    container.setAttribute(
        'aria-label',
        window.G7Core?.t?.('jw-power_cache.loading_ux.loading') ?? '콘텐츠를 불러오는 중입니다.',
    );

    const timer = window.setTimeout(() => {
        if (!container.isConnected) return;
        container.dataset.jwpcVisible = 'true';
        container.removeAttribute('aria-hidden');
        container.setAttribute('aria-busy', 'true');
    }, runtime.delay);

    const cleanup = () => {
        window.clearTimeout(timer);
        if (container.isConnected) {
            delete container.dataset.jwpcObserved;
            container.dataset.jwpcVisible = 'true';
            container.removeAttribute('aria-hidden');
            container.setAttribute('aria-busy', 'true');
        }
        observed.delete(container);
    };
    observed.set(container, { kind: 'inline', cleanup });
}

function scan(root: ParentNode, runtime: RuntimeSettings): void {
    if (root instanceof HTMLElement) {
        if (root.id === 'g7-skeleton-overlay') observeTransitionOverlay(root, runtime);
        if (root.classList.contains('jwpc-inline-skeleton')) observeInlineSkeleton(root, runtime);
    }

    root.querySelectorAll<HTMLElement>('#g7-skeleton-overlay').forEach((node) => observeTransitionOverlay(node, runtime));
    root.querySelectorAll<HTMLElement>('.jwpc-inline-skeleton').forEach((node) => observeInlineSkeleton(node, runtime));
}

export function stopLoadingUxObserver(): void {
    observer?.disconnect();
    observer = null;
    unsubscribeTransition?.();
    unsubscribeTransition = null;
    for (const state of [...observed.values()]) state.cleanup();
    document.documentElement.classList.remove('jwpc-loading-ux-active');
}

export function startLoadingUxObserver(): boolean {
    stopLoadingUxObserver();
    const runtime = settings();
    if (!runtime.enabled || !scopeMatches(runtime.scope)) return false;

    observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof HTMLElement) scan(node, runtime);
            });
        }
        cleanupDisconnected();
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
    const transitionManager = window.G7Core?.TransitionManager;
    if (typeof transitionManager?.subscribe === 'function') {
        unsubscribeTransition = transitionManager.subscribe((pending) => {
            window.setTimeout(() => {
                if (pending) scan(document, runtime);
                else cleanupTransitionOverlays();
            }, 0);
        });
    }
    document.documentElement.classList.add('jwpc-loading-ux-active');
    scan(document, runtime);

    return true;
}

export function initPlugin(): void {
    startLoadingUxObserver();
}
