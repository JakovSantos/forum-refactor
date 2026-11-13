<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Turns the flat list of comment rows into the order and depth the page needs.
 *
 * Step 1: group the rows by parent_id.
 * Step 2: start at the top-level comments and walk down, children after their
 *         parent. This is depth-first and uses a stack instead of recursion, so
 *         a very deep thread cannot overflow the PHP call stack.
 *
 * Depth is calculated while walking (top-level = 0, each reply adds 1).
 * Indentation stops at $indentCap but the depth label keeps its real number.
 *
 * Protection inside the walk:
 *   - a comment that was already visited is skipped, so a loop cannot repeat forever
 *   - nothing deeper than $maxDepth is drawn
 *   - at most $maxNodes comments are drawn
 * A comment that cannot be reached from a top-level comment (for example a
 * disconnected cycle 2 -> 3 -> 2) is simply not drawn. It is counted in "hidden".
 */
final class CommentTree
{
    public function __construct(
        private readonly int $indentCap = 8,
        private readonly int $maxDepth = 50,
        private readonly int $maxNodes = 2000,
    ) {
    }

    /**
     * @param iterable<array<string, mixed>> $rows each row needs at least "id" and "parent_id"
     * @return array{items: list<array<string, mixed>>, hidden: int}
     */
    public function flatten(iterable $rows): array
    {
        $roots = [];
        $children = [];     // parent id => list of rows
        $authors = [];      // comment id => author name
        $total = 0;

        foreach ($rows as $row) {
            $total++;
            $id = (int) $row['id'];
            $authors[$id] = (string) ($row['author'] ?? '');

            if ($row['parent_id'] === null) {
                $roots[] = $row;
            } else {
                $children[(int) $row['parent_id']][] = $row;
            }
        }

        $items = [];
        $visited = [];
        $stack = [];

        // The stack is last-in-first-out, so push in reverse to keep the original order.
        foreach (array_reverse($roots) as $root) {
            $stack[] = [$root, 0];
        }

        while ($stack !== []) {
            [$row, $depth] = array_pop($stack);
            $id = (int) $row['id'];

            if (isset($visited[$id])) {
                continue;
            }
            if ($depth > $this->maxDepth || count($items) >= $this->maxNodes) {
                continue;
            }
            $visited[$id] = true;

            $kids = $children[$id] ?? [];
            $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];

            $items[] = array_merge($row, [
                'depth' => $depth,
                'indent_class' => 'indent-' . min($depth, $this->indentCap),
                'reply_to' => $parentId === null ? null : ($authors[$parentId] ?? null),
                'reply_count' => count($kids),
            ]);

            foreach (array_reverse($kids) as $kid) {
                $stack[] = [$kid, $depth + 1];
            }
        }

        return [
            'items' => $items,
            'hidden' => $total - count($items),
        ];
    }

    /**
     * Turns the flat, ordered list from flatten() into a nested list for the view.
     *
     * Each node keeps every field it had and gets two more:
     *   children      the replies directly under it (same shape, same order)
     *   has_children  true when children is not empty, so the template can decide
     *                 whether a "Hide replies" button makes sense
     *
     * flatten() puts every reply after its parent and gives it depth = parent + 1.
     * Because of that, the parent of an item is the last item seen at depth - 1.
     * This also uses loops instead of recursion, so a deep thread is fine.
     *
     * @param list<array<string, mixed>> $items the "items" list returned by flatten()
     * @return list<array<string, mixed>> top-level comments, each with its children inside
     */
    public function nest(array $items): array
    {
        // Step 1: find the parent (as a position in $items) of every item.
        $parentOf = [];
        $path = [];     // positions of the comments above the current one

        foreach ($items as $i => $item) {
            $depth = (int) $item['depth'];

            while (count($path) > $depth) {
                array_pop($path);
            }

            $parentOf[$i] = $depth === 0 ? null : ($path[$depth - 1] ?? null);
            $path[] = $i;
        }

        // Step 2: build from the last item backwards. In depth-first order every
        // child comes after its parent, so a node's children are finished before
        // the node itself is built.
        $roots = [];
        $waiting = [];  // parent position => finished children (collected in reverse)

        for ($i = count($items) - 1; $i >= 0; $i--) {
            $children = array_reverse($waiting[$i] ?? []);
            $node = $items[$i] + [
                'children' => $children,
                'has_children' => $children !== [],
            ];
            unset($waiting[$i]);

            if ($parentOf[$i] === null) {
                $roots[] = $node;
            } else {
                $waiting[$parentOf[$i]][] = $node;
            }
        }

        return array_reverse($roots);
    }
}
