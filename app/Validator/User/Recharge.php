<?php
declare (strict_types=1);

namespace App\Validator\User;

use Kernel\Annotation\Regex;
use Kernel\Annotation\Required;

class Recharge
{
    #[Required("金额不能为空")]
    //必须为正数金额（排除 0 / 0.00），避免生成 0 元充值订单产生垃圾数据
    #[Regex("/^(?!0+(\.0{1,2})?$)[0-9]+(\.[0-9]{1,2})?$/", "金额错误")]
    public function amount(): bool
    {
        return true;
    }
}