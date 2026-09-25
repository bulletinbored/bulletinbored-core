<?php

/**
 * PluginHooks — hook registration and execution for PluginManager.
 *
 * Extracted from PluginManager to keep the class focused. Uses the host
 * class's $hooks / $capturedHead state.
 */
trait PluginHooks
{
    public function addHook(string $event, callable $callback, int $priority = 10): void
    {
        $this->hooks[$event][] = ['cb' => $callback, 'prio' => $priority];
        usort($this->hooks[$event], fn($a, $b) => $a['prio'] <=> $b['prio']);
    }

    public function removeHook(string $event, callable $callback): void
    {
        if (!isset($this->hooks[$event])) {
            return;
        }
        foreach ($this->hooks[$event] as $i => $h) {
            if ($h['cb'] === $callback) {
                unset($this->hooks[$event][$i]);
                break;
            }
        }
        $this->hooks[$event] = array_values($this->hooks[$event]);
    }

    public function runHook(string $event, mixed ...$args): void
    {
        if (!isset($this->hooks[$event])) {
            return;
        }
        foreach ($this->hooks[$event] as $h) {
            if (is_callable($h['cb'])) {
                call_user_func_array($h['cb'], $args);
            }
        }
    }

    public function applyHook(string $event, mixed ...$args): mixed
    {
        if (!isset($this->hooks[$event])) {
            return null;
        }
        foreach ($this->hooks[$event] as $h) {
            if (is_callable($h['cb'])) {
                $result = call_user_func_array($h['cb'], $args);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        return null;
    }

    public function filter(string $event, mixed $value, mixed ...$args): mixed
    {
        if (!isset($this->hooks[$event])) {
            return $value;
        }
        foreach ($this->hooks[$event] as $h) {
            if (is_callable($h['cb'])) {
                $result = call_user_func($h['cb'], $value, ...$args);
                if ($result !== null) {
                    $value = $result;
                }
            }
        }
        return $value;
    }

    public function checkHook(string $event, mixed ...$args): bool
    {
        if (!isset($this->hooks[$event])) {
            return false;
        }
        foreach ($this->hooks[$event] as $h) {
            if (is_callable($h['cb']) && call_user_func_array($h['cb'], $args)) {
                return true;
            }
        }
        return false;
    }

    public function checkHookAll(string $event, mixed ...$args): bool
    {
        if (!isset($this->hooks[$event])) {
            return true;
        }
        foreach ($this->hooks[$event] as $h) {
            if (is_callable($h['cb']) && !call_user_func_array($h['cb'], $args)) {
                return false;
            }
        }
        return true;
    }

    public function captureHook(string $event, mixed ...$args): void
    {
        if (!isset($this->hooks[$event])) {
            return;
        }
        ob_start();
        foreach ($this->hooks[$event] as $h) {
            if (is_callable($h['cb'])) {
                call_user_func_array($h['cb'], $args);
            }
        }
        $this->capturedHead[] = ob_get_clean();
    }

    public function getCapturedHead(bool $admin = false): string
    {
        if ($admin) {
            if ($this->capturedAdminHead === null) {
                $this->capturedAdminHead = implode("\n", array_filter($this->capturedHead, fn($s) => str_starts_with($s, '<script') || str_starts_with($s, '<link')));
            }
            return $this->capturedAdminHead;
        }
        return implode("\n", array_filter($this->capturedHead, fn($s) => str_contains($s, 'editbored') || str_starts_with($s, '<script') || str_starts_with($s, '<link')));
    }
}
