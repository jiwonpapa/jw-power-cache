<?php

namespace Plugins\Jw\PowerCache\LoadingUx;

final class TemplateProfileRegistry
{
    /**
     * 공식 템플릿의 큰 콘텐츠 로딩 패턴만 명시적으로 허용합니다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function profiles(): array
    {
        return [
            [
                'id' => 'sirsoft-basic.board-index.posts-loading',
                'layout_name' => 'board/index',
                'profile' => 'board',
                'node_name' => 'Div',
                'node_class_tokens' => ['animate-spin', 'h-12', 'w-12', 'border-4'],
                'parent_name' => 'Div',
                'parent_class_tokens' => ['flex-col', 'items-center', 'py-16'],
                'ancestor_if' => '{{!posts?.data?.board && !_global.hasError}}',
            ],
            [
                'id' => 'sirsoft-basic.users-show.profile-loading',
                'layout_name' => 'users/show',
                'profile' => 'detail',
                'node_name' => 'Div',
                'node_class_tokens' => ['animate-spin', 'h-8', 'w-8', 'border-b-2'],
                'parent_name' => 'Div',
                'parent_class_tokens' => ['justify-center', 'items-center', 'py-20'],
                'ancestor_if' => '{{profile.loading}}',
            ],
            [
                'id' => 'sirsoft-basic.users-posts.list-loading',
                'layout_name' => 'users/posts',
                'profile' => 'board',
                'node_name' => 'Div',
                'node_class_tokens' => ['animate-spin', 'h-8', 'w-8', 'border-b-2'],
                'parent_name' => 'Div',
                'parent_class_tokens' => ['justify-center', 'items-center', 'py-20'],
                'ancestor_if' => '{{userPosts.loading && !userPosts.data}}',
            ],
            [
                'id' => 'sirsoft-basic.shop-reorder.processing',
                'layout_name' => 'shop/reorder',
                'profile' => 'product',
                'node_name' => 'Icon',
                'node_prop_name' => 'loader-2',
                'node_class_tokens' => ['animate-spin', 'text-4xl'],
                'parent_name' => 'Div',
                'parent_class_tokens' => ['flex-col', 'justify-center', 'py-16'],
                'ancestor_if' => "{{_local.status === 'pending'}}",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $ancestors
     * @return array<string, mixed>|null
     */
    public function match(string $layoutName, array $node, array $ancestors): ?array
    {
        foreach ($this->profiles() as $profile) {
            if ($profile['layout_name'] !== $layoutName
                || ($node['name'] ?? null) !== $profile['node_name']
                || ! $this->hasClassTokens($node, $profile['node_class_tokens'])) {
                continue;
            }

            if (isset($profile['node_prop_name'])
                && ($node['props']['name'] ?? null) !== $profile['node_prop_name']) {
                continue;
            }

            $parent = $ancestors[array_key_last($ancestors)] ?? null;
            if (! is_array($parent)
                || ($parent['name'] ?? null) !== $profile['parent_name']
                || ! $this->hasClassTokens($parent, $profile['parent_class_tokens'])) {
                continue;
            }

            $conditionFound = false;
            foreach ($ancestors as $ancestor) {
                if (($ancestor['if'] ?? null) === $profile['ancestor_if']) {
                    $conditionFound = true;
                    break;
                }
            }

            if ($conditionFound) {
                return $profile;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $node @param array<int, string> $tokens */
    private function hasClassTokens(array $node, array $tokens): bool
    {
        $className = $node['props']['className'] ?? '';
        if (! is_string($className)) {
            return false;
        }

        $classes = preg_split('/\s+/', trim($className)) ?: [];

        foreach ($tokens as $token) {
            if (! in_array($token, $classes, true)) {
                return false;
            }
        }

        return true;
    }
}
