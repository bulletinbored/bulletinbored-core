# Plugin API Inventory — BulletinBored

**Generated:** 2026-08-30  
**Version:** 0.5.1  

This document inventories the public APIs that plugins can legitimately call.  
It is the basis for the **Plugin API v1** in the roadmap.

---

## 1. Global Helpers

### URL & Routing

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `url()` | `src/helpers.php` | 19 | `url(string $action, array $params = [], bool $absolute = false): string` |
| `base_url()` | `src/helpers.php` | 377 | `base_url(): string` |
| `current_route_action()` | `src/helpers.php` | 164 | `current_route_action(): string` |
| `redirect()` | `src/helpers.php` | 376 | `redirect(string $url): void` |

### Translation & i18n

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `t()` | `src/bootstrap.php` | 201 | `t(string $key, array $params = [], string $scope = 'core'): string` |
| `pt()` | `src/bootstrap.php` | 211 | `pt(string $pluginName, string $key, array $params = []): string` |
| `tt()` | `src/bootstrap.php` | 216 | `tt(string $themeName, string $key, array $params = []): string` |
| `load_lang_file()` | `src/bootstrap.php` | 174 | `load_lang_file(string $path): array` |

### Output Escaping & Sanitization

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `escape()` | `src/helpers.php` | 234 | `escape($s): string` |
| `render_site_name()` | `src/helpers.php` | 241 | `render_site_name(string $name): string` |
| `validate_input()` | `src/helpers.php` | 497 | `validate_input($data): string` |
| `clean_text()` | `src/helpers.php` | 507 | `clean_text($data): string` |

### CSRF

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `generate_csrf_token()` | `src/helpers.php` | 422 | `generate_csrf_token(): string` |
| `validate_csrf_token()` | `src/helpers.php` | 428 | `validate_csrf_token($token): bool` |
| `csrf_validate_request()` | `src/helpers.php` | 434 | `csrf_validate_request(): bool` |
| `csrf_field()` | `src/helpers.php` | 443 | `csrf_field(): string` |

### CSP (Content Security Policy)

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `generate_csp_nonce()` | `src/csp.php` | 3 | `generate_csp_nonce(): string` |
| `csp_nonce()` | `src/csp.php` | 10 | `csp_nonce(): string` |
| `send_security_headers()` | `src/csp.php` | 14 | `send_security_headers(string $nonce): void` |

### Session/Auth State

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `is_logged_in()` | `src/helpers.php` | 361 | `is_logged_in(): bool` |
| `is_admin()` | `src/helpers.php` | 362 | `is_admin(): bool` |
| `is_banned()` | `src/helpers.php` | 245 | `is_banned(): bool` |
| `is_suspended()` | `src/helpers.php` | 247 | `is_suspended(): bool` |
| `user_has_permission()` | `src/helpers.php` | 363 | `user_has_permission(string $permission): bool` |

### Text & Content

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `slugify()` | `src/helpers.php` | 10 | `slugify($text): string` |
| `excerpt()` | `src/helpers.php` | 545 | `excerpt($text, $length = 110): string` |
| `time_ago()` | `src/helpers.php` | 519 | `time_ago($datetime): string` |
| `compact_number()` | `src/helpers.php` | 537 | `compact_number($n): string` |
| `marked_parse()` | `src/helpers.php` | 263 | `marked_parse($text): string` |

### Avatar & Rendering

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `render_avatar()` | `src/helpers.php` | 577 | `render_avatar($username, $avatar = '', $size = 44, $class = ''): string` |
| `avatar_initial()` | `src/helpers.php` | 563 | `avatar_initial($name): string` |
| `avatar_color()` | `src/helpers.php` | 571 | `avatar_color($name): string` |

### Forum Data

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `sidebar_categories()` | `src/helpers.php` | 589 | `sidebar_categories(): array` |
| `forum_statistics()` | `src/helpers.php` | 609 | `forum_statistics(): array` |
| `thread_sort_options()` | `src/helpers.php` | 638 | `thread_sort_options(): array` |
| `fetch_threads()` | `src/helpers.php` | 653 | `fetch_threads(array $opts = []): array` |

