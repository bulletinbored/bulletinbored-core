<?php
// Forum Nuovo - MVC Forum Software
// Bootstrap and Framework Core

class ForumApp {
    private $config;
    private $router;
    private $container;\n    private $db;
    private $logger;

    public function __construct() {
        $this->loadConfig();
        $this->initContainer();
        $this->initLogger();
        $this->initDatabase();
        $this->initRouter();
        $this->registerCoreServices();
    }

    private function loadConfig() {
        // Load configuration
        $configFile = __DIR__ . '/config/local.php';
        if (file_exists($configFile)) {
            $this->config = require $configFile;
        } else {
            $this->config = $this->getDefaultConfig();
        }
    }

    private function getDefaultConfig() {
        return [
            'db_driver' => 'sqlite',
            'db_path' => __DIR__ . '/storage/database.sqlite',
            'db_table_prefix' => 'forum_',
            'site_name' => 'Forum Nuovo',
            'site_url' => '',
            'admin_email' => 'admin@example.com',
            'upload_dir' => 'storage/uploads/',
            'max_upload_size' => 10485760,
            'plugin_dir' => 'plugins/',
            'template_dir' => 'templates/',
            'session_lifetime' => 3600,
        ];
    }

    private function initContainer() {
        $this->container = new Container();
        $this->container->set('config', $this->config);
    }

    private function initLogger() {
        $this->logger = new Logger(__DIR__ . '/storage/app.log');
        $this->container->set('logger', $this->logger);
    }

    private function initDatabase() {
        $dbClass = $this->config['db_driver'] . 'Database';
        $this->db = new $dbClass($this->config);
        $this->container->set('db', $this->db);

        // Auto-migrate tables
        $this->db->autoMigrate();
    }

    private function initRouter() {
        $this->router = new Router();
        $this->container->set('router', $this->router);
    }

    private function registerCoreServices() {
        $this->container->set('app', $this);
    }

    public function run() {
        $this->registerRoutes();
        $this->router->dispatch();
    }

    private function registerRoutes() {
        $forumController = new ForumController($this->container);
        $authController = new AuthController($this->container);

        // Public routes
        $this->router->addRoute('GET', '/', [$forumController, 'index']);
        $this->router->addRoute('GET', '/thread/{id}', [$forumController, 'viewThread']);
        $this->router->addRoute('POST', '/thread', [$forumController, 'createThread']);
        $this->router->addRoute('POST', '/reply', [$forumController, 'createReply']);

        // Auth routes
        $this->router->addRoute('GET', '/login', [$authController, 'login']);
        $this->router->addRoute('POST', '/login', [$authController, 'authenticate']);
        $this->router->addRoute('GET', '/register', [$authController, 'register']);
        $this->router->addRoute('POST', '/register', [$authController, 'createAccount']);
        $this->router->addRoute('GET', '/logout', [$authController, 'logout']);

        // Admin routes
        $this->router->addRoute('GET', '/admin', [$forumController, 'adminPanel']);
        $this->router->addRoute('POST', '/admin/moderate', [$forumController, 'moderateContent']);

        // Plugin routes
        $this->loadPlugins();
    }

    private function loadPlugins() {
        $pluginDir = $this->config['plugin_dir'];
        if (!is_dir($pluginDir)) {
            return;
        }

        $files = scandir($pluginDir);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $pluginClass = pathinfo($file, PATHINFO_FILENAME);
                $pluginPath = $pluginDir . $file;
                // Load plugin
                require $pluginPath;
                // Register plugin routes and services
            }
        }
    }

    public function getContainer() {
        return $this->container;
    }
}
