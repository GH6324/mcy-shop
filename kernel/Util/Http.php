<?php
declare (strict_types=1);

namespace Kernel\Util;

use GuzzleHttp\Client;

class Http
{

    /**
     * @param array $opt
     * @return Client
     */
    public static function make(array $opt = []): Client
    {
        //默认校验对端 TLS 证书（缓解中间人窃取商店Token/支付/短信凭据）。
        //优先使用系统 CA bundle 的显式路径，最大化跨环境可用性；找不到时回退为启用系统默认校验。
        //调用方仍可通过 $opt 覆盖 verify。
        return new Client(array_merge(["verify" => self::caBundle()], $opt));
    }

    /**
     * @return string|bool 证书校验设置：CA bundle 路径，或 true（使用系统默认）
     */
    public static function caBundle(): string|bool
    {
        static $ca = null;
        if ($ca === null) {
            $ca = true;
            foreach (['/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt', '/etc/ssl/cert.pem'] as $path) {
                if (is_file($path)) {
                    $ca = $path;
                    break;
                }
            }
        }
        return $ca;
    }


}