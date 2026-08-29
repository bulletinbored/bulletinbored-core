<?php

/**
 * Renderer.php — micro template renderer.
 *
 * Separates logic from presentation. Templates are pure PHP files that receive
 * pre-escaped variables via $this. No raw PHP logic in templates.
 *
 * Usage:
 *   $r = new Renderer(__DIR__ . '/views');
 *   $r->render('thread', ['thread' => $thread, 'posts' => $posts]);
 *
 * In templates:
 *   $this->e($thread['title'])          — escaped output
 *   $this->partial('post', ['post' => $p]) — include partial
 *   $this->slot('sidebar')              — start capturing a slot
 *   $this->endSlot()                    — end capturing
 *   $this->hasSlot('sidebar')           — check if slot has content
 *   $this->slotContent('sidebar')       — output slot content
 */

namespace Bulletin;

class Renderer
{
    private string $viewsPath;
    private string $layout = '';
    private array $slots = [];
    private string $currentSlot = '';
    private array $globals = [];
    private array $sectionStack = [];

    public function __construct(string $viewsPath)
    {
        $this->viewsPath = rtrim($viewsPath, '/');
    }

    public function addGlobal(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->globals[$key] = $value;
        return $clone;
    }

    public function render(string $template, array $data = []): string
    {
        $clone = clone $this;
        $clone->slots = [];
        return $clone->renderTemplate($template, $data);
    }

    public function display(string $template, array $data = []): void
    {
        echo $this->render($template, $data);
    }

    private function renderTemplate(string $template, array $data): string
    {
        $templateFile = $this->viewsPath . '/' . $template . '.php';
        if (!file_exists($templateFile)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        $renderer = $this;
        extract(array_merge($this->globals, $data));

        ob_start();
        try {
            include $templateFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }

    public function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public function raw(string $value): string
    {
        return $value;
    }

    public function partial(string $name, array $data = []): string
    {
        $partialFile = $this->viewsPath . '/partials/' . $name . '.php';
        if (!file_exists($partialFile)) {
            return '';
        }

        $renderer = $this;
        extract(array_merge($this->globals, $data));

        ob_start();
        try {
            include $partialFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }

    public function renderPartial(string $name, array $data = []): void
    {
        echo $this->partial($name, $data);
    }

    public function slot(string $name): void
    {
        $this->currentSlot = $name;
        ob_start();
    }

    public function endSlot(): void
    {
        if ($this->currentSlot === '') {
            return;
        }
        $this->slots[$this->currentSlot] = ob_get_clean();
        $this->currentSlot = '';
    }

    public function hasSlot(string $name): bool
    {
        return !empty($this->slots[$name]);
    }

    public function slotContent(string $name): string
    {
        return $this->slots[$name] ?? '';
    }

    public function renderSlot(string $name): void
    {
        echo $this->slots[$name] ?? '';
    }

    public function section(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function endSection(): void
    {
        if (empty($this->sectionStack)) {
            return;
        }
        $name = array_pop($this->sectionStack);
        $this->slots[$name] = ob_get_clean();
    }

    public function yield(string $name, string $default = ''): void
    {
        echo $this->slots[$name] ?? $default;
    }

    public function layout(string $layout): void
    {
        $this->layout = $layout;
    }

    public function extend(string $layoutTemplate, array $data = []): string
    {
        $content = $this->renderTemplate($this->currentTemplate ?? '', $data);

        if ($this->layout !== '') {
            $layoutFile = $this->viewsPath . '/' . $this->layout . '.php';
            if (file_exists($layoutFile)) {
                $renderer = $this;
                extract(array_merge($this->globals, $data, ['content' => $content]));

                ob_start();
                include $layoutFile;
                return ob_get_clean();
            }
        }

        return $content;
    }

    public function when(bool $condition, callable $callback): string
    {
        if (!$condition) {
            return '';
        }
        return $callback($this);
    }

    public function each(array $items, callable $callback): string
    {
        $output = '';
        foreach ($items as $key => $item) {
            $output .= $callback($item, $key, $this);
        }
        return $output;
    }

    public function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . $this->e(generate_csrf_token()) . '">';
    }

    public function url(string $action, array $params = []): string
    {
        return url($action, $params);
    }

    public function t(string $key): string
    {
        return t($key);
    }

    public function renderComponent(string $name, array $data = []): string
    {
        $componentFile = $this->viewsPath . '/components/' . $name . '.php';
        if (!file_exists($componentFile)) {
            return '';
        }

        $renderer = $this;
        extract(array_merge($this->globals, $data));

        ob_start();
        try {
            include $componentFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }

    public function displayComponent(string $name, array $data = []): void
    {
        echo $this->renderComponent($name, $data);
    }
}
