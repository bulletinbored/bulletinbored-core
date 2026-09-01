<?php

/**
 * posts.php — dispatcher for post/thread actions.
 *
 * Actual handlers are organized into modules:
 * - posts-thread.php: thread view, watch, unwatch, image upload
 * - posts-new.php: new thread creation
 * - posts-edit.php: reply, edit post, delete post, edit thread, delete thread
 */

require_once __DIR__ . '/posts-thread.php';
require_once __DIR__ . '/posts-new.php';
require_once __DIR__ . '/posts-edit.php';

function handle_posts_action(string $action, string $method): \Bulletin\Response|bool
{
    switch ($action) {
        case 'thread':
            return $method === 'GET' ? handle_thread_view() : false;
        case 'new_thread':
            return handle_new_thread($method);
        case 'reply':
            return $method === 'POST' ? handle_reply_post() : false;
        case 'edit_post':
            return handle_edit_post($method);
        case 'delete_post':
            return $method === 'POST' ? handle_delete_post() : false;
        case 'edit_thread':
            return handle_edit_thread($method);
        case 'delete_thread':
            return $method === 'POST' ? handle_delete_thread() : false;
        case 'watch':
            return is_logged_in() ? handle_watch() : false;
        case 'unwatch':
            return is_logged_in() ? handle_unwatch() : false;
        case 'upload_image':
            return $method === 'POST' ? handle_upload_image() : false;
        default:
            return false;
    }
}
