<?php

declare(strict_types=1);

/**
 * Application settings. Nothing secret lives here, so this file is safe to commit.
 */
return [
    'name' => 'Nested Forum Lab',

    // Leave empty when the app is served from the web root (php -S does this).
    // Set APP_BASE_PATH=/forum-refactor/public if it is served from a sub folder.
    'base_path' => rtrim((string) (getenv('APP_BASE_PATH') ?: ''), '/'),

    // Set APP_SECURE_COOKIES=1 when the site runs behind HTTPS but PHP cannot tell.
    'secure_cookies' => getenv('APP_SECURE_COOKIES') === '1',

    'limits' => [
        // Longest comment, counted in characters.
        'comment_max_length' => 2000,

        // Deepest displayed depth the write handler will accept (top-level = 0).
        'max_reply_depth' => 12,

        // Indentation stops growing here. The "Depth N" label keeps counting.
        'max_indent_depth' => 8,

        // Traversal limits used when the tree is drawn. They protect the page
        // if the table ever contains data that did not come through the app.
        'tree_max_depth' => 50,
        'tree_max_nodes' => 2000,
    ],
];
