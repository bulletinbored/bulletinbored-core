# Localization (i18n)

The forum includes a basic translation system managed from the admin dashboard.

1. Create translation files in `lang/` (e.g., `lang/en.php`, `lang/it.php`)
2. Each file returns an associative array of key => translated string
3. Go to **Admin Panel → Settings** to configure:
   - **Default Language**: the language used by default
   - **Available Languages**: comma-separated list of enabled language codes
4. Use `t('key')` in views and index.php to output translated strings

The forum ships with English (`lang/en.php`) and Italian (`lang/it.php`) translations.

## Translation File Format

Each language file is a PHP file that returns an array:

```php
<?php
return [
    'site_name' => 'bulletinbored',
    'home' => 'Home',
    'login' => 'Login',
    'logout' => 'Logout',
];
```

## Using Translations

In views and `index.php`:

```php
<?= t('home') ?>
```

With parameters:

```php
<?= t('reply_notification_subject', ['title' => $threadTitle]) ?>
```

## Managing Languages

- Upload new language files from **Admin Panel → Languages**
- Delete installed language files from **Admin Panel → Languages** (except the default language)
- Language files can also be uploaded via ZIP from the Plugin Manager if they're bundled as part of a localization pack
