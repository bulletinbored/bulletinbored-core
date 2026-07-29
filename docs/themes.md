# Themes

Distributed themes and contributions are accepted under the terms of the [CLA.md](../CLA.md).

The forum ships with **Freshbored**, a Bootstrap 5 dark navbar theme.

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
