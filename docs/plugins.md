# Plugins

Create plugins as single PHP files in `plugins/`. Contributions and distributed plugins are accepted under the terms of the [CLA.md](../CLA.md).

## Shipping Plugins as ZIP

To distribute a plugin as a ZIP package:

1. Package your plugin PHP file(s) into a ZIP
2. The plugin file(s) must be at the root of the ZIP or inside a single folder
3. Upload via **Admin Panel → Plugins → Install Plugin**

## Managing Plugins

- **Enable / Disable**: toggle plugin state without deleting the file
- **Delete**: removes the PHP file from `plugins/` and clears state from `data/plugins.json`
- **Install**: upload a ZIP to add one or more plugin files

## Accessing the Plugin Manager

From PHP code:

```php
$pluginManager->getAll();
$pluginManager->getEnabled();
$pluginManager->enable('analytics');
$pluginManager->disable('analytics');
$pluginManager->add_hook('after_post', $callback);
$pluginManager->run_hook('after_post', $arg1, $arg2);
```
