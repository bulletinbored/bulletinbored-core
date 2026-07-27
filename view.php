<?php
// View class for rendering templates

class View {
    private $template;
    private $data = [];
    private $templateDir;

    public function __construct($template, $templateDir = null) {
        $this->template = $template;
        $this->templateDir = $templateDir ?? __DIR__ . '/templates/';
    }

    public function assign($key, $value) {
        $this->data[$key] = $value;
    }

    public function render() {
        $templateFile = $this->templateDir . $this->template . '.php';
        
        extract($this->data);
        
        ob_start();
        if (file_exists($templateFile)) {
            include $templateFile;
        } else {
            echo "<!-- Template: {$this->template} -->";
            $this->renderDefault();
        }
        return ob_get_clean();
    }

    private function renderDefault() {
        echo "<h1>" . ($data['title'] ?? 'Forum') . "</h1>";
    }
}