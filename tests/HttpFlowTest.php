<?php

declare(strict_types=1);

/**
 * End-to-end checks. A real PHP built-in server runs the app against a separate
 * test database, and the checks send real HTTP requests to it.
 */
function runHttpFlowTests(string $root): void
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') === false ? '' : (string) getenv('DB_PASS');
    $testDb = getenv('DB_NAME_TEST') ?: 'nested_forum_test';

    // --- Fresh test database -------------------------------------------------
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException) {
        // Fine when the database already exists and the user cannot create databases.
    }
    $pdo->exec("USE `$testDb`");
    $pdo->exec((string) file_get_contents($root . '/database/schema.sql'));

    $count = static function (string $table, string $where = '1=1', array $params = []) use ($pdo): int {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE $where");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    };

    $server = new TestServer($root, [
        'DB_HOST' => $host, 'DB_PORT' => (string) $port, 'DB_USER' => $user,
        'DB_PASS' => $pass, 'DB_NAME' => $testDb,
    ]);
    $server->start();

    try {
        $base = $server->base;

        // =====================================================================
        T::section('Reading as a guest and ID handling');
        $guest = new Browser($base);

        $page = $guest->get('/discussions/1');
        T::same('GET /discussions/1 returns 200', 200, $page['status']);
        T::same('all 8 seeded comments are on the page', 8, preg_match_all('/<article class="comment /', $page['body']));
        T::contains('the depth-4 branch is labelled', $page['body'], 'Depth 4');
        T::notContains('a guest sees no reply form', $page['body'], '<textarea');
        T::contains('a guest is asked to log in', $page['body'], 'you need an account to post');

        // =====================================================================
        T::section('Collapse and expand markup (optional challenge)');
        $x = Page::xpath($page['body']);

        T::same('collapse.js is linked once, with defer', 1, $x->query('//script[@src="/assets/collapse.js"][@defer]')->length);
        T::same('the page has no inline script', 0, $x->query('//script[not(@src)]')->length);
        $csp = $page['headers']['content-security-policy'][0] ?? '';
        T::contains('CSP allows scripts from this site only', $csp, "script-src 'self'");
        T::notContains('CSP does not allow inline scripts', $csp, 'unsafe-inline');

        $buttons = $x->query('//button[contains(@class, "toggle-replies")]');
        T::same('5 of the 8 comments have replies, so there are 5 buttons', 5, $buttons->length);

        $badButtons = [];
        foreach ($buttons as $button) {
            $target = $button->getAttribute('aria-controls');
            $label = trim((string) preg_replace('/\s+/', ' ', $button->textContent));
            $ok = $button->getAttribute('type') === 'button'
                && $button->hasAttribute('hidden')
                && $button->getAttribute('aria-expanded') === 'true'
                && $label === 'Hide replies'
                && $x->query('//div[@id="' . $target . '"]')->length === 1
                && $x->query('//div[@id="' . $target . '"]//button[@aria-controls="' . $target . '"]')->length === 0;
            if (!$ok) {
                $badButtons[] = $target;
            }
        }
        T::same('each button is type=button, starts hidden and expanded, says "Hide replies" and controls one branch outside itself', [], $badButtons);

        $seen = [];
        $duplicates = [];
        foreach ($x->query('//*[@id]') as $node) {
            $id = $node->getAttribute('id');
            if (isset($seen[$id])) {
                $duplicates[] = $id;
            }
            $seen[$id] = true;
        }
        T::same('every id on the page is unique', [], $duplicates);

        T::same('comments without replies (5, 6, 8) get no button', 0, $x->query('//article[@id="comment-5" or @id="comment-6" or @id="comment-8"]//button[contains(@class, "toggle-replies")]')->length);
        T::same('comments without replies get no branch container', 0, $x->query('//div[@id="replies-5" or @id="replies-6" or @id="replies-8"]')->length);

        T::same('branch of comment 1 holds exactly its descendants (2, 3, 4, 5, 6)', ['comment-2', 'comment-3', 'comment-4', 'comment-5', 'comment-6'], Page::commentsInBranch($x, 'replies-1'));
        T::same('comment 7 is outside the branch of comment 1, so it stays visible', 0, $x->query('//div[@id="replies-1"]//article[@id="comment-7"]')->length);
        T::same('branch of comment 2 holds 3, 4, 5 but not its sibling 6', ['comment-3', 'comment-4', 'comment-5'], Page::commentsInBranch($x, 'replies-2'));
        T::same('branch of comment 7 holds only comment 8', ['comment-8'], Page::commentsInBranch($x, 'replies-7'));
        T::same('every depth label equals the number of enclosing branches', [], Page::depthMismatches($x));

        $js = $guest->get('/assets/collapse.js');
        T::same('collapse.js is served', 200, $js['status']);
        T::contains('collapse.js updates aria-expanded', $js['body'], 'aria-expanded');
        T::notContains('collapse.js does not build HTML (no innerHTML)', $js['body'], 'innerHTML');
        T::notContains('collapse.js does not use eval', $js['body'], 'eval(');

        T::same('malformed ID "abc" -> 400', 400, $guest->get('/discussions/abc')['status']);
        T::same('malformed ID "0" -> 400', 400, $guest->get('/discussions/0')['status']);
        T::same('malformed ID "1abc" -> 400', 400, $guest->get('/discussions/1abc')['status']);
        T::same('malformed ID "-5" -> 400', 400, $guest->get('/discussions/-5')['status']);
        T::same('malformed ID "007" -> 400', 400, $guest->get('/discussions/007')['status']);
        T::same('huge ID -> 400', 400, $guest->get('/discussions/99999999999999999999')['status']);
        T::same('valid ID that does not exist -> 404', 404, $guest->get('/discussions/999')['status']);
        T::same('unknown URL -> 404', 404, $guest->get('/no-such-page')['status']);
        T::same('GET /logout is not a route -> 404', 404, $guest->get('/logout')['status']);

        // A brand-new visitor: the first response is the one that sets the session cookie.
        $cookie = (new Browser($base))->get('/login')['headers']['set-cookie'][0] ?? '';
        T::contains('session cookie is HttpOnly', $cookie, 'HttpOnly');
        T::contains('session cookie is SameSite=Lax', $cookie, 'SameSite=Lax');

        // =====================================================================
        T::section('Guest cannot post (server-side check)');
        $before = $count('comments');
        $guestToken = $guest->token('/login');   // guests have no form on the thread page, so take the token from the login form

        $r = $guest->post('/discussions/1/comments', ['_token' => $guestToken, 'body' => 'guest top-level']);
        T::same('guest POST top-level comment -> 401', 401, $r['status']);
        $r = $guest->post('/discussions/1/comments/5/replies', ['_token' => $guestToken, 'body' => 'guest reply']);
        T::same('guest POST reply -> 401', 401, $r['status']);
        $r = $guest->post('/discussions/1/comments/5/replies', ['body' => 'guest reply, no token']);
        T::same('guest POST without a token -> 401', 401, $r['status']);
        T::same('no row was created by the guest', $before, $count('comments'));

        // =====================================================================
        T::section('Registration');
        $reg = new Browser($base);
        $regToken = $reg->token('/register');
        $good = ['_token' => $regToken, 'username' => 'student_demo', 'email' => 'Student.Demo@example.com',
            'password' => 'Demo-pass-2026!', 'password_confirm' => 'Demo-pass-2026!'];

        T::same('register without a token -> 403', 403, $reg->post('/register', array_diff_key($good, ['_token' => 1]))['status']);
        $r = $reg->post('/register', ['password' => 'short', 'password_confirm' => 'short'] + $good);
        T::same('short password -> 422', 422, $r['status']);
        T::contains('short password message', $r['body'], 'Password must be 8 to 72 characters');
        $r = $reg->post('/register', ['password_confirm' => 'Different-pass-1'] + $good);
        T::contains('password mismatch message', $r['body'], 'passwords do not match');
        $r = $reg->post('/register', ['email' => 'not-an-email'] + $good);
        T::contains('bad email message', $r['body'], 'valid email address');
        $r = $reg->post('/register', ['username' => 'a b'] + $good);
        T::contains('bad username message', $r['body'], 'Username must be 3 to 30');
        $r = $reg->post('/register', ['password' => str_repeat('a', 73), 'password_confirm' => str_repeat('a', 73)] + $good);
        T::contains('password over 72 bytes is refused', $r['body'], 'Password must be 8 to 72 characters');
        $r = $reg->post('/register', ['username' => 'MIKA'] + $good);
        T::contains('duplicate username (any letter case)', $r['body'], 'username is already taken');
        $r = $reg->post('/register', ['email' => 'MIKA@example.com'] + $good);
        T::contains('duplicate email (any letter case)', $r['body'], 'email already exists');
        T::notContains('password is never echoed back into the form', $r['body'], 'Demo-pass-2026!');
        T::same('no account was created by the failed attempts', 0, $count('users', 'username = ?', ['student_demo']));

        $r = $reg->post('/register', $good);
        T::same('valid registration -> 302', 302, $r['status']);
        T::same('registration redirects to /login', '/login', $r['location']);
        T::same('account row exists', 1, $count('users', 'username = ?', ['student_demo']));

        $stmt = $pdo->prepare('SELECT email, password_hash FROM users WHERE username = ?');
        $stmt->execute(['student_demo']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        T::same('email is stored in lower case', 'student.demo@example.com', $row['email']);
        T::check('password is stored as a hash, not plain text', $row['password_hash'] !== 'Demo-pass-2026!' && password_get_info($row['password_hash'])['algo'] !== null);
        T::check('stored hash verifies with password_verify', password_verify('Demo-pass-2026!', $row['password_hash']));
        $userId = (int) $pdo->query("SELECT id FROM users WHERE username = 'student_demo'")->fetchColumn();

        // =====================================================================
        T::section('Login and logout');
        $me = new Browser($base);
        $loginToken = $me->token('/login');
        $sidBefore = $me->sessionId();

        T::same('login without a token -> 403', 403, $me->post('/login', ['login' => 'student_demo', 'password' => 'Demo-pass-2026!'])['status']);
        $r = $me->post('/login', ['_token' => $loginToken, 'login' => 'student_demo', 'password' => 'wrong-password']);
        T::same('wrong password -> 401', 401, $r['status']);
        T::contains('wrong password message', $r['body'], 'Incorrect username or password.');
        $r2 = $me->post('/login', ['_token' => $loginToken, 'login' => 'nobody_here', 'password' => 'whatever123']);
        T::contains('unknown user gets the same message', $r2['body'], 'Incorrect username or password.');
        $r = $me->post('/login', ['_token' => $loginToken, 'login' => 'mika', 'password' => '']);
        T::same('seed user with a NULL hash cannot log in (empty password)', 401, $r['status']);
        $r = $me->post('/login', ['_token' => $loginToken, 'login' => 'mika', 'password' => 'anything-at-all']);
        T::same('seed user with a NULL hash cannot log in (any password)', 401, $r['status']);

        $r = $me->post('/login', ['_token' => $loginToken, 'login' => 'student_demo', 'password' => 'Demo-pass-2026!']);
        T::same('correct login -> 302', 302, $r['status']);
        T::same('login redirects to /', '/', $r['location']);
        T::check('session ID is regenerated after login', $sidBefore !== null && $me->sessionId() !== null && $sidBefore !== $me->sessionId());
        $home = $me->get('/');
        T::contains('header shows who is logged in', $home['body'], 'Logged in as <strong>student_demo</strong>');
        T::same('logged-in user is sent away from /login', 302, $me->get('/login')['status']);

        // =====================================================================
        T::section('Authenticated posting: depth-5 reply and a second top-level comment');
        $token = $me->token('/discussions/1');
        $before = $count('comments');

        $r = $me->post('/discussions/1/comments/5/replies', [
            '_token' => $token, 'body' => 'Depth five reply from the test account.',
            'user_id' => '1', 'parent_id' => '1', 'discussion_id' => '2',   // tampered fields must be ignored
        ]);
        T::same('reply to comment 5 -> 302', 302, $r['status']);
        preg_match('/#comment-(\d+)$/', (string) $r['location'], $m);
        $replyId = (int) ($m[1] ?? 0);
        T::check('redirect points at the new comment', $replyId > 10, (string) $r['location']);

        $stmt = $pdo->prepare('SELECT discussion_id, parent_id, user_id FROM comments WHERE id = ?');
        $stmt->execute([$replyId]);
        $saved = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        T::same('user_id comes from the session, not the form', $userId, (int) ($saved['user_id'] ?? 0));
        T::same('parent_id comes from the URL, not the form', 5, (int) ($saved['parent_id'] ?? 0));
        T::same('discussion_id comes from the URL, not the form', 1, (int) ($saved['discussion_id'] ?? 0));

        $page = $me->get('/discussions/1');
        T::matches('new reply is drawn at depth 5 with indent-5', '/<article class="comment indent-5" id="comment-' . $replyId . '">.*?Depth 5/s', $page['body']);
        T::contains('success message shows once after the redirect', $page['body'], 'Your reply was posted.');
        T::notContains('success message is gone on the next load', $me->get('/discussions/1')['body'], 'Your reply was posted.');

        $r = $me->post('/discussions/1/comments', ['_token' => $token, 'body' => "Second top-level comment.\nWith a second line."]);
        T::same('top-level comment -> 302', 302, $r['status']);
        preg_match('/#comment-(\d+)$/', (string) $r['location'], $m);
        $topId = (int) ($m[1] ?? 0);
        $stmt->execute([$topId]);
        $saved = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        T::check('top-level comment has a NULL parent', array_key_exists('parent_id', $saved) && $saved['parent_id'] === null);
        T::matches('top-level comment is drawn at depth 0', '/<article class="comment indent-0" id="comment-' . $topId . '">.*?Depth 0/s', $me->get('/discussions/1')['body']);
        T::same('two rows were added in total', $before + 2, $count('comments'));

        // =====================================================================
        T::section('CSRF protection');
        $before = $count('comments');
        T::same('missing token -> 403', 403, $me->post('/discussions/1/comments', ['body' => 'no token'])['status']);
        T::same('wrong token -> 403', 403, $me->post('/discussions/1/comments', ['_token' => str_repeat('0', 64), 'body' => 'bad token'])['status']);
        T::same("another session's token -> 403", 403, $me->post('/discussions/1/comments', ['_token' => $guestToken, 'body' => 'stolen token'])['status']);
        T::same('token sent as an array -> 403', 403, $me->post('/discussions/1/comments', ['_token' => [$token], 'body' => 'array token'])['status']);
        T::same('login form also needs a token', 403, (new Browser($base))->post('/login', ['login' => 'x', 'password' => 'y'])['status']);
        T::same('logout without a token -> 403', 403, $me->post('/logout')['status']);
        T::same('still logged in after the rejected logout', 200, $me->get('/')['status']);
        T::same('no row was created by any rejected request', $before, $count('comments'));

        // =====================================================================
        T::section('Parent, discussion and input validation');
        $before = $count('comments');
        $token = $me->token('/discussions/1');

        $r = $me->post('/discussions/2/comments/1/replies', ['_token' => $token, 'body' => 'cross-discussion parent']);
        T::same('parent from another discussion -> 422', 422, $r['status']);
        T::same('parent that does not exist -> 422', 422, $me->post('/discussions/1/comments/9999/replies', ['_token' => $token, 'body' => 'x'])['status']);
        T::same('missing discussion -> 404', 404, $me->post('/discussions/9999/comments', ['_token' => $token, 'body' => 'x'])['status']);
        T::same('malformed discussion ID -> 400', 400, $me->post('/discussions/abc/comments', ['_token' => $token, 'body' => 'x'])['status']);
        T::same('malformed parent ID -> 400', 400, $me->post('/discussions/1/comments/abc/replies', ['_token' => $token, 'body' => 'x'])['status']);

        $r = $me->post('/discussions/1/comments', ['_token' => $token, 'body' => "  \n  "]);
        T::same('empty comment -> redirect back', 302, $r['status']);
        T::contains('empty comment message', $me->get('/discussions/1')['body'], 'Write something before you post.');
        $r = $me->post('/discussions/1/comments', ['_token' => $token, 'body' => str_repeat('a', 2001)]);
        T::contains('2001 characters is too long', $me->get('/discussions/1')['body'], 'too long');
        $r = $me->post('/discussions/1/comments', ['_token' => $token, 'body' => ['not', 'text']]);
        T::contains('body sent as an array is refused', $me->get('/discussions/1')['body'], 'Write something before you post.');
        T::same('no row was created by any invalid request', $before, $count('comments'));

        $r = $me->post('/discussions/1/comments', ['_token' => $token, 'body' => str_repeat('b', 2000)]);
        T::same('exactly 2000 characters is accepted', 302, $r['status']);

        // =====================================================================
        T::section('HTML escaping');
        $evil = '<script>alert("xss")</script><img src=x onerror=alert(1)> & "quotes" \'single\'';
        $r = $me->post('/discussions/1/comments', ['_token' => $token, 'body' => $evil]);
        T::same('script-like comment is accepted as plain text', 302, $r['status']);
        preg_match('/#comment-(\d+)$/', (string) $r['location'], $m);
        $evilId = (int) ($m[1] ?? 0);
        $stored = $pdo->query("SELECT body FROM comments WHERE id = $evilId")->fetchColumn();
        T::same('database keeps exactly what was typed', $evil, $stored);

        $html = $me->get('/discussions/1')['body'];
        T::notContains('no raw <script> tag in the page', $html, '<script>alert("xss")');
        T::notContains('no raw <img> tag from the comment', $html, '<img src=x');
        T::contains('angle brackets and quotes are escaped', $html, '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt; &amp; &quot;quotes&quot; &#039;single&#039;');

        // =====================================================================
        T::section('Depth limit and indentation cap');
        $parent = $replyId;     // displayed depth 5
        $lastOk = 5;
        for ($depth = 6; $depth <= 12; $depth++) {
            $r = $me->post('/discussions/1/comments/' . $parent . '/replies', ['_token' => $token, 'body' => 'level ' . $depth]);
            preg_match('/#comment-(\d+)$/', (string) $r['location'], $m);
            if ($r['status'] === 302 && isset($m[1])) {
                $parent = (int) $m[1];
                $lastOk = $depth;
            }
        }
        T::same('replies are accepted down to depth 12', 12, $lastOk);

        $before = $count('comments');
        $r = $me->post('/discussions/1/comments/' . $parent . '/replies', ['_token' => $token, 'body' => 'one level too deep']);
        T::same('depth 13 is refused (redirect back)', 302, $r['status']);
        T::contains('depth limit message', $me->get('/discussions/1')['body'], 'Replies can only go 12 levels deep');
        T::same('no row was created for depth 13', $before, $count('comments'));

        $html = $me->get('/discussions/1')['body'];
        T::contains('depth 12 label is accurate', $html, 'Depth 12');
        T::same('indentation stops at indent-8 (depths 8 to 12)', 5, preg_match_all('/class="comment indent-8"/', $html));
        T::notContains('there is no indent-9 class', $html, 'indent-9');
        $x = Page::xpath($html);
        T::same('in the deep thread every depth label still equals its nesting', [], Page::depthMismatches($x));
        T::same('the depth 12 comment sits inside 12 branches', 12, $x->query('//article[.//span[@class="depth-label"][text()="Depth 12"]]/ancestor::div[contains(@class, "reply-branch")]')->length);

        // =====================================================================
        T::section('Database rules');
        try {
            $pdo->exec("INSERT INTO comments (discussion_id, parent_id, user_id, body) VALUES (2, 1, 1, 'cross-discussion parent, direct SQL')");
            T::check('composite foreign key rejects a parent from another discussion', false, 'insert succeeded');
        } catch (PDOException $e) {
            T::same('composite foreign key rejects a parent from another discussion (SQLSTATE 23000)', '23000', (string) $e->getCode());
        }
        $stmt = $pdo->query('SELECT COUNT(*) FROM users WHERE password_hash IS NULL');
        T::same('the 5 fictional seed users have no password hash', 5, (int) $stmt->fetchColumn());

        // =====================================================================
        T::section('Logout');
        $token = $me->token('/discussions/1');
        $r = $me->post('/logout', ['_token' => $token]);
        T::same('logout -> 302', 302, $r['status']);
        T::contains('logout message', $me->get('/')['body'], 'You have been logged out.');

        $before = $count('comments');
        $r = $me->post('/discussions/1/comments', ['_token' => $token, 'body' => 'after logout']);
        T::same('posting after logout -> 401', 401, $r['status']);
        T::same('no row was created after logout', $before, $count('comments'));
    } finally {
        $server->stop();
    }
}
