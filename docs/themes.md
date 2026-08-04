# Themes

Distributed themes and contributions are accepted under the terms of the [CLA.md](../CLA.md).

The forum ships with **freshbored**, a Bootstrap 5 dark navbar theme.

## Theme Structure

A theme is a folder in `themes/` containing at minimum a `style.css` file.

```
themes/mytheme/
├── style.css          # Required
└── manifest.json      # Optional
```

## manifest.json

Optional metadata file:

```json
{
    "name": "My Theme",
    "version": "1.0.0",
    "author": "Your Name",
    "description": "Theme description"
}
```

## Localization

Themes can be localized independently from the core and from plugins. Each theme
gets its own translation **scope** (`theme:<name>`), so theme strings never
collide with the core or with plugins.

Place translation files under a `lang/` directory using the language code as the
filename:

```
themes/mytheme/
├── style.css          # Required
├── manifest.json      # Optional
└── lang/
    ├── en.php
    └── it.php
```

Each file returns an associative array:

```php
<?php
return [
    'tagline' => 'Welcome to the forum',
];
```

Strings are loaded automatically into the `theme:<name>` scope based on the
active language. Use the `tt()` helper to translate:

```php
echo tt('mytheme', 'tagline');                  // -> 'Welcome to the forum'
echo tt('mytheme', 'hello', ['name' => 'Joe']); // with {name} placeholder
```

You may also call the core translation function directly with an explicit scope:

```php
echo t('tagline', [], 'theme:mytheme');
```

If a key is missing in the theme's language file, the key itself is returned
(untranslated) — so a theme that ships no `lang/` directory still works
unchanged. The core translation function `t($key, $params)` continues to resolve
only from the `core` scope and is unaffected by theme translations.

## Activating a Theme

1. Place the theme folder in `themes/`
2. Go to **Admin Panel → Themes** and click **Activate**
3. Or set the theme in `config.php`: `'theme' => 'mytheme'`

## Shipping a Theme as ZIP

To distribute a theme as a ZIP package:

1. Create a folder with the theme name (e.g., `mytheme/`)
2. Add `style.css` and optional `manifest.json`
3. ZIP the folder (the ZIP should contain the folder itself, not loose files)
4. Upload via **Admin Panel → Themes → Install Theme**

## CSS Classes Used

The theme should style the following classes:

- `.navbar-forum` — main navigation bar
- `.container` — main content wrapper
- `.footer` — footer area
- `.admin-layout` — admin panel layout

Fields and buttons use Bootstrap 5 classes by default.
