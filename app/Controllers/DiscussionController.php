<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Models\Comment;
use App\Models\Discussion;
use App\Services\CommentTree;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Input;
use App\Support\View;

/**
 * Public reading. Asks the models for data, shapes it for the view, and never
 * writes HTML or SQL itself.
 */
final class DiscussionController
{
    /** GET / : list of discussions */
    public function index(): void
    {
        $discussions = Discussion::query()
            ->with('author')
            ->withCount('comments')
            ->orderBy('id')
            ->get()
            ->map(static fn (Discussion $d): array => [
                'id' => $d->id,
                'title' => $d->title,
                'author' => $d->author?->username ?? 'unknown',
                'comment_count' => $d->comments_count,
                'created_at' => $d->created_at->format('Y-m-d H:i') . ' UTC',
            ])
            ->all();

        View::render('home', [
            'page_title' => 'Discussions',
            'discussions' => $discussions,
        ]);
    }

    /** GET /discussions/{id} : one discussion with its nested replies */
    public function show(string $rawId): void
    {
        // 1. Validate the ID from the URL (malformed -> 400)
        $id = Input::id($rawId);

        // 2. Ask the model layer (valid but missing -> 404)
        $discussion = Discussion::with('author')->find($id);
        if ($discussion === null) {
            throw new HttpException(404, 'That discussion does not exist.');
        }

        $rows = Comment::forDiscussion($id)
            ->map(static fn (Comment $c): array => [
                'id' => $c->id,
                'parent_id' => $c->parent_id,
                'author' => $c->author?->username ?? 'unknown',
                'body' => $c->body,
                'created_at' => $c->created_at->format('Y-m-d H:i') . ' UTC',
                'created_iso' => $c->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ])
            ->all();

        // 3. Group by parent, work out depth, then nest the replies under their parent
        $tree = new CommentTree(
            (int) Config::get('app.limits.max_indent_depth'),
            (int) Config::get('app.limits.tree_max_depth'),
            (int) Config::get('app.limits.tree_max_nodes'),
        );
        $flat = $tree->flatten($rows);

        // 4. Prepare view data
        $base = Config::get('app.base_path');
        $items = array_map(
            static fn (array $item): array => $item + [
                'reply_action' => $base . '/discussions/' . $id . '/comments/' . $item['id'] . '/replies',
            ],
            $flat['items']
        );
        $comments = $tree->nest($items);

        View::render('discussion', [
            'page_title' => $discussion->title,
            'page_script' => 'collapse.js',
            'discussion' => [
                'id' => $discussion->id,
                'title' => $discussion->title,
                'body' => $discussion->body,
                'author' => $discussion->author?->username ?? 'unknown',
                'created_at' => $discussion->created_at->format('Y-m-d H:i') . ' UTC',
                'created_iso' => $discussion->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'comments' => $comments,
            'comment_count' => count($rows),
            'hidden_count' => $flat['hidden'],
            'can_post' => Auth::user() !== null,
            'new_comment_action' => $base . '/discussions/' . $id . '/comments',
            'max_length' => (int) Config::get('app.limits.comment_max_length'),
        ]);
    }
}