### Upload & File

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `validate_upload()` | `src/helpers.php` | 282 | `validate_upload(string $tmpPath, string $origName, array $allowed, int $maxSize): ?array` |
| `validate_uploaded_file()` | `src/helpers.php` | 320 | `validate_uploaded_file(string $tmpPath, string $origName, array $allowed, int $maxSize): ?array` |
| `get_uploaded_images()` | `src/helpers.php` | 328 | `get_uploaded_images(): array` |

### Email & Notifications

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `send_email()` | `src/helpers.php` | 748 | `send_email($to, $subject, $body): bool` |
| `notify_thread_reply()` | `src/helpers.php` | 856 | `notify_thread_reply($thread, int $authorId, string $content): void` |
| `notify_admin_new_user()` | `src/helpers.php` | 908 | `notify_admin_new_user($username, $email = ''): bool` |
| `notify_mentioned_users()` | `src/helpers.php` | 925 | `notify_mentioned_users($pdo, $content, $threadId, $threadTitle, $authorName): int` |
| `create_notification()` | `src/helpers.php` | 991 | `create_notification(PDO $pdo, int $userId, string $type, string $title, string $message, string $link = ''): void` |
| `notification_label()` | `src/helpers.php` | 1010 | `notification_label(array $n): string` |

### Rate Limiting & Security

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `rate_limit()` | `src/helpers.php` | 462 | `rate_limit($action, $max = 10, $window = 300, $key = null): bool` |
| `log_security_event()` | `src/helpers.php` | 393 | `log_security_event(string $event, array $context = []): void` |
| `log_admin_action()` | `src/helpers.php` | 415 | `log_admin_action(string $action, array $context = []): void` |

### Markdown Rendering

| Function | File | Line | Signature |
|----------|------|------|-----------|
| `bb_render_content()` | `src/markdown.php` | 206 | `bb_render_content(string $text): string` |
| `bb_parse_markdown()` | `src/markdown.php` | 100 | `bb_parse_markdown(string $src): string` |
| `bb_parse_inline()` | `src/markdown.php` | 30 | `bb_parse_inline(string $text): string` |
| `bb_esc()` | `src/markdown.php` | 20 | `bb_esc(string $s): string` |

---

## 2. Hook System

### Methods (PluginManager)

| Method | File | Line | Signature |
|--------|------|------|-----------|
| `addHook()` | `lib/PluginManager.php` | 284 | `addHook(string $event, callable $callback, int $priority = 10): void` |
| `removeHook()` | `lib/PluginManager.php` | 290 | `removeHook(string $event, callable $callback): void` |
| `runHook()` | `lib/PluginManager.php` | 304 | `runHook(string $event, mixed ...$args): void` |
| `applyHook()` | `lib/PluginManager.php` | 321 | `applyHook(string $event, mixed ...$args): mixed` |
| `filter()` | `lib/PluginManager.php` | 347 | `filter(string $event, mixed $value, mixed ...$args): mixed` |
| `checkHook()` | `lib/PluginManager.php` | 371 | `checkHook(string $event, mixed ...$args): bool` |
| `checkHookAll()` | `lib/PluginManager.php` | 392 | `checkHookAll(string $event, mixed ...$args): bool` |
| `captureHook()` | `lib/PluginManager.php` | 405 | `captureHook(string $event, mixed ...$args): void` |

### Core Hooks (posts.php, users.php)

