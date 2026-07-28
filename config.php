<?php
// Configuration file - edit these values for your installation
$config = [
    // Database
    'db_driver' => 'sqlite',          // 'sqlite' or 'mysql'
    'db_path' => __DIR__.'/data/database.sqlite',
    'db_host' => 'localhost',
    'db_name' => 'forum',
    'db_user' => 'root',
    'db_pass' => '',
    
    // Site
    'site_name' => 'bulletinbored',
    'admin_user' => 'admin',
    'admin_pass' => 'changeme123',
    
    // Email (for password reset, notifications)
    'mail_from' => 'noreply@bulletinbored.local',
    'mail_from_name' => 'bulletinbored',
    'mail_method' => 'mail',          // 'mail' for PHP mail(), 'smtp' for SMTP
    
    // Theme
    'theme' => 'default'              // Theme name (folder in themes/)
];
