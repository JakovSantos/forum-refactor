<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of the comments table. parent_id is the comment being answered,
 * or null for a top-level comment. Depth is never stored, it is calculated.
 *
 * @property int      $id
 * @property int      $discussion_id
 * @property int|null $parent_id
 * @property int      $user_id
 * @property string   $body
 */
class Comment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'comments';

    // No $fillable list on purpose: the controller sets each column by hand,
    // so a form field can never fill in user_id or parent_id by itself.
    protected $casts = [
        'id' => 'integer',
        'discussion_id' => 'integer',
        'parent_id' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class, 'discussion_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * All comments of one discussion with their authors, oldest first.
     * Two queries in total (comments, then authors), whatever the thread size.
     *
     * @return Collection<int, Comment>
     */
    public static function forDiscussion(int $discussionId): Collection
    {
        return static::query()
            ->with('author')
            ->where('discussion_id', $discussionId)
            ->orderBy('id')
            ->get();
    }

    /** A comment, but only if it belongs to the given discussion. */
    public static function findInDiscussion(int $discussionId, int $commentId): ?self
    {
        return static::query()
            ->where('discussion_id', $discussionId)
            ->where('id', $commentId)
            ->first();
    }

    /**
     * Displayed depth of a comment (top-level = 0), found by walking up the
     * parent links one step at a time.
     *
     * Returns null if the chain is longer than $limit steps, points at a
     * missing row, or comes back to a comment it already visited. Each of
     * those means the data is not a healthy tree.
     */
    public static function depthOf(int $commentId, int $limit): ?int
    {
        $depth = 0;
        $seen = [];
        $currentId = $commentId;

        while ($currentId !== null) {
            if (isset($seen[$currentId]) || count($seen) > $limit) {
                return null;
            }
            $seen[$currentId] = true;

            $row = static::query()->select(['id', 'parent_id'])->find($currentId);
            if ($row === null) {
                return null;
            }

            $currentId = $row->parent_id;
            if ($currentId !== null) {
                $depth++;
            }
        }

        return $depth;
    }
}
