<?php
declare (strict_types=1);

namespace App\Service\Store;

use App\Entity\Store\Login;
use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Store\Bind\Auth::class)]
interface Auth
{

    /**
     * @param string $type
     * @return string
     */
    public function captcha(string $type): string;


    /**
     * @param string $username
     * @param string $password
     * @param string $captcha
     * @return Login
     */
    public function login(string $username, string $password, string $captcha): Login;


    /**
     * @param string $username
     * @param string $password
     * @param string $email
     * @param string $code
     * @param string $captcha
     * @return Login
     */
    public function register(string $username, string $password, string $email, string $code, string $captcha): Login;


    /**
     * @param string $email
     * @param string $password
     * @param string $code
     * @param string $captcha
     * @return Login
     */
    public function reset(string $email, string $password, string $code, string $captcha): Login;

    /**
     * @param string $type
     * @param string $email
     * @param string $captcha
     * @return void
     */
    public function sendEmail(string $type, string $email, string $captcha): void;
}