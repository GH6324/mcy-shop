<?php
declare (strict_types=1);

namespace App\Validator\Store;

use Kernel\Annotation\Regex;
use Kernel\Annotation\Required;

class Install
{
    #[Required("要安装的插件不能为空")]
    #[Regex("/^[A-Za-z0-9_]+$/", "非法的插件标识")] //只允许字母数字下划线，杜绝 ../、%2f 等穿越写入/删除任意目录
    public function key(): bool
    {
        return true;
    }
}