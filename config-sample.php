<?php
/**
 * bulletinbored configuration sample
 *
 * Copy this file to config.php and fill in your values.
 * The web installer can also generate config.php for you automatically.
 */

$config['db_driver'] = 'sqlite'; // 'sqlite' or 'mysql'
$config['db_path'] = __DIR__ . '/data/database.sqlite'; // SQLite only
$config['db_host'] = 'localhost'; // MySQL only
$config['db_name'] = 'forum'; // MySQL only
$config['db_user'] = 'root'; // MySQL only
$config['db_pass'] = ''; // MySQL only
$config['site_name'] = 'bulletinbored';
$config['admin_user'] = 'admin';
$config['admin_pass'] = 'changeme123';
$config['mail_from'] = 'noreply@bulletinbored.local';
$config['mail_from_name'] = 'bulletinbored';
$config['mail_method'] = 'mail'; // 'mail' or 'smtp'
$config['theme'] = 'freshbored';
$config['default_lang'] = 'en';
$config['available_langs'] = array(
    'en',
);
$config['avatar_max_size'] = 2097152;
$config['avatar_allowed_types'] = array(
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
);
$config['base_url'] = ''; // e.g. '/forum' if installed in a subdirectory
$config['allow_registration'] = 0;
$config['maintenance_mode'] = 0;
$config['site_tagline'] = '';
$config['site_icon'] = '';
$config['timezone'] = 'UTC';
$config['date_format'] = 'Y-m-d';
$config['time_format'] = 'H:i';
$config['version'] = trim(file_get_contents(__DIR__.'/VERSION'));
$config['plugin_manifest'] = __DIR__.'/data/plugins.json';
$config['theme_manifest'] = __DIR__.'/data/themes.json';
$config['update_manifest'] = __DIR__.'/data/updates.json';
$config['update_server'] = '';
