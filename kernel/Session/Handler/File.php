<?php
declare (strict_types=1);

namespace Kernel\Session\Handler;

use Kernel\Context\App;
use Kernel\Context\Interface\Request;
use Kernel\Exception\RuntimeException;
use Kernel\Session\Session;
use Kernel\Util\Context;
use Kernel\Util\File as Filesystem;
use Symfony\Component\Finder\Finder;

class File implements Session
{

    /**
     * @var string
     */
    private string $path = BASE_PATH . "/runtime/session/";

    public function __construct()
    {
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path . $this->id();
    }


    /**
     * @param string|null $key
     * @return mixed
     */
    public function get(?string $key = null): mixed
    {
        if (!App::$cli) {
            return $_SESSION[$key] ?? null;
        }

        $path = $this->getPath();

        if (!is_file($path)) {
            return null;
        }

        $data = unserialize((string)Filesystem::read($path), ['allowed_classes' => false]);

        if ($key) {
            return $data[$key] ?? null;
        }

        return $data;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     * @throws RuntimeException
     */
    public function set(string $key, mixed $value): void
    {
        if (!App::$cli) {
            $_SESSION[$key] = $value;
            return;
        }

        Filesystem::writeForLock($this->getPath(), function (string $contents) use ($value, $key) {
            $data = unserialize($contents, ['allowed_classes' => false]) ?: [];
            $data[$key] = $value;
            return serialize($data);
        });
    }

    /**
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        if (!App::$cli) {
            return isset($_SESSION[$key]);
        }

        $path = $this->getPath();
        if (!is_file($path)) {
            return false;
        }

        $data = (array)$this->get();
        return isset($data[$key]);
    }

    /**
     * @param string $key
     * @return void
     * @throws RuntimeException
     */
    public function remove(string $key): void
    {
        if (!App::$cli) {
            $_SESSION[$key] = null;
            unset($_SESSION[$key]);
            return;
        }

        $path = $this->getPath();
        if (!is_file($path)) {
            return;
        }

        Filesystem::writeForLock($path, function (string $contents) use ($key) {
            $data = unserialize($contents, ['allowed_classes' => false]) ?: [];
            unset($data[$key]);
            return serialize($data);
        });
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        if (!App::$cli) {
            $_SESSION = [];
            unset($_SESSION);
            return;
        }
        Filesystem::remove($this->getPath());
    }

    /**
     * @return bool
     */
    public function gc(): bool
    {
        $files = Finder::create()
            ->in($this->path)
            ->files()
            ->ignoreDotFiles(true)
            ->date('<= now - ' . App::$session['options']['lifetime'] . ' seconds');

        foreach ($files as $file) {
            Filesystem::remove($file->getRealPath());
        }

        return true;
    }

    /**
     * @return string
     */
    public function id(): string
    {
        $id = (string)Context::get(Request::class)->cookie(Session::NAME);
        /**
         * 会话ID只允许安全字符集，杜绝目录穿越（../、绝对路径、%2f、空字节等）。
         * 合法会话ID由 Str::generateRandStr() 生成（32位十六进制），天然满足白名单；
         * 任何不合规的客户端值都会被映射为其哈希，落点始终限制在 runtime/session/ 目录内。
         */
        if (!preg_match('/^[A-Za-z0-9]{1,64}$/', $id)) {
            $id = md5($id);
        }
        return $id;
    }
}