| Hook | Type | File:Line | Description |
|------|------|-----------|-------------|
| `thread_not_found` | `applyHook` | `posts.php:121` | Fallback content when thread not found |
| `thread_before_view` | `filter` | `posts.php:131` | Filter thread data before display |
| `thread_posts_before_view` | `filter` | `posts.php:171` | Filter posts array before display |
| `thread_before_render` | `runHook` | `posts.php:177` | Action before thread render |
| `thread_after_render` | `runHook` | `posts.php:183` | Action after thread render |
| `thread_before_create` | `filter` | `posts.php:244` | Filter data before thread creation |
| `thread_create_block` | `checkHook` | `posts.php:245` | Block thread creation (veto) |
| `thread_after_create` | `runHook` | `posts.php:258` | Action after thread creation |
| `post_before_create` | `filter` | `posts.php:319` | Filter data before post creation |
| `post_create_block` | `checkHook` | `posts.php:320` | Block post creation (veto) |
| `post_after_create` | `runHook` | `posts.php:333` | Action after post creation |
| `post_before_update` | `filter` | `posts.php:379` | Filter data before post update |
| `post_after_update` | `runHook` | `posts.php:392` | Action after post update |
| `thread_before_update` | `filter` | `posts.php:448` | Filter data before thread update |
| `thread_after_update` | `runHook` | `posts.php:461` | Action after thread update |
| `post_delete_block` | `checkHook` | `posts.php:508` | Block post deletion (veto) |
| `post_before_delete` | `runHook` | `posts.php:513` | Action before post deletion |
| `post_after_delete` | `runHook` | `posts.php:519` | Action after post deletion |
| `thread_delete_block` | `checkHook` | `posts.php:565` | Block thread deletion (veto) |
| `thread_before_delete` | `runHook` | `posts.php:570` | Action before thread deletion |
| `thread_after_delete` | `runHook` | `posts.php:577` | Action after thread deletion |
| `auth_before_verify` | `filter` | `users.php:55` | Filter user before auth verification |
| `auth_login_block` | `checkHook` | `users.php:59` | Block login (veto) |
| `auth_after_login` | `runHook` | `users.php:88` | Action after successful login |
| `auth_login_failed` | `runHook` | `users.php:98` | Action on failed login |
| `user_registered` | `runHook` | `users.php:140` | Action after user registration |

### Render Hooks (views/header.php)

| Hook | Type | File:Line | Description |
|------|------|-----------|-------------|
| `before_render` | `runHook` | `header.php:44` | Before any render |
| `frontend_before_render` | `runHook` | `header.php:45` | Before frontend render |
| `admin_before_render` | `runHook` | `header.php:47` | Before admin render |
| `navbar_icons` | `runHook` | `header.php:74` | Navbar icons |
| `mobile_tabbar_icons` | `runHook` | `header.php:110` | Mobile tabbar icons |
| `mobile_stack_tabs` | `runHook` | `header.php:140` | Mobile stack tabs |
| `mobile_stack_panes` | `runHook` | `header.php:155` | Mobile stack panes |
| `footer_before_render` | `runHook` | `header.php:220` | Before footer render |

### Content Hook (src/markdown.php)

| Hook | Type | File:Line | Description |
|------|------|-----------|-------------|
| `render_content` | `applyHook` | `markdown.php:212` | Override content rendering |

### Dynamic Hooks (permissions)

| Hook | Type | File:Line | Description |
|------|------|-----------|-------------|
| `permission_{name}` | `checkHook` | `helpers.php:372` | Dynamic permission check |

---

## 3. PluginManager API

| Method | File | Line | Signature |
|--------|------|------|-----------|
| `setRouter()` | `lib/PluginManager.php` | 26 | `setRouter(Bulletin\Router $router): void` |
| `registerRoute()` | `lib/PluginManager.php` | 31 | `registerRoute(string $method, string $pattern, callable $handler, array $middleware = []): void` |
| `registerMiddleware()` | `lib/PluginManager.php` | 36 | `registerMiddleware(string $name, callable $fn): void` |
| `getRouter()` | `lib/PluginManager.php` | 41 | `getRouter(): ?Bulletin\Router` |
| `applyRoutes()` | `lib/PluginManager.php` | 46 | `applyRoutes(): void` |
| `discover()` | `lib/PluginManager.php` | 149 | `discover(): array` |
| `getAll()` | `lib/PluginManager.php` | 202 | `getAll(): array` |
| `getEnabled()` | `lib/PluginManager.php` | 210 | `getEnabled(): array` |
| `getByName()` | `lib/PluginManager.php` | 215 | `getByName(string $name): ?array` |
| `isEnabled()` | `lib/PluginManager.php` | 221 | `isEnabled(string $name): bool` |
| `enable()` | `lib/PluginManager.php` | 227 | `enable(string $name): bool` |
| `disable()` | `lib/PluginManager.php` | 240 | `disable(string $name): bool` |
| `loadTranslations()` | `lib/PluginManager.php` | 253 | `loadTranslations(string $lang): void` |
| `loadEnabled()` | `lib/PluginManager.php` | 268 | `loadEnabled(): array` |
| `getVersion()` | `lib/PluginManager.php` | 430 | `getVersion(string $name): string` |
| `installFromZip()` | `lib/PluginManager.php` | 538 | `installFromZip(string $zipPath): array` |
| `installFromRepo()` | `lib/PluginManager.php` | 730 | `installFromRepo(string $repoUrl, ?string $tag = null, ?string $expectedName = null): array` |
| `delete()` | `lib/PluginManager.php` | 648 | `delete(string $name): array` |
| `removeMissing()` | `lib/PluginManager.php` | 682 | `removeMissing(): array` |

