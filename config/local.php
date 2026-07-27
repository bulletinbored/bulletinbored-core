<?php
// Configuration file - Copy to config/local.php to override defaults

return [
    'db_driver' => 'sqlite',
    'db_path' => __DIR__ . '/storage/database.sqlite',
    'db_table_prefix' => 'forum_',
    'site_name' => 'Forum Nuovo',
    'site_url' => '',
    'admin_email' => 'admin@example.com',
    'upload_dir' => 'storage/uploads/',
    'max_upload_size' => 10485760, // 10MB
    'plugin_dir' => 'plugins/',
    'template_dir' => 'templates/',
    'session_lifetime' => 3600,
    'salt' => 'change_this_to_random_string'
];