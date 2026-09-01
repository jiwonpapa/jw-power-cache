import React, { useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '../../resources/css/loading-ux.css';
import { startLoadingUxObserver } from '../../resources/js/loading-ux/register';
import type { SkeletonProfile } from '../../resources/js/loading-ux/analyzeLayout';
import './loading-ux-harness.css';

const screens: Record<string, { label: string; profile: SkeletonProfile; title: string; path: string }> = {
    user: { label: '사용자 홈', profile: 'cards', title: '새로운 콘텐츠', path: '/' },
    admin: { label: '관리자 설정', profile: 'settings', title: '사이트 설정', path: '/admin/settings' },
    board: { label: '게시판 목록', profile: 'board', title: '자유게시판', path: '/board/free' },
    shop: { label: '쇼핑', profile: 'product', title: '추천 상품', path: '/shop/products' },
    mypage: { label: '마이페이지', profile: 'detail', title: '내 활동', path: '/mypage/posts' },
};

const transitionListeners = new Set<(pending: boolean) => void>();
window.G7Core = {
    t: () => '콘텐츠를 불러오는 중입니다.',
    TransitionManager: {
        subscribe: (listener) => {
            const observedListener = (pending: boolean) => {
                document.documentElement.dataset.lastTransitionPending = String(pending);
                listener(pending);
            };
            transitionListeners.add(observedListener);
            document.documentElement.dataset.transitionSubscribers = String(transitionListeners.size);
            return () => transitionListeners.delete(observedListener);
        },
    },
};
window.G7Config = { plugins: { 'jw-power_cache': {
    loading_ux_enabled: true,
    loading_ux_scope: 'all',
    loading_ux_animation: 'wave',
    loading_ux_delay_ms: 120,
    loading_ux_iteration_count: 5,
} } };
window.history.replaceState({}, '', screens.board.path);
startLoadingUxObserver();

function App() {
    const [screen, setScreen] = useState('board');
    const [loading, setLoading] = useState(false);
    const [dark, setDark] = useState(false);
    const timer = useRef<number>();
    const current = screens[screen];

    const run = (duration: number) => {
        if (timer.current) window.clearTimeout(timer.current);
        setLoading(true);
        window.setTimeout(() => {
            transitionListeners.forEach((listener) => listener(true));
            // G7의 플러그인 설정 저장 후 initPlugin 재호출 경로도 함께 재현합니다.
            startLoadingUxObserver();
        }, 0);
        timer.current = window.setTimeout(() => {
            setLoading(false);
            transitionListeners.forEach((listener) => listener(false));
        }, duration);
    };

    const selectScreen = (key: string) => {
        window.history.replaceState({}, '', screens[key].path);
        setScreen(key);
    };

    return (
        <div className={dark ? 'dark harness' : 'harness'}>
            <header className="harness__header">
                <strong>JW PowerCache</strong>
                <nav aria-label="주요 메뉴"><span>홈</span><span>게시판</span><span>쇼핑</span><span>마이페이지</span></nav>
                <button type="button" onClick={() => setDark((value) => !value)} data-testid="dark-toggle">{dark ? '라이트' : '다크'}</button>
            </header>
            <div className="harness__shell">
                <aside className="harness__menu" aria-label="화면 선택">
                    {Object.entries(screens).map(([key, item]) => (
                        <button type="button" key={key} data-testid={`screen-${key}`} aria-current={screen === key ? 'page' : undefined} onClick={() => selectScreen(key)}>{item.label}</button>
                    ))}
                </aside>
                <main className="harness__main" id="main_content_area">
                    <div className="harness__toolbar">
                        <div><small>{current.label}</small><h1>{current.title}</h1></div>
                        <div className="harness__actions">
                            <button type="button" onClick={() => run(60)} data-testid="fast-hit">빠른 HIT 60ms</button>
                            <button type="button" onClick={() => run(10000)} data-testid="slow-api">느린 API 10000ms</button>
                            <button type="button" className="harness__action-spinner" data-testid="action-spinner"><span aria-hidden="true" />저장 중</button>
                        </div>
                    </div>
                    <section className="harness__content" aria-busy={loading}>
                        {loading ? (
                            <div id="g7-skeleton-overlay" role="status" aria-busy="true">
                                <div className="harness__core-spinner" aria-label="G7 transition spinner" />
                            </div>
                        ) : (
                            <div className={`harness__sample harness__sample--${current.profile}`}><p>실제 콘텐츠 영역</p><p>헤더와 메뉴는 유지되고 이 영역만 스켈레톤으로 전환됩니다.</p></div>
                        )}
                    </section>
                </main>
            </div>
        </div>
    );
}

createRoot(document.getElementById('root')!).render(<App />);
