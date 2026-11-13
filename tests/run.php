<?php

declare(strict_types=1);

/**
 * Runs every check in this folder:
 *
 *   php tests/run.php
 *
 * Needs: the Composer packages installed, the PHP curl extension, and a MySQL or
 * MariaDB server. The HTTP checks use their own database (nested_forum_test by
 * default) and reset it on every run, so they never touch your normal data.
 *
 * Environment variables (all optional, XAMPP defaults are used otherwise):
 *   DB_HOST  DB_PORT  DB_USER  DB_PASS  DB_NAME_TEST
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';
require __DIR__ . '/support.php';
require __DIR__ . '/CommentTreeTest.php';
require __DIR__ . '/HttpFlowTest.php';

date_default_timezone_set('UTC');

runCommentTreeTests();
runHttpFlowTests($root);

exit(T::summary());
