-- Nested Forum Lab: schema and seed data
-- Works on MySQL 8 and MariaDB 10.x (InnoDB, utf8mb4).
--
-- Import into an existing, empty database, for example:
--   mysql -u root -p -e "CREATE DATABASE nested_forum CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   mysql -u root -p nested_forum < database/schema.sql
--
-- Warning: the three DROP TABLE lines make the file safe to re-run, but they
-- also erase any data already in these tables.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

DROP TABLE IF EXISTS comments;
DROP TABLE IF EXISTS discussions;
DROP TABLE IF EXISTS users;

-- ---------------------------------------------------------------------------
-- users
-- password_hash is nullable on purpose: the seed users below are fictional and
-- have no password, so they can never log in. Accounts created through the
-- registration form always get a real password_hash() value.
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(30)  NOT NULL,
    email         VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- discussions: a title and an opening message written by one user
-- ---------------------------------------------------------------------------
CREATE TABLE discussions (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    title      VARCHAR(200) NOT NULL,
    body       TEXT         NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_discussions_user (user_id),
    CONSTRAINT fk_discussions_user
        FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- comments
--   discussion_id : the thread the comment belongs to (always set)
--   parent_id     : the comment being answered, or NULL for a top-level comment
--
-- The composite foreign key (discussion_id, parent_id) points at
-- comments (discussion_id, id). That is how the database makes sure a parent
-- lives in the SAME discussion as its reply. When parent_id is NULL the key is
-- not checked, which is exactly what a top-level comment needs.
--
-- uq_comments_discussion_id exists only so the composite foreign key has a
-- matching index on (discussion_id, id) to point at.
--
-- This key does not stop cycles (for example 2 -> 3 -> 2). The application
-- never updates parent_id after insert, and a new comment can only point at a
-- row that already exists, so replies cannot form a cycle through the app.
-- ---------------------------------------------------------------------------
CREATE TABLE comments (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    discussion_id INT UNSIGNED  NOT NULL,
    parent_id     INT UNSIGNED  NULL,
    user_id       INT UNSIGNED  NOT NULL,
    body          VARCHAR(2000) NOT NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_comments_discussion_id (discussion_id, id),
    KEY idx_comments_parent (discussion_id, parent_id),
    KEY idx_comments_user (user_id),
    CONSTRAINT fk_comments_discussion
        FOREIGN KEY (discussion_id) REFERENCES discussions (id),
    CONSTRAINT fk_comments_user
        FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_comments_parent_same_discussion
        FOREIGN KEY (discussion_id, parent_id) REFERENCES comments (discussion_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Seed data (fictional people only, times are UTC)
-- ---------------------------------------------------------------------------
INSERT INTO users (id, username, email, password_hash, created_at) VALUES
    (1, 'mika', 'mika@example.com', NULL, '2026-11-01 08:00:00'),
    (2, 'renz', 'renz@example.com', NULL, '2026-11-01 08:05:00'),
    (3, 'tala', 'tala@example.com', NULL, '2026-11-01 08:10:00'),
    (4, 'bong', 'bong@example.com', NULL, '2026-11-01 08:15:00'),
    (5, 'ines', 'ines@example.com', NULL, '2026-11-01 08:20:00');

INSERT INTO discussions (id, user_id, title, body, created_at) VALUES
    (1, 4, 'How should I study for the PHP practical exam?',
        'My PHP practical exam is coming up. Share how you study so I can compare notes.',
        '2026-11-02 09:00:00'),
    (2, 3, 'Which database tool is easier for beginners?',
        'I am choosing between phpMyAdmin and the command line client. What do you use?',
        '2026-11-03 10:00:00');

-- Discussion 1: two top-level comments and one branch that reaches displayed depth 4
--   1 (depth 0)
--     2 (1)
--       3 (2)
--         4 (3)
--           5 (4)
--     6 (1)
--   7 (depth 0)
--     8 (1)
INSERT INTO comments (id, discussion_id, parent_id, user_id, body, created_at) VALUES
    (1, 1, NULL, 1, 'Start with the official PHP manual. The language reference is short and the examples are easy to follow.', '2026-11-02 09:10:00'),
    (2, 1, 1,    2, 'I agree. I type the examples myself instead of copying them, so I notice my own mistakes.',                '2026-11-02 09:20:00'),
    (3, 1, 2,    3, 'Then change one thing in each example and guess the output before you run it.',                            '2026-11-02 09:30:00'),
    (4, 1, 3,    2, 'Good tip. After that I join a few examples into a small program, like a guest book.',                      '2026-11-02 09:40:00'),
    (5, 1, 4,    5, 'A guest book is a nice first project. You can add replies later to practice parent IDs.',                  '2026-11-02 09:50:00'),
    (6, 1, 1,    4, 'Which part of the manual should I read first?',                                                            '2026-11-02 10:00:00'),
    (7, 1, NULL, 5, 'Practice SQL as well, since most PHP projects read from a database.',                                      '2026-11-02 10:10:00'),
    (8, 1, 7,    1, 'Try writing joins on your own tables, not only the sample ones.',                                          '2026-11-02 10:20:00');

-- Discussion 2 is small. It gives the tests a second discussion, so a reply
-- that points at a comment from another discussion can be rejected.
INSERT INTO comments (id, discussion_id, parent_id, user_id, body, created_at) VALUES
    (9,  2, NULL, 5, 'Use phpMyAdmin first, then move to the command line once you know the basic statements.', '2026-11-03 10:15:00'),
    (10, 2, 9,    2, 'I did the same thing. The command line felt less scary after that.',                      '2026-11-03 10:30:00');
