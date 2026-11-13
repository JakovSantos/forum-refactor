<?php

declare(strict_types=1);

use App\Services\CommentTree;

/**
 * Unit tests for the grouping and depth logic. No database or web server needed.
 */
function runCommentTreeTests(): void
{
    T::section('CommentTree (grouping, depth, indentation cap, traversal limits)');

    $row = static fn (int $id, ?int $parent, string $author = 'x'): array => [
        'id' => $id, 'parent_id' => $parent, 'author' => $author, 'body' => 'b' . $id,
    ];

    // The sample thread from the lab sheet (section 4.1).
    $sample = [
        $row(1, null, 'Alice'), $row(2, 1, 'Ben'), $row(3, 2, 'Cara'), $row(4, 1, 'Dee'),
        $row(5, null, 'Eli'), $row(6, 3, 'Ben'), $row(7, 6, 'Eli'),
    ];
    $result = (new CommentTree())->flatten($sample);
    $ids = array_column($result['items'], 'id');
    $depths = array_column($result['items'], 'depth');

    T::same('lab sample: replies come right after their parent', [1, 2, 3, 6, 7, 4, 5], $ids);
    T::same('lab sample: depths 0,1,2,3,4,1,0', [0, 1, 2, 3, 4, 1, 0], $depths);
    T::same('lab sample: nothing hidden', 0, $result['hidden']);
    T::same('lab sample: comment 7 is displayed at depth 4', 4, $result['items'][4]['depth']);
    T::same('lab sample: comment 2 has one sibling (comment 4)', 1, count(array_filter($sample, static fn ($r) => $r['parent_id'] === 1)) - 1);
    T::same('lab sample: reply_to shows the parent author', 'Alice', $result['items'][1]['reply_to']);
    T::same('lab sample: top-level comment has no reply_to', null, $result['items'][0]['reply_to']);
    T::same('lab sample: reply_count of comment 1 is 2', 2, $result['items'][0]['reply_count']);

    // nest(): the same list, with each reply placed inside its parent.
    $tree = new CommentTree();
    $nested = $tree->nest($result['items']);
    T::same('nest: lab sample has two top-level comments (Alice and Eli)', [1, 5], array_column($nested, 'id'));
    T::same('nest: Alice has Ben and Dee as direct replies, in order', [2, 4], array_column($nested[0]['children'], 'id'));
    T::same('nest: Ben has Cara, Cara has comment 6, comment 6 has comment 7', [3, 6, 7], [
        $nested[0]['children'][0]['children'][0]['id'],
        $nested[0]['children'][0]['children'][0]['children'][0]['id'],
        $nested[0]['children'][0]['children'][0]['children'][0]['children'][0]['id'],
    ]);
    T::same('nest: Eli (comment 5) has no replies', [], $nested[1]['children']);
    T::same('nest: has_children is true for Alice and false for Eli', [true, false], [$nested[0]['has_children'], $nested[1]['has_children']]);
    T::same('nest: Dee (comment 4) is a leaf', false, $nested[0]['children'][1]['has_children']);
    T::same('nest: depth is kept (comment 7 is still depth 4)', 4, $nested[0]['children'][0]['children'][0]['children'][0]['children'][0]['depth']);
    T::same('nest: other fields are kept (body of comment 3)', 'b3', $nested[0]['children'][0]['children'][0]['body']);
    T::same('nest: an empty list gives an empty list', [], $tree->nest([]));

    $countNodes = static function (array $nodes): int {
        $total = 0;
        $todo = $nodes;
        while ($todo !== []) {
            $node = array_pop($todo);
            $total++;
            foreach ($node['children'] as $child) {
                $todo[] = $child;
            }
        }

        return $total;
    };
    T::same('nest: no comment is lost or repeated (7 in, 7 out)', 7, $countNodes($nested));

    $siblings = $tree->nest($tree->flatten([$row(1, null), $row(2, 1), $row(3, 1), $row(4, 1), $row(5, null)])['items']);
    T::same('nest: sibling order is kept (2, 3, 4)', [2, 3, 4], array_column($siblings[0]['children'], 'id'));

    // Indentation stops at 8 but the depth keeps counting.
    $chain = [$row(1, null)];
    for ($i = 2; $i <= 12; $i++) {
        $chain[] = $row($i, $i - 1);
    }
    $deep = (new CommentTree(indentCap: 8))->flatten($chain)['items'];
    T::same('indent cap: depth 7 uses indent-7', 'indent-7', $deep[7]['indent_class']);
    T::same('indent cap: depth 8 uses indent-8', 'indent-8', $deep[8]['indent_class']);
    T::same('indent cap: depth 11 still uses indent-8', 'indent-8', $deep[11]['indent_class']);
    T::same('indent cap: depth label stays accurate (11)', 11, $deep[11]['depth']);

    $deepNested = (new CommentTree(indentCap: 8))->nest($deep);
    $levels = 0;
    for ($node = $deepNested[0]; $node['children'] !== []; $node = $node['children'][0]) {
        $levels++;
    }
    T::same('nest: a chain of 12 comments nests 11 levels below the top one', 11, $levels);

    // Cycle that no top-level comment can reach: 2 -> 3 -> 2. It must not loop forever.
    $cycle = (new CommentTree())->flatten([$row(1, null), $row(2, 3), $row(3, 2)]);
    T::same('cycle: only the reachable comment is drawn', [1], array_column($cycle['items'], 'id'));
    T::same('cycle: the two unreachable comments are counted as hidden', 2, $cycle['hidden']);

    $self = (new CommentTree())->flatten([$row(1, null), $row(4, 4)]);
    T::same('self-parent: comment pointing at itself is not drawn', [1], array_column($self['items'], 'id'));

    $orphan = (new CommentTree())->flatten([$row(1, null), $row(2, 99)]);
    T::same('orphan: parent that does not exist is not drawn', 1, $orphan['hidden']);

    // Traversal limits.
    $many = [];
    for ($i = 1; $i <= 10; $i++) {
        $many[] = $row($i, null);
    }
    $limited = (new CommentTree(maxNodes: 5))->flatten($many);
    T::same('node limit: only 5 of 10 are drawn', 5, count($limited['items']));
    T::same('node limit: the rest are counted as hidden', 5, $limited['hidden']);

    $shallow = (new CommentTree(maxDepth: 3))->flatten($chain);
    T::same('depth limit: nothing deeper than depth 3 is drawn', 4, count($shallow['items']));

    T::same('empty thread gives an empty list', ['items' => [], 'hidden' => 0], (new CommentTree())->flatten([]));
}
