<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Input;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;
use Illuminate\Database\QueryException;

/**
 * Registration, login and logout.
 */
final class AuthController
{
    private static ?string $dummyHash = null;

    /** GET /register */
    public function showRegister(): void
    {
        if (Auth::user() !== null) {
            Response::redirect('/');
        }

        View::render('register', ['page_title' => 'Register', 'old' => [], 'errors' => [], 'has_errors' => false]);
    }

    /** POST /register */
    public function register(): void
    {
        Csrf::require();

        $username = Input::line('username');
        $email = strtolower(Input::line('email'));
        $password = Input::raw('password');
        $confirm = Input::raw('password_confirm');

        $errors = [];

        if (preg_match('/^[A-Za-z0-9_]{3,30}$/', $username) !== 1) {
            $errors[] = 'Username must be 3 to 30 characters: letters, numbers and underscores only.';
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address.';
        }
        // bcrypt only reads the first 72 bytes, so longer passwords are refused instead of silently cut.
        if (strlen($password) < 8 || strlen($password) > 72) {
            $errors[] = 'Password must be 8 to 72 characters long.';
        }
        if (!hash_equals($password, $confirm)) {
            $errors[] = 'The two passwords do not match.';
        }

        if ($errors === []) {
            if (User::usernameExists($username)) {
                $errors[] = 'That username is already taken.';
            }
            if (User::emailExists($email)) {
                $errors[] = 'An account with that email already exists.';
            }
        }

        if ($errors === []) {
            try {
                User::create([
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            } catch (QueryException $e) {
                // Two people registering the same name at the same moment: the UNIQUE key catches it.
                if (($e->errorInfo[0] ?? '') !== '23000') {
                    throw $e;
                }
                $errors[] = 'That username or email is already in use.';
            }
        }

        if ($errors !== []) {
            // The password is never sent back to the form.
            View::render('register', [
                'page_title' => 'Register',
                'old' => ['username' => $username, 'email' => $email],
                'errors' => $errors,
                'has_errors' => true,
            ], 422);

            return;
        }

        Session::flash('success', 'Your account was created. You can log in now.');
        Response::redirect('/login');
    }

    /** GET /login */
    public function showLogin(): void
    {
        if (Auth::user() !== null) {
            Response::redirect('/');
        }

        View::render('login', ['page_title' => 'Log in', 'old' => []]);
    }

    /** POST /login */
    public function login(): void
    {
        Csrf::require();

        $login = Input::line('login');
        $password = Input::raw('password');

        $user = $login === '' ? null : User::findByLogin($login);
        $hash = $user?->password_hash;

        // A null or empty hash can never log in (the seed users have none).
        // When there is nothing to check, a dummy hash is checked anyway so a
        // wrong username and a wrong password take about the same time.
        if (is_string($hash) && $hash !== '') {
            $valid = password_verify($password, $hash);
        } else {
            password_verify($password, self::dummyHash());
            $valid = false;
        }

        if ($user === null || !$valid) {
            // One message for both cases, so the form does not reveal which usernames exist.
            View::render('login', [
                'page_title' => 'Log in',
                'old' => ['login' => $login],
                'error' => 'Incorrect username or password.',
            ], 401);

            return;
        }

        Auth::login($user);   // regenerates the session ID
        Session::flash('success', 'Welcome back, ' . $user->username . '.');
        Response::redirect('/');
    }

    /** POST /logout */
    public function logout(): void
    {
        Csrf::require();

        Auth::logout();

        // The old session is gone. Start a new one just to carry the message.
        Session::start();
        Session::flash('success', 'You have been logged out.');
        Response::redirect('/');
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    }
}
