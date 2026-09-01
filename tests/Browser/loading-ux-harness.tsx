import React, { useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '../../resources/css/loading-ux.css';
import { JWPowerCacheSkeleton } from '../../resources/js/loading-ux/JWPowerCacheSkeleton';
import type { SkeletonProfile } from '../../resources/js/loading-ux/analyzeLayout';
import './loading-ux-harness.css';

const screens: Record<string, { label: string; profile: SkeletonProfile; title: string }> = {
    user: { label: '사용자 홈', profile: 'cards', title: '새로운 콘텐츠' },
    admin: { label: '관리자 설정', profile: 'settings', title: '사이트 설정' },
    board: { label: '게시판 목록', profile: 'board', title: '자유게시판' },
    shop: { label: '쇼핑', profile: 'product', title: '추천 상품' },
    mypage: { label: '마이페이지', profile: 'detail', title: '내 활동' },
};

window.G7Core = { t: () => '콘텐츠를 불러오는 중입니다.' };
window.G7Config = { plugins: { 'jw-power_cache': { loading_ux_delay_ms: 120 } } };

function App() {
    const [screen, setScreen] = useState('board');
    const [loading, setLoading] = useState(false);
    const [dark, setDark] = useState(false);
    const timer = useRef<number>();
    const current = screens[screen];

    const run = (duration: number) => {
        if (timer.current) window.clearTimeout(timer.current);
        setLoading(true);
        timer.current = window.setTimeout(() => setLoading(false), duration);
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
                        <button type="button" key={key} data-testid={`screen-${key}`} aria-current={screen === key ? 'page' : undefined} onClick={() => setScreen(key)}>{item.label}</button>
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
                            <JWPowerCacheSkeleton profile={current.profile} options={{ animation: 'wave', iteration_count: 5, delay_ms: 120 }} />
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
