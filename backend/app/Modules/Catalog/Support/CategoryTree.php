<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Facades\DB;

/**
 * In-memory view of the category adjacency list (small table, max 3 levels).
 * Loaded once per instance; call flush() after writes.
 */
final class CategoryTree
{
    /** @var array<int, object{id: int, parent_id: ?int, name: string, slug: string, is_active: bool, position: int, deleted: bool}>|null */
    private ?array $nodes = null;

    public function flush(): void
    {
        $this->nodes = null;
    }

    /** @return array<int, object> */
    public function nodes(): array
    {
        if ($this->nodes === null) {
            $this->nodes = [];
            foreach (DB::table('categories')->orderBy('position')->orderBy('id')->get(['id', 'parent_id', 'name', 'slug', 'is_active', 'position', 'deleted_at']) as $row) {
                $this->nodes[(int) $row->id] = (object) [
                    'id' => (int) $row->id,
                    'parent_id' => $row->parent_id !== null ? (int) $row->parent_id : null,
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'is_active' => (bool) $row->is_active,
                    'position' => (int) $row->position,
                    'deleted' => $row->deleted_at !== null,
                ];
            }
        }

        return $this->nodes;
    }

    public function node(int $id): ?object
    {
        return $this->nodes()[$id] ?? null;
    }

    /** @return list<int> ancestors from the root down to (excluding) $id */
    public function ancestorIds(int $id): array
    {
        $ids = [];
        $guard = 0;
        $node = $this->node($id);
        while ($node !== null && $node->parent_id !== null && $guard++ < 10) {
            array_unshift($ids, $node->parent_id);
            $node = $this->node($node->parent_id);
        }

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int> the ids plus all their ancestors
     */
    public function withAncestors(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[] = $id;
            foreach ($this->ancestorIds($id) as $a) {
                $out[] = $a;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<int> $id and every (non-deleted) descendant */
    public function descendantIds(int $id, bool $activeOnly = false): array
    {
        $children = [];
        foreach ($this->nodes() as $node) {
            if ($node->parent_id !== null && ! $node->deleted && (! $activeOnly || $node->is_active)) {
                $children[$node->parent_id][] = $node->id;
            }
        }
        $out = [$id];
        $queue = [$id];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($children[$current] ?? [] as $child) {
                if (! in_array($child, $out, true)) {
                    $out[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $out;
    }

    /** 1 for roots. */
    public function depth(int $id): int
    {
        return count($this->ancestorIds($id)) + 1;
    }

    /** Height of the subtree rooted at $id (1 = leaf). */
    public function subtreeHeight(int $id): int
    {
        $max = 1;
        foreach ($this->nodes() as $node) {
            if ($node->parent_id === $id && ! $node->deleted) {
                $max = max($max, 1 + $this->subtreeHeight($node->id));
            }
        }

        return $max;
    }

    /** True when every ancestor and the node itself are active and not deleted. */
    public function isVisible(int $id): bool
    {
        $node = $this->node($id);
        if ($node === null || $node->deleted || ! $node->is_active) {
            return false;
        }
        foreach ($this->ancestorIds($id) as $a) {
            $n = $this->node($a);
            if ($n === null || $n->deleted || ! $n->is_active) {
                return false;
            }
        }

        return true;
    }
}
