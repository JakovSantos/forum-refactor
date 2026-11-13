<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property string      $username
 * @property string      $email
 * @property string|null $password_hash
 */
class User extends Model
{
    public const UPDATED_AT = null;   // the table only has created_at

    protected $table = 'users';

    protected $fillable = ['username', 'email', 'password_hash'];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function discussions(): HasMany
    {
        return $this->hasMany(Discussion::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'user_id');
    }

    /** Finds an account by username or email (the collation makes this case-insensitive). */
    public static function findByLogin(string $login): ?self
    {
        return static::query()
            ->where('username', $login)
            ->orWhere('email', $login)
            ->first();
    }

    public static function usernameExists(string $username): bool
    {
        return static::query()->where('username', $username)->exists();
    }

    public static function emailExists(string $email): bool
    {
        return static::query()->where('email', $email)->exists();
    }
}