---

## 4. Renderer API (Bulletin\Renderer)

| Method | File | Line | Signature |
|--------|------|------|-----------|
| `__construct()` | `src/Renderer.php` | 37 | `__construct(string $viewsPath)` |
| `addGlobal()` | `src/Renderer.php` | 42 | `addGlobal(string $key, mixed $value): self` |
| `composer()` | `src/Renderer.php` | 56 | `composer(string $template, callable $callback): self` |
| `composeAll()` | `src/Renderer.php` | 66 | `composeAll(callable $callback): self` |
| `render()` | `src/Renderer.php` | 73 | `render(string $template, array $data = []): string` |
| `display()` | `src/Renderer.php` | 82 | `display(string $template, array $data = []): void` |
| `e()` | `src/Renderer.php` | 124 | `e(string $value): string` |
| `raw()` | `src/Renderer.php` | 129 | `raw(string $value): string` |
| `partial()` | `src/Renderer.php` | 134 | `partial(string $name, array $data = []): string` |
| `renderPartial()` | `src/Renderer.php` | 154 | `renderPartial(string $name, array $data = []): void` |
| `slot()` | `src/Renderer.php` | 159 | `slot(string $name): void` |
| `endSlot()` | `src/Renderer.php` | 165 | `endSlot(): void` |
| `hasSlot()` | `src/Renderer.php` | 174 | `hasSlot(string $name): bool` |
| `slotContent()` | `src/Renderer.php` | 179 | `slotContent(string $name): string` |
| `renderSlot()` | `src/Renderer.php` | 184 | `renderSlot(string $name): void` |
| `yield()` | `src/Renderer.php` | 189 | `yield(string $name, string $default = ''): void` |
| `layout()` | `src/Renderer.php` | 194 | `layout(string $layout): void` |
| `extend()` | `src/Renderer.php` | 199 | `extend(string $layoutTemplate, array $data = []): string` |
| `when()` | `src/Renderer.php` | 225 | `when(bool $condition, callable $callback): string` |
| `each()` | `src/Renderer.php` | 233 | `each(array $items, callable $callback): string` |
| `escapeAttr()` | `src/Renderer.php` | 242 | `escapeAttr(string $value): string` |
| `csrfField()` | `src/Renderer.php` | 247 | `csrfField(): string` |
| `url()` | `src/Renderer.php` | 252 | `url(string $action, array $params = []): string` |
| `t()` | `src/Renderer.php` | 257 | `t(string $key): string` |
| `renderComponent()` | `src/Renderer.php` | 262 | `renderComponent(string $name, array $data = []): string` |
| `displayComponent()` | `src/Renderer.php` | 282 | `displayComponent(string $name, array $data = []): void` |

---

## 5. Database API

### BbPdo (lib/BbPdo.php)

| Method | Line | Signature |
|--------|------|-----------|
| `__construct()` | 15 | `__construct(string $dsn, ?string $user = null, ?string $pass = null, ?array $options = null)` |
| `exec()` | 59 | `exec($statement, ...$rest)` |
| `query()` | 68 | `query($statement, ...$rest)` |
| `prepare()` | 73 | `prepare($statement, $options = null)` |

### DbQuery (lib/DbQuery.php)

