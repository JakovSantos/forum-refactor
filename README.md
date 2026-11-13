# Nested Forum Lab (forum-refactor)

A small forum where replies are shown as a tree. Anyone can read a discussion, but only a logged-in user can post. The code is split into a router, controllers, models and Mustache views, as asked in the lab sheet (section 8.1).

## Libraries used

| Job | Library | Notes |
|---|---|---|
| Router | `bramus/router` | Maps URLs to controller methods in `config/routes.php` |
| Models / data access | `illuminate/database` (Eloquent) | Eloquent runs on PDO and sends every value as a bound parameter |
| Templates | `mustache/mustache` | `{{ }}` tags escape HTML, no `{{{ }}}` is used anywhere |

These are the three lecture libraries. Nothing was substituted.

## Requirements

- PHP 8.2 or newer with `pdo_mysql` and `mbstring`, plus `curl` and `dom` for the tests (both come with XAMPP)
- MySQL 8 or a compatible MariaDB (tested on MariaDB 10.11)
- Composer
- A text editor. A web server is not needed, PHP's built-in server is enough.

## Setup

```bash
# 1. Get the code and install the packages
git clone <your-repository-url> forum-refactor
cd forum-refactor
composer install

# 2. Create the database and load the schema + seed data
mysql -u root -p -e "CREATE DATABASE nested_forum CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p nested_forum < database/schema.sql

# 3. Run the site
php -S 127.0.0.1:8000 -t public public/index.php
```

Then open <http://127.0.0.1:8000/> and click the first discussion (`/discussions/1`).

On Windows with XAMPP, use `C:\xampp\php\php.exe` in place of `php`, and import `database/schema.sql` with phpMyAdmin into a database called `nested_forum` if you prefer.

### Database login

`config/database.php` reads these environment variables and falls back to the XAMPP defaults (user `root`, empty password):

`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`

If your MySQL user has a password, copy `config/database.local.example.php` to `config/database.local.php` and fill it in. That file is ignored by Git, so no credentials are committed.

### Accounts

The five users in the seed data (`mika`, `renz`, `tala`, `bong`, `ines`) are fictional and have no password, so nobody can log in as them. To post, open `/register`, make your own account, then log in.

## Routes

| Method | URL | Controller method | Who |
|---|---|---|---|
| GET | `/` | `DiscussionController@index` | everyone |
| GET | `/discussions/{id}` | `DiscussionController@show` | everyone |
| GET | `/register` | `AuthController@showRegister` | guests |
| POST | `/register` | `AuthController@register` | guests |
| GET | `/login` | `AuthController@showLogin` | guests |
| POST | `/login` | `AuthController@login` | guests |
| POST | `/logout` | `AuthController@logout` | anyone with a CSRF token |
| POST | `/discussions/{id}/comments` | `CommentController@store` | logged-in users |
| POST | `/discussions/{id}/comments/{parentId}/replies` | `CommentController@reply` | logged-in users |

Logout is POST only, so `GET /logout` is a normal 404.

### Responses

| Situation | Response |
|---|---|
| ID in the URL is not a positive whole number (`abc`, `0`, `-5`, `007`, a huge number) | **400** Bad Request, decided by the controller |
| ID is valid but the discussion does not exist | **404** Not Found |
| URL that matches no route | **404** Not Found |
| Guest sends a POST that needs a login | **401** Unauthorized, nothing saved |
| Missing or wrong CSRF token | **403** Forbidden, nothing saved |
| Parent comment is missing or belongs to another discussion | **422**, nothing saved |
| Empty or too long comment, or reply deeper than the limit | redirect back to the discussion with a message, nothing saved |
| Successful registration, login, logout, comment or reply | **302** redirect |

Malformed IDs get 400 on purpose. The route pattern accepts any text for the ID and the controller validates it, so the response is the same for every kind of bad ID instead of depending on the router.

## How a request moves through the code

