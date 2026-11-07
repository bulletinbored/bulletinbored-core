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
            if ($method !== 'GET') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_thread_view();
        case 'new_thread':
            return handle_new_thread($method);
        case 'reply':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_reply_post();
        case 'edit_post':
            return handle_edit_post($method);
        case 'delete_post':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_delete_post();
        case 'edit_thread':
            return handle_edit_thread($method);
        case 'delete_thread':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_delete_thread();
        case 'watch':
            if (!is_logged_in()) {
                throw new \Bulletin\UnauthorizedException();
            }
            return handle_watch();
        case 'unwatch':
            if (!is_logged_in()) {
                throw new \Bulletin\UnauthorizedException();
            }
            return handle_unwatch();
        case 'upload_image':
            if ($method !== 'POST') {
                throw new \Bulletin\MethodNotAllowedException('Method not allowed');
            }
            return handle_upload_image();
        default:
            throw new \Bulletin\NotFoundException('Post action not found');
    }
}