| Method | Line | Signature |
|--------|------|-----------|
| `__construct()` | 28 | `__construct(PDO $pdo)` |
| `table()` | 33 | `table(string $table): self` |
| `select()` | 51 | `select(string ...$cols): self` |
| `where()` | 58 | `where(string $column, $value, string $op = '='): self` |
| `whereIn()` | 67 | `whereIn(string $column, array $values): self` |
| `whereRaw()` | 83 | `whereRaw(string $sql, array $params = []): self` |
| `orderBy()` | 93 | `orderBy(string $column, string $direction = 'ASC'): self` |
| `limit()` | 101 | `limit(int $limit): self` |
| `offset()` | 108 | `offset(int $offset): self` |
| `get()` | 143 | `get(): array` |
| `first()` | 156 | `first(): ?array` |
| `count()` | 163 | `count(): int` |
| `insert()` | 171 | `insert(array $data): int` |
| `insertIgnore()` | 191 | `insertIgnore(array $data): int` |
| `update()` | 220 | `update(array $data): int` |
| `delete()` | 240 | `delete(): int` |
| `raw()` | 248 | `raw(string $sql, array $params = []): array` |
| `rawFirst()` | 255 | `rawFirst(string $sql, array $params = []): ?array` |
| `rawExec()` | 263 | `rawExec(string $sql, array $params = []): int` |
| `exists()` | 270 | `exists(): bool` |
| `pluck()` | 275 | `pluck(string $column): array` |
| `paginate()` | 282 | `paginate(int $perPage, int $page = 1): array` |

---

## 6. Request API (src/Request.php)

| Method | Line | Signature |
|--------|------|-----------|
| `get()` | 24 | `static get(string $key, mixed $default = null): mixed` |
| `post()` | 35 | `static post(string $key, mixed $default = null): mixed` |
| `input()` | 46 | `static input(string $key, mixed $default = null): mixed` |
| `has()` | 60 | `static has(string $key): bool` |
| `raw()` | 69 | `static raw(string $key, mixed $default = null): mixed` |
| `sanitize()` | 84 | `static sanitize(mixed $value): mixed` |

---

## 7. Router API (src/Router.php)

| Method | Line | Signature |
|--------|------|-----------|
| `middleware()` | 80 | `middleware(string ...$names): self` |
| `group()` | 86 | `group(callable $callback): self` |
| `api()` | 98 | `api(): self` |
| `view()` | 105 | `view(): self` |
| `get()` | 112 | `get(string $pattern, callable $handler): self` |
| `post()` | 117 | `post(string $pattern, callable $handler): self` |
| `put()` | 122 | `put(string $pattern, callable $handler): self` |
| `delete()` | 127 | `delete(string $pattern, callable $handler): self` |
| `patch()` | 132 | `patch(string $pattern, callable $handler): self` |
| `any()` | 137 | `any(string $pattern, callable $handler): self` |
| `registerMiddleware()` | 168 | `registerMiddleware(string $name, callable $fn): self` |
| `dispatch()` | 174 | `dispatch(): void` |

---

## 8. ThemeManager API (lib/ThemeManager.php)

| Method | Line | Signature |
|--------|------|-----------|
| `loadTranslations()` | 19 | `loadTranslations(string $lang): void` |
| `discover()` | 74 | `discover(): array` |
| `getAll()` | 93 | `getAll(): array` |
| `getActive()` | 101 | `getActive(): string` |
| `getActiveMeta()` | 115 | `getActiveMeta(): ?array` |
| `activate()` | 121 | `activate(string $name): bool` |
| `getCssUrl()` | 135 | `getCssUrl(?string $name = null): string` |
| `getCssPath()` | 155 | `getCssPath(?string $name = null): string` |
| `getVersion()` | 167 | `getVersion(string $name): string` |
| `installFromZip()` | 173 | `installFromZip(string $zipPath): array` |
| `delete()` | 307 | `delete(string $name): array` |
| `removeMissing()` | 345 | `removeMissing(): array` |
| `installFromRepo()` | 387 | `installFromRepo(string $repoUrl, ?string $tag = null, ?string $expectedName = null): array` |

---

## 9. UpdateManager API (lib/UpdateManager.php)

