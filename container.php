<?php
// Simple Container for Dependency Injection

class Container {
    private $services = [];
    private $instances = [];

    public function set($name, $service) {
        $this->services[$name] = $service;
    }

    public function get($name) {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (isset($this->services[$name])) {
            if (is_callable($this->services[$name])) {
                $this->instances[$name] = $this->services[$name]($this);
                return $this->instances[$name];
            }
            return $this->services[$name];
        }

        return null;
    }
}