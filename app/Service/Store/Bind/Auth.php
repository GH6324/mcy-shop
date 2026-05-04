<?php
declare (strict_types=1);

namespace App\Service\Store\Bind;

use App\Entity\Store\Login;
use Kernel\Annotation\Inject;
use Kernel\Exception\ServiceException;

class Auth implements \App\Service\Store\Auth
{

    #[Inject]
    private \App\Service\Store\Http $http;

    /**
     * @param string $type
     * @return string
     * @throws ServiceException
     */
    public function captcha(string $type): string
    {
        $http = $this->http->request("/auth/captcha", ["type" => $type]);

        if (!isset($http->data["raw"])) {
            throw new ServiceException("图形验证码获取失败");
        }
        return base64_decode($http->data["raw"]);
    }

    /**
     * @param string $username
     * @param string $password
     * @param string $captcha
     * @return Login
     * @throws ServiceException
     */
    public function login(string $username, string $password, string $captcha): Login
    {
        $http = $this->http->request("/auth/login",
            [
                "username" => $username,
                "password" => $password,
                "captcha" => $captcha
            ]
        );

        if ($http->code != 200) {
            throw new ServiceException($http->message);
        }

        return new Login($http->data);
    }

    /**
     * @param string $username
     * @param string $password
     * @param string $email
     * @param string $code
     * @param string $captcha
     * @return Login
     * @throws ServiceException
     */
    public function register(string $username, string $password, string $email, string $code, string $captcha): Login
    {
        $http = $this->http->request("/auth/register",
            [
                "username" => $username,
                "password" => $password,
                "email" => $email,
                "code" => $code,
                "captcha" => $captcha
            ]
        );

        if ($http->code != 200) {
            throw new ServiceException($http->message);
        }

        return new Login($http->data);
    }


    /**
     * @param string $type
     * @param string $email
     * @param string $captcha
     * @return void
     * @throws ServiceException
     */
    public function sendEmail(string $type, string $email, string $captcha): void
    {
        $http = $this->http->request("/auth/email/code?type={$type}", ["captcha" => $captcha, "email" => $email]);
        if ($http->code != 200) {
            throw new ServiceException($http->message);
        }
    }

    /**
     * @param string $email
     * @param string $password
     * @param string $code
     * @param string $captcha
     * @return Login
     * @throws ServiceException
     */
    public function reset(string $email, string $password, string $code, string $captcha): Login
    {
        $http = $this->http->request("/auth/reset",
            [
                "password" => $password,
                "email" => $email,
                "code" => $code,
                "captcha" => $captcha
            ]
        );

        if ($http->code != 200) {
            throw new ServiceException($http->message);
        }

        return new Login($http->data);
    }
}