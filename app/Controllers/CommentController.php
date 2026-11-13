<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Models\Comment;
use App\Models\Discussion;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Input;
use App\Support\Response;
use App\Support\Session;

/**
 * Writing comments. The checks run in the same order as the "post a reply"
 * activity diagram: logged in? -> token and input valid? -> save -> redirect.
 */
final class CommentController
{
    /** POST /discussions/{id}/comments : new top-level comment */
    public function store(string $rawDiscussionId): void
    {
        $this->save($rawDiscussionId, null);
    }

    /** POST /discussions/{id}/comments/{parentId}/replies : reply to a comment */
    public function reply(string $rawDiscussionId, string $rawParentId): void
    {
        $this->save($rawDiscussionId, $rawParentId);
    }

    private function save(string $rawDiscussionId, ?string $rawParentId): void
    {
        // 1. Authentication is checked on the server for every write.
        $user = Auth::require();

        // 2. CSRF token from the session-backed form field.
        Csrf::require();

        // 3. IDs from the URL.
        $discussionId = Input::id($rawDiscussionId);

        if (Discussion::query()->find($discussionId) === null) {
            throw new HttpException(404, 'That discussion does not exist, so nothing was saved.');
        }

        // 4. The parent must exist AND belong to this same discussion.
        $parentId = null;
        $depth = 0;

        if ($rawParentId !== null) {
            $parentId = Input::id($rawParentId);

            if (Comment::findInDiscussion($discussionId, $parentId) === null) {
                throw new HttpException(422, 'The comment you are replying to is not part of this discussion. Nothing was saved.');
            }

            // Depth limit: walk up the chain. A broken chain (missing row, loop,
            // too long) is refused too, which keeps cycles out.
            $maxDepth = (int) Config::get('app.limits.max_reply_depth');
            $parentDepth = Comment::depthOf($parentId, $maxDepth + 1);

            if ($parentDepth === null) {
                throw new HttpException(422, 'That thread could not be checked, so the reply was not saved.');
            }

            $depth = $parentDepth + 1;

            if ($depth > $maxDepth) {
                $this->back($discussionId, 'error', 'Replies can only go ' . $maxDepth . ' levels deep. Reply to a higher comment instead.');
            }
        }

        // 5. Comment text.
        $body = Input::text('body');
        $maxLength = (int) Config::get('app.limits.comment_max_length');

        if ($body === null || $body === '') {
            $this->back($discussionId, 'error', 'Write something before you post.');
        }
        if (mb_strlen($body) > $maxLength) {
            $this->back($discussionId, 'error', 'That comment is too long. The limit is ' . $maxLength . ' characters.');
        }

        // 6. Save. user_id always comes from the session, never from the form.
        $comment = new Comment();
        $comment->discussion_id = $discussionId;
        $comment->parent_id = $parentId;
        $comment->user_id = (int) $user->id;
        $comment->body = $body;
        $comment->save();

        // 7. Redirect so a refresh does not post the same comment twice.
        Session::flash('success', $parentId === null ? 'Your comment was posted.' : 'Your reply was posted.');
        Response::redirect('/discussions/' . $discussionId . '#comment-' . $comment->id);
    }

    private function back(int $discussionId, string $type, string $message): never
    {
        Session::flash($type, $message);
        Response::redirect('/discussions/' . $discussionId);
    }
}
