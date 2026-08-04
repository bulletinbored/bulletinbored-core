<?php
$config['db_driver'] = 'sqlite';
$config['db_path'] = __DIR__ . '/data/database.sqlite';
$config['db_host'] = 'localhost';
$config['db_name'] = 'forum';
$config['db_user'] = 'root';
$config['db_pass'] = '';
$config['site_name'] = 'bulletinbored';
$config['admin_user'] = 'admin';
$config['admin_pass'] = 'changeme123';
$config['mail_from'] = 'noreply@bulletinbored.local';
$config['mail_from_name'] = 'bulletinbored';
$config['mail_method'] = 'mail';
$config['theme'] = 'freshbored';
$config['default_lang'] = 'en';
$config['available_langs'] = array (
  0 => 'en',
);
$config['avatar_max_size'] = 2097152;
$config['avatar_allowed_types'] = array (
  0 => 'image/jpeg',
  1 => 'image/png',
  2 => 'image/gif',
  3 => 'image/webp',
);
$config['base_url'] = '';
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
