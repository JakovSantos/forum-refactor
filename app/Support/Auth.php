<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\HttpException;
use App\Models\User;

/**
 * Who is logged in. The only thing kept in the session is the user's ID;
 * the user row is loaded from the database when it is needed.
 */
final class Auth
{
    private static ?User $user = null;
    private static bool $loaded = false;

    public static function user(): ?User
    {
        if (self::$loaded) {
            return self::$user;
        }

        self::$loaded = true;
        $id = $_SESSION['user_id'] ?? null;

        if (is_int($id)) {
            self::$user = User::find($id);

            if (self::$user === null) {
                unset($_SESSION['user_id']);
            }
        }

        return self::$user;
    }

    /** Returns the logged-in user or stops the request with 401. */
    public static function require(): User
    {
        $user = self::user();

        if ($user === null) {
            throw new HttpException(
                401,
                'You need to log in before you can post. Nothing was saved.',
                '/login',
                'Go to the login page'
            );
        }

        return $user;
    }

    public static function login(User $user): void
    {
        Session::regenerate();   // stops session fixation
        Csrf::reset();           // the old token belonged to the guest session
        $_SESSION['user_id'] = (int) $user->id;

        self::$user = $user;
        self::$loaded = true;
    }

    public static function logout(): void
    {
        Session::destroy();

        self::$user = null;
        self::$loaded = false;
    }
}
