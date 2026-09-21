<?php
declare (strict_types=1);

namespace Kernel\Language\Entity;

class Language
{
    public string $preferred;

    /**
     * @param string $preferred
     */
    public function __construct(string $preferred)
    {
        $preferred = strtolower(trim($preferred));
        //语言代码白名单：仅允许字母、数字、连字符、下划线，杜绝借语言值(如 Cookie)拼接路径穿越读取任意 .json
        if ($preferred === '' || !preg_match('/^[a-z0-9_-]{1,32}$/', $preferred)) {
            $preferred = 'zh-cn';
        }
        $this->preferred = $preferred;
    }
}