| Method | Line | Signature |
|--------|------|-----------|
| `setVersion()` | 55 | `setVersion(string $type, string $name, string $version): void` |
| `getVersion()` | 64 | `getVersion(string $type, string $name): string` |
| `recordCheck()` | 69 | `recordCheck(string $type, string $name, ?string $remoteVersion, ?string $updateUrl = null, ?string $updateNotes = null): void` |
| `getAvailableUpdate()` | 86 | `getAvailableUpdate(string $type, string $name): ?array` |
| `checkAll()` | 91 | `checkAll(string $coreVersion, PluginManager $pluginManager, ThemeManager $themeManager, ?array $catalog = null): array` |
| `applyUpdate()` | 196 | `applyUpdate(string $type, string $name, string $zipPath): bool` |
| `applyCoreUpdate()` | 334 | `applyCoreUpdate(string $tag): bool` |
| `applyExtensionUpdate()` | 459 | `applyExtensionUpdate(string $type, string $name, string $tag, ?string $repoUrl = null): bool` |
| `getRemoteVersion()` | 591 | `getRemoteVersion(string $type, string $name, ?string $repoUrl = null): ?string` |
| `getLockedExtensions()` | 596 | `getLockedExtensions(): array` |

#### Private methods (ZIP upload dispatch)

| Method | Line | Signature |
|--------|------|-----------|
| `applyExtensionUpdateFromZip()` | 209 | `applyExtensionUpdateFromZip(string $type, string $name, string $zipPath): bool` |
| `applyCoreUpdateFromZip()` | 279 | `applyCoreUpdateFromZip(string $zipPath): bool` |
| `detectVersionFromPackage()` | 314 | `detectVersionFromPackage(string $targetDir): string` |

---

## 10. Migrator API (lib/Migrator.php)

| Method | Line | Signature |
|--------|------|-----------|
| `__construct()` | 26 | `__construct(PDO $pdo, array $config)` |
| `addPath()` | 37 | `addPath(string $path): self` |
| `addPluginPaths()` | 50 | `addPluginPaths(string $pluginsDir): self` |
| `ensureMigrationsTable()` | 70 | `ensureMigrationsTable(): void` |
| `getAllMigrations()` | 100 | `getAllMigrations(): array` |
| `getRanMigrations()` | 131 | `getRanMigrations(): array` |
| `getPending()` | 140 | `getPending(): array` |
| `getNextBatch()` | 151 | `getNextBatch(): int` |
| `getLastBatch()` | 161 | `getLastBatch(): ?int` |
| `getMigrationsByBatch()` | 171 | `getMigrationsByBatch(int $batch): array` |
| `getBatchFor()` | 195 | `getBatchFor(string $name): ?int` |
| `runUp()` | 206 | `runUp(array $migration, int $batch): void` |
| `runDown()` | 218 | `runDown(array $migration): void` |
| `rollbackByName()` | 230 | `rollbackByName(string $name): void` |

---

## 11. Global Variables for Plugins

| Variable | Type | Description |
|----------|------|-------------|
| `$config` | `array` | Global configuration from `config.json` |
| `$pdo` | `BbPdo` | Database connection |
| `$pluginManager` | `PluginManager` | Plugin manager instance |
| `$themeManager` | `ThemeManager` | Theme manager instance |
| `$lang` | `string` | Current language code |
| `$GLOBALS['i18n']` | `array` | Translation registry |
| `$GLOBALS['CSP_NONCE']` | `string` | CSP nonce for the current request |

---

## 12. Plugin Initialization Convention

Plugins must define an init function following this pattern:

```php
function {pluginname}_init() {
    global $pluginManager, $config, $pdo;
    // Register hooks, routes, etc.
}
```

The init function is called automatically by `PluginManager::loadEnabled()` (`lib/PluginManager.php:268-282`).

---

## Summary

| API Category | Items |
|--------------|-------|
| Global helpers | ~50 functions |
| Hook system | 8 methods + 30+ hook names |
| PluginManager | 20 methods |
| Renderer | 25 methods |
| DbQuery | 22 methods |
| Request | 6 methods |
| Router | 13 methods |
| ThemeManager | 13 methods |
| UpdateManager | 10 methods |
| Migrator | 15 methods |
| **TOTAL** | **~210 public APIs** |
