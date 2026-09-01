import '../css/loading-ux.css';
import { initPlugin } from './loading-ux/register';

window.__JWPowerCache = {
    identifier: 'jw-power_cache',
    initPlugin,
};

initPlugin();

export { JWPowerCacheSkeleton } from './loading-ux/JWPowerCacheSkeleton';
export { analyzeLayout } from './loading-ux/analyzeLayout';
export { initPlugin, registerLoadingUxComponents } from './loading-ux/register';