```
Browser
  -> public/index.php            front controller: boots the app and runs the router
  -> config/routes.php           Bramus Router picks the controller method
  -> Controller                  checks the ID, login and CSRF token, prepares view data
  -> Model (Eloquent)            reads or writes through PDO with bound parameters
  -> Services/CommentTree        groups comments by parent_id and works out depth
  -> Mustache view               builds the HTML, escaping every value
  -> Browser
```

Example, `GET /discussions/1`: the router calls `DiscussionController::show('1')`. The controller validates the ID, asks `Discussion` and `Comment` for the rows, passes the comments to `CommentTree`, then hands the finished arrays to `View::render('discussion', ...)`. The controller never writes SQL or HTML.

## Data model

```
users 1 ---- 0..* discussions 1 ---- 0..* comments
users 1 ------------------------------ 0..* comments (author)
comments.parent_id ----> comments.id  (replies to replies)
```

**`discussion_id` and `parent_id`.** `discussion_id` says which thread a comment belongs to. `parent_id` says which comment it answers. When `parent_id` is `NULL`, the comment answers the discussion itself, so it is top-level (depth 0). A reply to a reply has the earlier reply as its parent, and that is how nesting happens. Siblings are comments with the same parent.

A composite foreign key `(discussion_id, parent_id) -> comments(discussion_id, id)` makes MySQL refuse a reply whose parent is in a different discussion. The key does not stop cycles such as `2 -> 3 -> 2`. The app avoids them because `parent_id` is never updated after insert and a new comment can only point at a row that already exists. The depth check in the write handler also refuses a chain that loops or is too long.

**Depth is calculated, not stored.** The tree is built from the rows each time the page is shown.

## Nested tree display

- Top-level comments are depth 0, every reply level adds 1, and the label shows the real number (`Depth 4`).
- Indentation stops growing at depth 8 (`indent-8` in `public/assets/style.css`). Deeper comments keep their accurate depth label.
- Traversal limit: the tree is walked without recursion, skips a comment it has already visited, and stops at depth 50 or 2000 comments. Comments that cannot be reached from a top-level comment are not shown, and the page says how many were left out.
- Write limit: a reply can be at most 12 levels deep.

## Collapse and expand replies (optional challenge, section 9)

Every comment that has replies gets a **Hide replies** / **Show replies** button with an arrow. Replies are open by default.

How it works:

- `CommentTree::nest()` puts each reply inside its parent and adds `has_children`. The view `partials/comment.mustache` draws the comment, then its replies inside `<div class="reply-branch" id="replies-{id}">`. The branch comes right after the comment, so hiding it hides every level below that comment and nothing else.
- The button is a real `<button type="button">` with `aria-controls="replies-{id}"` and `aria-expanded`. Its label changes between "Hide replies" and "Show replies", and the branch is hidden with the `hidden` attribute.
- The buttons are written by the server with the `hidden` attribute. `public/assets/collapse.js` (a separate file, loaded with `defer`) shows them and attaches the click handlers. If JavaScript is off or the file is blocked, the whole thread stays open and no button appears.
- The script only reads the button's `aria-controls` value. No comment text goes into JavaScript, and the page still has no inline script.
- Because the page now loads one script from the same site, the Content-Security-Policy says `script-src 'self'` (inline scripts are still blocked).
- Depth labels and indentation do not change when a branch is closed and reopened. Closing a branch does not change the other branches.

Manual check: collapse the first comment and confirm its replies disappear while the next top-level comment stays. Reopen it. Collapse a reply in the middle of the thread and confirm its sibling stays visible. Use Tab and Enter or Space on the buttons. Then turn JavaScript off (or block `collapse.js`) and confirm all replies are shown with no buttons.

## Security notes

- Passwords are stored with `password_hash()` and checked with `password_verify()`. A `NULL` or empty hash can never log in.
- The session ID is regenerated after login. The session cookie is `HttpOnly` and `SameSite=Lax`, and it gets `Secure` when the site runs over HTTPS (or when `APP_SECURE_COOKIES=1`).
- Every state-changing form (register, login, logout, comment, reply) is a POST with a session-backed CSRF token.
- Login is checked on the server for every write. `user_id` comes from the session, never from the form. `discussion_id` and `parent_id` come from the URL, so form fields with those names are ignored.
- The parent must belong to the same discussion, comment text must be valid UTF-8 and 1 to 2000 characters, and reply depth is limited.
- All output goes through Mustache's escaping. Responses also send a Content-Security-Policy that blocks inline scripts and allows only scripts from this site.
- The error handler never logs messages, passwords or tokens, only the exception class, code and location.

