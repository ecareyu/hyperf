<?php

namespace Hyperf\Autoload;


use App\Foo;
use Composer\Autoload\ClassLoader as ComposerClassLoader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Dotenv\Test;
use Hyperf\Config\ProviderConfig;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Di\Annotation\AspectCollector;
use Hyperf\Di\Annotation\Inject;

class ClassLoader
{

    /**
     * @var \Composer\Autoload\ClassLoader
     */
    protected $composerLoader;

    /**
     * @var array
     */
    protected $proxies = [];

    /**
     * @var array
     */
    protected $injects = [];

    /**
     * @var array
     */
    protected $classAspects = [];

    public function __construct(ComposerClassLoader $classLoader)
    {
        $this->composerLoader = $classLoader;
        $config = ScanConfig::instance();

        $scanner = new Scanner($this, $config);
        $classes = $scanner->scan($config->getPaths());
        $this->proxies = ProxyManager::init($classes);
        $this->injects = AnnotationCollector::getPropertiesByAnnotation(Inject::class);
        $this->classAspects = $this->getClassAspects();
    }

    public function loadClass(string $class): void
    {
        $path = $this->locateFile($class);

        if ($path !== false) {
            include $path;
        }
    }

    protected function locateFile(string $className)
    {
        if (isset($this->proxies[$className]) && file_exists($this->proxies[$className])) {
            // echo '[Load Proxy] ' . $className . PHP_EOL;
            $file = $this->proxies[$className];
        } else {
            $match = [];
            foreach ($this->classAspects as $aspect => $rules) {
                foreach ($rules as $rule) {
                    if (ProxyManager::isMatch($rule, $className)) {
                        $match[] = $aspect;
                    }
                }
            }
            if ($match) {
                $match = array_flip(array_flip($match));
                $proxies = ProxyManager::generateProxyFiles([$className => $match]);
                $this->proxies = array_merge($this->proxies, $proxies);
                return $this->locateFile($className);
            }
            // echo '[Load Composer] ' . $className . PHP_EOL;
            $file = $this->composerLoader->findFile($className);
        }

        return $file;
    }

    protected static function registerClassLoader()
    {
        $loaders = spl_autoload_functions();

        // Proxy the composer loader
        foreach ($loaders as &$loader) {
            $unregisterLoader = $loader;
            if (is_array($loader) && $loader[0] instanceof ComposerClassLoader) {
                $composerLoader = $loader[0];
                AnnotationRegistry::registerLoader(function ($class) use ($composerLoader) {
                    $composerLoader->loadClass($class);
                    return class_exists($class, false);
                });
                $loader[0] = new static($composerLoader);
            }
            spl_autoload_unregister($unregisterLoader);
        }

        unset($loader);
        
        // Re-register the loaders
        foreach ($loaders as $loader) {
            spl_autoload_register($loader);
        }
    }

    public static function init(): void
    {
        self::registerClassLoader();
    }

    public function getClassAspects(): array
    {
        $aspects = AspectCollector::get('classes', []);
        // Remove the useless aspect rules
        foreach ($aspects as $aspect => $rules) {
            if (! $rules) {
                unset($aspects[$aspect]);
            }
        }
        return $aspects;
    }

    public function getComposerLoader(): ComposerClassLoader
    {
        return $this->composerLoader;
    }

}
