<?php
/**
 * Router.php — maps the incoming request path to a $_GET['action'].
 *
 * This is the pretty-URL layer. When no rewrite layer (Apache .htaccess or
 * router.php) has already populated $_GET, we parse /thread/N-slug,
 * /category/N-slug, /u/user, /admin/... from the request path so the correct
 * page is rendered. It mutates the global $_GET in place and returns it.
 */

namespace Bulletin;

class Router
{
    /**
     * Resolve the current request path into action parameters.
     * Returns the populated $_GET array.
     */
    public static function resolve(): array
    {
        if (isset($_GET['action'])) {
            return $_GET;
        }

        $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $reqPath = ltrim($reqPath, '/');
        $base = trim(base_url(), '/');
        if ($base !== '' && str_starts_with($reqPath, $base . '/')) {
            $reqPath = substr($reqPath, strlen($base) + 1);
        }

        $map = [
            '#^thread/([0-9]+)(?:-[^/]+)?$#'            => fn($m) => ['action' => 'thread', 'id' => $m[1]],
            '#^category/([0-9]+)(?:-[^/]+)?$#'          => fn($m) => ['action' => 'category', 'id' => $m[1]],
            '#^u/([^/]+)$#'                              => fn($m) => ['action' => 'profile', 'user' => urldecode($m[1])],
            '#^edit-post/([0-9]+)$#'                    => fn($m) => ['action' => 'edit_post', 'id' => (int)$m[1]],
            '#^delete-post/([0-9]+)$#'                  => fn($m) => ['action' => 'delete_post', 'id' => (int)$m[1]],
            '#^edit-thread/([0-9]+)$#'                  => fn($m) => ['action' => 'edit_thread', 'id' => (int)$m[1]],
            '#^delete-thread/([0-9]+)$#'                => fn($m) => ['action' => 'delete_thread', 'id' => (int)$m[1]],
            '#^download/([0-9]+)$#'                     => fn($m) => ['action' => 'download', 'id' => (int)$m[1]],
            '#^admin$#'                                  => fn()   => ['action' => 'admin'],
            '#^admin/moderation$#'                       => fn()   => ['action' => 'admin_moderation'],
            '#^admin/categories$#'                       => fn()   => ['action' => 'admin_categories'],
            '#^admin/users$#'                            => fn()   => ['action' => 'admin_users'],
            '#^admin/users/([0-9]+)/edit$#'             => fn($m) => ['action' => 'admin_user_edit', 'id' => (int)$m[1]],
            '#^admin/create-user$#'                      => fn()   => ['action' => 'admin_create_user'],
            '#^admin/settings$#'                         => fn()   => ['action' => 'admin_settings'],
            '#^admin/langs$#'                           => fn()   => ['action' => 'admin_langs'],
            '#^admin/catalog$#'                         => fn()   => ['action' => 'admin_catalog'],
            '#^admin/plugins$#'                         => fn()   => ['action' => 'admin_plugins'],
            '#^admin/themes$#'                          => fn()   => ['action' => 'admin_themes'],
            '#^admin/diagnostics$#'                     => fn()   => ['action' => 'admin_diagnostics'],
            '#^admin/updates$#'                         => fn()   => ['action' => 'admin_updates'],
            '#^admin/roles$#'                           => fn()   => ['action' => 'admin_roles'],
            '#^admin/roles-action$#'                    => fn()   => ['action' => 'admin_roles_action'],
            '#^admin/moderate$#'                        => fn()   => ['action' => 'moderate'],
            '#^admin/delete-category$#'                 => fn()   => ['action' => 'delete_category'],
            '#^admin/update-category-order$#'            => fn()   => ['action' => 'update_category_order'],
            '#^admin/delete-user$#'                     => fn()   => ['action' => 'delete_user'],
            '#^admin/ban-user$#'                        => fn()   => ['action' => 'ban_user'],
            '#^admin/unban-user$#'                      => fn()   => ['action' => 'unban_user'],
            '#^edit-profile$#'                          => fn()   => ['action' => 'edit_profile'],
            '#^remove-avatar$#'                         => fn()   => ['action' => 'remove_avatar'],
            '#^forgot-password$#'                       => fn()   => ['action' => 'forgot_password'],
            '#^reset-password$#'                        => fn()   => ['action' => 'reset_password'],
            '#^verify-email$#'                          => fn()   => ['action' => 'verify_email'],
            '#^new-thread$#'                            => fn()   => ['action' => 'new_thread'],
            '#^messages$#'                              => fn()   => ['action' => 'messages'],
            '#^notifications$#'                        => fn()   => ['action' => 'notifications'],
        ];

        foreach ($map as $pattern => $apply) {
            if (preg_match($pattern, $reqPath, $m)) {
                $_GET = array_merge($_GET, $apply($m));
                return $_GET;
            }
        }

        if ($reqPath === '' || $reqPath === 'index.php') {
            $_GET['action'] = 'home';
        } elseif (preg_match('#^[^/]+$#', $reqPath, $m)) {
            $_GET['action'] = $m[0];
        }

        return $_GET;
    }
}