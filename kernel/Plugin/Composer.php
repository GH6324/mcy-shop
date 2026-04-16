<?php
declare (strict_types=1);

namespace Kernel\Plugin;

use Kernel\Component\Singleton;
use Kernel\Exception\RuntimeException;
use Kernel\Exception\ServiceException;
use Kernel\Util\Aes;
use Kernel\Util\File;

class Composer
{
    use Singleton;

    public const CACHE_FILE = BASE_PATH . "/runtime/plugin/vendor";

    /**
     * @var array
     */
    private array $loaded = [];


    /**
     * @return void
     */
    public function register(): void
    {
        try {
            $list = File::read(self::CACHE_FILE, function (string $contents) {
                $pass = Plugin::inst()->getHwId();
                return unserialize(Aes::decrypt($contents, $pass, $pass, false)) ?: [];
            });

            foreach ($list as $item) {
                $path = realpath(BASE_PATH . $item['env'] . "/{$item['name']}/Vendor/autoload.php");
                if (!file_exists($path)) {
                    continue;
                }

                if (array_key_exists($path, $this->loaded)) {
                    continue;
                }

                $autoloadRealFile = dirname($path) . '/composer/autoload_real.php';
                $code = file_get_contents($autoloadRealFile);


                if ($code !== false && preg_match('/class\s+(ComposerAutoloaderInit[a-zA-Z0-9_]+)/', $code, $match)) {
                    $composerInitClass = $match[1];
                    if (class_exists($composerInitClass, false)) {
                        $loaded[$path] = false;
                        continue;
                    }
                }

                require_once($path);
                $this->loaded[$path] = true;
            }
        } catch (\Throwable $e) {

        }
    }

    /**
     * @param string $name
     * @param string $env
     * @return void
     * @throws RuntimeException
     * @throws ServiceException
     * @throws \ReflectionException
     */
    public function install(string $name, string $env): void
    {
        $plugin = Plugin::inst()->getPlugin($name, $env);

        if (!$plugin) {
            throw new ServiceException("插件不存在");
        }


        if (!file_exists($plugin->path . "/Vendor/autoload.php")) {
            return;
        }

        File::writeForLock(self::CACHE_FILE, function (string $contents) use ($env, $name) {
            $pass = Plugin::inst()->getHwId();
            $composers = unserialize(Aes::decrypt($contents, $pass, $pass, false)) ?: [];
            $composers[] = [
                "name" => $name,
                "env" => $env,
            ];
            return Aes::encrypt(serialize($composers), $pass, $pass, false);
        });
    }

    /**
     * @param string $name
     * @param string $env
     * @return void
     * @throws RuntimeException
     */
    public function uninstall(string $name, string $env): void
    {
        File::writeForLock(self::CACHE_FILE, function (string $contents) use ($name, $env) {
            $pass = Plugin::inst()->getHwId();
            $composers = unserialize(Aes::decrypt($contents, $pass, $pass, false)) ?: [];
            foreach ($composers as $index => $co) {
                if ($co['name'] == $name && $co['env'] == $env) {
                    unset($composers[$index]);
                }
            }
            $composers = array_values($composers);
            return Aes::encrypt(serialize($composers), $pass, $pass, false);
        });
    }
}