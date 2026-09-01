import { describe, expect, it } from 'vitest';
import { analyzeLayout } from '../../resources/js/loading-ux/analyzeLayout';

describe('analyzeLayout', () => {
    it.each([
        ['DataGrid', 'datagrid'],
        ['BoardList', 'board'],
        ['PostDetail', 'detail'],
        ['CardGrid', 'cards'],
        ['ProductGrid', 'product'],
        ['Form', 'form'],
        ['SettingSection', 'settings'],
    ] as const)('classifies %s as %s', (name, profile) => {
        expect(analyzeLayout([{ name }])).toBe(profile);
    });

    it('inspects nested identifiers in the provided layout tree', () => {
        expect(analyzeLayout([{ name: 'Div', children: [{ name: 'Div', id: 'mypage_post_detail' }] }])).toBe('detail');
    });
});
