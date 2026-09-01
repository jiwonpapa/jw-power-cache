export type SkeletonProfile = 'datagrid' | 'board' | 'detail' | 'cards' | 'product' | 'form' | 'settings';

export interface LayoutNode {
    id?: string;
    name?: string;
    props?: Record<string, unknown>;
    children?: LayoutNode[];
    [key: string]: unknown;
}

const PROFILE_SIGNALS: Readonly<Record<SkeletonProfile, readonly string[]>> = {
    datagrid: ['DataGrid', 'Table', 'TableBody', 'TableRow'],
    board: ['BoardList', 'PostList', 'Pagination', 'PostCard'],
    detail: ['PostDetail', 'Article', 'CommentList', 'Profile'],
    cards: ['CardGrid', 'Card', 'StatCard', 'Gallery'],
    product: ['ProductGrid', 'ProductCard', 'Cart', 'Order', 'Price'],
    form: ['Form', 'Input', 'Textarea', 'Select', 'Editor'],
    settings: ['Settings', 'SettingSection', 'Tabs', 'Switch'],
};

function walk(nodes: LayoutNode[], names: string[], ids: string[]): void {
    for (const node of nodes) {
        if (typeof node?.name === 'string') names.push(node.name);
        if (typeof node?.id === 'string') ids.push(node.id.toLowerCase());
        if (Array.isArray(node?.children)) walk(node.children, names, ids);
    }
}

export function analyzeLayout(components: LayoutNode[] = []): SkeletonProfile {
    const names: string[] = [];
    const ids: string[] = [];
    walk(components, names, ids);
    const all = `${names.join(' ')} ${ids.join(' ')}`.toLowerCase();

    if (/settings|setting|preference|admin/.test(all)) return 'settings';
    if (/product|shop|cart|order|price/.test(all)) return 'product';
    if (/postdetail|article|commentlist|profile|detail/.test(all)) return 'detail';
    if (/boardlist|postlist|pagination|board|posts/.test(all)) return 'board';
    if (/datagrid|tablebody|tablerow|datatable/.test(all)) return 'datagrid';
    if (/cardgrid|productcard|statcard|gallery/.test(all)) return 'cards';
    if (/form|input|textarea|select|editor/.test(all)) return 'form';

    let best: SkeletonProfile = 'cards';
    let bestScore = 0;
    for (const [profile, signals] of Object.entries(PROFILE_SIGNALS) as [SkeletonProfile, readonly string[]][]) {
        const score = signals.reduce((total, signal) => total + names.filter((name) => name === signal).length, 0);
        if (score > bestScore) {
            best = profile;
            bestScore = score;
        }
    }

    return best;
}