## Tests

```bash
php tests/run.php
```

The unit tests need nothing else. The HTTP tests start PHP's built-in server on a free port, reset a **separate** database called `nested_forum_test` from `database/schema.sql`, and send real requests to it. Your normal `nested_forum` data is not touched. Set `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS` and `DB_NAME_TEST` if your setup differs from the XAMPP defaults. The MySQL user must be able to create or use that test database.

What is covered:

- tree order, depth, indentation cap, cycles, self-parents, orphans and traversal limits
- nesting of replies (`nest()`), the collapse buttons in the page (type, `aria-controls`, `aria-expanded`, starting hidden, unique IDs), which comments each branch holds, and that every depth label matches its nesting
- reading as a guest, bad and missing IDs
- guest POSTs rejected, with no row created
- registration rules, duplicates, hashed passwords
- wrong password, unknown user, seed users with a `NULL` hash, session ID change after login
- a depth-5 reply and a second top-level comment, with `user_id` taken from the session
- missing, wrong and borrowed CSRF tokens
- cross-discussion parent, missing discussion, empty, too long and non-text comments
- an HTML or script-like comment shown as escaped text
- depth limit and the database foreign key
- logout, then a rejected POST

### Manual checks

1. Register at `/register`, log in at `/login`.
2. Open `/discussions/1`, use **Reply** on the deepest comment (depth 4) to add a depth-5 reply, then add a second top-level comment at the bottom.
3. Log out and try to post: the forms are gone, and a direct `curl -X POST` gets 401.
4. Visit `/discussions/abc` (400) and `/discussions/999` (404).
5. In the browser developer tools, change the hidden `_token` value in a form and submit it (403).
6. Post `<script>alert(1)</script>` as a comment and check that it appears as plain text.

## Production web servers (explanation only)

The local test needs no rewrite rules because `php -S ... public/index.php` sends every request to the front controller. On a real web server, point the document root at `public/` and send every URL that is not a real file to `index.php`.

Apache (`public/.htaccess`):

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

nginx:

```nginx
location / {
    try_files $uri /index.php?$query_string;
}
```

If the app is served from a sub folder, set `APP_BASE_PATH`, for example `/forum-refactor/public`.

## Limitations

- All comments of a discussion are loaded into memory and drawn at once. That is fine for small lab data, not for a large public forum, which would need pagination or lazy loading.
- The database does not prevent cycles by itself. The app's rules do, and a cycle created by hand in SQL would not be shown.
- There is no rate limiting, account recovery, email verification, editing, deleting or moderation.
- Avatar upload and Gravatar are optional and are not part of this version. Every user shows the default avatar.
- Collapsed branches are not remembered. After a reload or after posting, every branch is open again.
- The `Secure` cookie flag only applies over HTTPS, so it is not visible in a plain local test.

## Project layout

```
forum-refactor/
  public/index.php            front controller
  public/assets/              style.css, collapse.js, default-avatar.svg
  app/bootstrap.php           autoload, config, Eloquent, session, security headers
  app/Controllers/            DiscussionController, AuthController, CommentController
  app/Models/                 User, Discussion, Comment (Eloquent)
  app/Services/CommentTree.php  groups by parent, calculates depth, nests replies
  app/Support/                Config, Database, Session, Csrf, Auth, Input, View, Response
  app/Http/                   HttpException, ErrorHandler
  app/Views/                  Mustache templates and partials
  config/                     app.php, database.php, routes.php
  database/schema.sql         tables, composite foreign key and seed data
  tests/                      run.php, unit tests, HTTP tests
  composer.json
  README.md
```
