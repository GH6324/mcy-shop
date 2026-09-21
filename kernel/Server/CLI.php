<?php
declare(strict_types=1);

namespace Kernel\Server;


use Kernel\Cache\Cache;
use Kernel\Component\Singleton;
use Kernel\Container\Di;
use Kernel\Context\App;
use Kernel\Context\App as AppContext;
use Kernel\Context\Interface\Request;
use Kernel\Context\Interface\Response;
use Kernel\Database\MysqlConnection;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;
use Kernel\Log\Const\Color;
use Kernel\Plugin\Const\Point;
use Kernel\Plugin\Const\WebSocket;
use Kernel\Plugin\Plugin;
use Kernel\Plugin\Usr;
use Kernel\Pool\ConnectionPool;
use Kernel\Session\Manager;
use Kernel\Util\Config;
use Kernel\Util\Context;
use Kernel\Util\Date;
use Kernel\Log\Log;
use Kernel\Util\Process;
use Kernel\Util\Str;
use Kernel\Util\System;
use Swoole\Constant;
use Swoole\Coroutine;
use Swoole\Server\Task;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class CLI
{
    use Singleton;

    /**
     * @var string
     */
    public string $name;


    /**
     * 配置文件
     * @var array
     */
    private array $config;


    /**
     * @var Server
     */
    private Server $httpServer;

    /**
     * @var int
     */
    private int $workerId = 0;

    /**
     * 静态资源根目录白名单（相对 BASE_PATH），懒加载缓存
     * @var array|null
     */
    private ?array $staticLocations = null;

    public function __construct()
    {
        $this->config = Config::get("cli-server");
        $this->name = $this->config['name'] . "." . $this->config['port'];
        Process::setName($this->name . ".main");
    }

    /**
     * @return Server
     */
    public function getHttpServer(): Server
    {
        return $this->httpServer;
    }

    /**
     * @param string|null $key
     * @return mixed
     */
    public function getConfig(?string $key = null): mixed
    {
        return $key ? $this->config[$key] : $this->config;
    }


    /**
     * @return int
     */
    public function getWorkerId(): int
    {
        return $this->workerId;
    }

    /**
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function start(): void
    {

        Log::inst()->stdout("[START]:正在啓動，請稍等..", Color::GREEN, true);
        Plugin::instance()->hook(App::env(), Point::CLI_INIT_BEFORE);
        Log::inst()->stdout("[Version]:" . AppContext::$version, Color::GREEN, true);
        Log::inst()->stdout("[Swoole]:" . swoole_version(), Color::GREEN, true);
        Log::inst()->stdout("[PHP-CLI]:" . PHP_VERSION, Color::GREEN, true);

        $this->startMemoryTable();
        App::$install && $this->startMysql();
        App::$install && $this->startProcess();


        Log::inst()->stdout("[REACTOR]:共({$this->config['options']['reactor_num']})個綫程啓動", Color::YELLOW, true);
        Log::inst()->stdout("[TASK]:共({$this->config['options']['task_worker_num']})個綫程啓動", Color::YELLOW, true);
        Log::inst()->stdout("[HTTP]:共({$this->config['options']['worker_num']})個綫程啓動", Color::YELLOW, true);

        $this->startHttpServer();
    }

    /**
     * 启动MYSQL连接池
     * @return void
     */
    private function startMysql(): void
    {
        #启动mysql连接池
        Coroutine\run(function () {
            Di::instance()->set(ConnectionPool::class, new ConnectionPool(MysqlConnection::class, AppContext::$database['pool']));
            Log::inst()->stdout("[MYSQL]:連接池啓動，峰值：" . (AppContext::$database['pool'] * $this->config["options"][Constant::OPTION_WORKER_NUM]), Color::YELLOW, true);
        });
    }


    /**
     * 启动插件进程池
     * @throws \ReflectionException
     */
    private function startProcess(): void
    {
        #启动应用重启进程
        \Kernel\Service\App::inst()->startRestartWaitProcess();
        #启动插件进程池
        \Kernel\Plugin\Process::inst()->started();
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    private function startMemoryTable(): void
    {
        $size = Cache::instance()->initialize();
        Log::inst()->stdout("[CACHE]:啓動内存分配，容量：" . $size . "M", Color::YELLOW, true);
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    private function startHttpServer(): void
    {
        if (!System::checkPortAvailable($this->config['port'])) {
            Log::inst()->stdout("启动失败，[{$this->config['host']}:{$this->config['port']}]已被占用", Color::RED, true);
            return;
        }

        $this->httpServer = new Server($this->config['host'], $this->config['port'], SWOOLE_BASE);
        $this->httpServer->set($this->config['options']);
        $this->httpServer->on('Request', [$this, "httpRequest"]);
        $this->httpServer->on('Message', [$this, "webSocketMessage"]);
        $this->httpServer->on('Open', [$this, "webSocketOpen"]);
        $this->httpServer->on('Close', [$this, "webSocketClose"]);
        $this->httpServer->on('Task', [$this, "task"]);
        $this->httpServer->on('Start', [$this, "httpStart"]);
        $this->httpServer->on('WorkerStart', [$this, "workerStart"]);
        $this->httpServer->on('PipeMessage', [$this, "pipeMessage"]);
        $this->httpServer->start();
    }


    /**
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @throws LoaderError
     * @throws NotFoundException
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws \ReflectionException
     */
    public function httpRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response): void
    {
        try {
            //由本进程接管静态资源，替代 Swoole 内置静态处理器（内置处理器无法防御 %2e%2e/%2f 编码目录穿越）
            if ($this->tryServeStatic($request, $response)) {
                return;
            }

            if (trim((string)$request->server['request_uri'], "/") != "wait/state") {
                //记录最后一次访问时间
                Cache::inst()->set(Swoole\Constant::CLI_LAST_REQUEST_TIME, time());
            }
            $req = new \Kernel\Context\CLI\Request($request);
            $resp = new \Kernel\Context\CLI\Response($response);
            //context
            Context::set(Request::class, $req);
            Context::set(Response::class, $resp);
            Manager::instance()->create();
            //call
            $resp = Http::instance()->call($req);
            if ($resp instanceof Response) {
                $resp->draw();
                return;
            }
            throw new RuntimeException("controller return value should be：" . Response::class);
        } catch (\Throwable $e) {
            $response = Context::get(Response::class);
            $message = AppContext::error($e, $response);
            $response->serverResponse->end($message);
        }
    }


    /**
     * @param Server $server
     * @param \Swoole\Http\Request $request
     * @return void
     * @throws \ReflectionException
     */
    public function webSocketOpen(Server $server, \Swoole\Http\Request $request): void
    {
        if (!isset($request->header['upgrade'])) {
            return;
        }

        $req = new \Kernel\Context\CLI\Request($request);
        Context::set(Request::class, $req);


        $uri = trim($req->uri(), "/");

        if ($request->server['remote_addr'] == WebSocket::LOCALHOST && $uri == "push") {
            Cache::instance()->set(sprintf(WebSocket::FD_KEY, $request->fd), [
                WebSocket::MESSAGE_TYPE => WebSocket::MESSAGE_TYPE_PUSH,
                WebSocket::MESSAGE_FD => $request->fd,
                WebSocket::MESSAGE_WORKER_ID => CLI::instance()->getWorkerId()
            ]);
            return;
        }

        $route = Usr::inst()->getRoute($uri);

        if (!$route) {
            $server->close($request->fd);
            return;
        }

        \Kernel\Plugin\WebSocket::instance()->open($route->name, Usr::inst()->userToEnv($route->usr), $server, $req, $request->fd);
    }

    /**
     * @param Server $server
     * @param int $fd
     * @return void
     * @throws \ReflectionException
     */
    public function webSocketClose(Server $server, int $fd): void
    {
        \Kernel\Plugin\WebSocket::instance()->close($server, $fd);
    }

    /**
     * @param Server $server
     * @param Frame $frame
     * @return void
     * @throws \ReflectionException
     */
    public function webSocketMessage(Server $server, Frame $frame): void
    {
        \Kernel\Plugin\WebSocket::instance()->message($server, $frame);
    }

    /**
     * @param Server $server
     * @param Task $task
     * @return void
     */
    public function task(Server $server, Task $task): void
    {
        $callable = $task->data['callable'] ?? false;
        if ($callable instanceof \Kernel\Task\Interface\Task) {
            $task->finish($callable->handle());
        }
    }


    /**
     * @param Server $server
     * @return void
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function httpStart(Server $server): void
    {
        Manager::instance()->gc();  //启动session gc回收
        //将server加入容器
        Plugin::instance()->hook(App::env(), Point::CLI_INIT_AFTER);
        $startTime = (Date::timestamp() - App::$startTime) / 1000;


        Log::inst()->stdout("HTTP高性能サーバーの起動に成功!", Color::GREEN, true);
        Log::inst()->stdout("Listening server: " . $this->config['host'] . ":" . $this->config['port'], Color::RED, true);
        Log::inst()->stdout("总耗时: {$startTime}秒", Color::BLACK, true);

        if (!App::$install) {
            Log::inst()->stdout("系统检测到您尚未完成安装，请使用浏览器访问：http://服务器IP:{$this->config['port']} 进行安装操作", Color::RED, true);
            Log::inst()->stdout("系統檢測到您尚未完成安裝，請使用瀏覽器訪問：http://伺服器IP:{$this->config['port']} 進行安裝操作", Color::RED, true);
            Log::inst()->stdout("The system has detected that you have not completed the installation. Please use your browser to visit: http://ip:{$this->config['port']} to complete the installation", Color::RED, true);
            Log::inst()->stdout("システムは、インストールが完了していないことを検出しました。ブラウザを使用して以下のURLにアクセスしてください：http://サーバーIP:{$this->config['port']} インストールを完了してください", Color::RED, true);
            Log::inst()->stdout("");
            Log::inst()->stdout("注意：请勿关闭SSH或中断程序，否则安装将失败 / Note: Do not close SSH or interrupt the program, otherwise the installation will fail", Color::BLUE, true);
        }
    }


    /**
     * @param Server $server
     * @param int $workerId
     * @return void
     * @throws \ReflectionException
     */
    public function workerStart(Server $server, int $workerId): void
    {
        $this->workerId = $workerId;

        if ($workerId >= $server->setting['worker_num']) {
            Process::setName($this->name . ".task#{$workerId}");
        } else {
            Process::setName($this->name . ".worker#{$workerId}");
            \Kernel\Plugin\WebSocket::instance()->setServer($server);
            \Kernel\Plugin\WebSocket::instance()->deathCheck($workerId);
        }
    }

    /**
     * @param Server $server
     * @param int $srcWorkerId
     * @param string $message
     * @return void
     * @throws \ReflectionException
     */
    public function pipeMessage(Server $server, int $srcWorkerId, mixed $message): void
    {
        $json = is_array($message) ? $message : [];
        if (isset($json['type']) && $json['type'] == "ws_push") {
            \Kernel\Plugin\WebSocket::instance()->pipeMessage($server, $json['data'] ?? []);
        }
    }

    /**
     * 安全的静态资源服务：对规范化后的真实路径做严格的目录归属校验，
     * 杜绝 ../、%2e%2e、%2f 等（编码）目录穿越读取站点根内任意文件（如 config/database.php）。
     *
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @return bool 命中静态文件并已发送则返回 true；否则 false（交由正常路由处理）
     */
    private function tryServeStatic(\Swoole\Http\Request $request, \Swoole\Http\Response $response): bool
    {
        $method = strtoupper((string)($request->server['request_method'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        $path = (string)parse_url((string)($request->server['request_uri'] ?? ''), PHP_URL_PATH);
        if ($path === '' || $path === '/') {
            return false;
        }

        //解码（覆盖 %2e %2f 等编码），并拒绝空字节
        $path = rawurldecode($path);
        if (str_contains($path, "\0")) {
            return false;
        }

        if ($this->staticLocations === null) {
            $this->staticLocations = array_merge(['/assets', '/favicon.ico'], \Kernel\Plugin\Assets::inst()->list());
        }

        $matched = null;
        foreach ($this->staticLocations as $loc) {
            $loc = '/' . trim((string)$loc, '/');
            if ($loc === '/') {
                continue;
            }
            if ($path === $loc || str_starts_with($path, $loc . '/')) {
                $matched = $loc;
                break;
            }
        }
        if ($matched === null) {
            return false;
        }

        $real = realpath(BASE_PATH . $path);
        if ($real === false || !is_file($real)) {
            return false;
        }

        //真实路径必须严格位于被匹配的静态目录内（或就是该文件本身），否则判定为穿越攻击
        $base = realpath(BASE_PATH . $matched);
        if ($base === false) {
            return false;
        }
        if ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return false;
        }

        $mtime = (int)@filemtime($real);
        $response->header("Content-Type", $this->staticMimeType($real));
        $response->header("X-Content-Type-Options", "nosniff");
        $response->header("Cache-Control", "no-cache");
        if ($mtime > 0) {
            $response->header("Last-Modified", gmdate("D, d M Y H:i:s", $mtime) . " GMT");
            $ims = $request->header['if-modified-since'] ?? null;
            if ($ims !== null && @strtotime((string)$ims) >= $mtime) {
                $response->status(304);
                $response->end();
                return true;
            }
        }
        if ($method === 'HEAD') {
            $response->header("Content-Length", (string)filesize($real));
            $response->end();
            return true;
        }
        $response->sendfile($real);
        return true;
    }

    /**
     * @param string $file
     * @return string
     */
    private function staticMimeType(string $file): string
    {
        static $map = [
            'css' => 'text/css', 'js' => 'application/javascript', 'mjs' => 'application/javascript',
            'json' => 'application/json', 'map' => 'application/json', 'xml' => 'application/xml',
            'html' => 'text/html', 'htm' => 'text/html', 'txt' => 'text/plain',
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
            'avif' => 'image/avif', 'apng' => 'image/apng',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'pdf' => 'application/pdf', 'wasm' => 'application/wasm', 'zip' => 'application/zip',
            'apk' => 'application/vnd.android.package-archive',
        ];
        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        $type = $map[$ext] ?? 'application/octet-stream';
        if (in_array($ext, ['css', 'js', 'mjs', 'json', 'map', 'xml', 'html', 'htm', 'txt', 'svg'], true)) {
            $type .= '; charset=utf-8';
        }
        return $type;
    }
}