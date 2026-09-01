import '../css/loading-ux.css';
import { initPlugin } from './loading-ux/register';

window.__JWPowerCache = {
    identifier: 'jw-power_cache',
    initPlugin,
};

initPlugin();

export { createJWPowerCacheSkeleton, profileFromPath } from './loading-ux/JWPowerCacheSkeleton';
export { analyzeLayout } from './loading-ux/analyzeLayout';
export { initPlugin, startLoadingUxObserver, stopLoadingUxObserver } from './loading-ux/register';
