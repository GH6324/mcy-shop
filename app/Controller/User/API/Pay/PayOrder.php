<?php
declare (strict_types=1);

namespace App\Controller\User\API\Pay;

use App\Controller\User\Base;
use App\Interceptor\PostDecrypt;
use App\Interceptor\Visitor;
use App\Interceptor\Waf;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Validator;
use Kernel\Context\Interface\Response;
use Kernel\Exception\RuntimeException;
use Kernel\Exception\ViewException;
use Kernel\Waf\Filter;

class PayOrder extends Base
{
    #[Inject]
    protected \App\Service\User\PayOrder $payOrder;


    /**
     * @return Response
     * @throws RuntimeException
     */
    #[Interceptor(class: [PostDecrypt::class, Waf::class, Visitor::class], type: Interceptor::API)]
    #[Validator([
        [\App\Validator\User\Order::class, "tradeNo"]
    ])]
    public function pay(): Response
    {
        $tradeNo = $this->request->post("trade_no");
        $this->assertOrderOwnership((string)$tradeNo); //归属校验：只能对自己的订单发起支付（防越权/跨用户发起支付）
        $method = (int)$this->request->post("method");
        $balance = (bool)$this->request->post("balance", Filter::BOOLEAN);
        $pay = $this->payOrder->pay($tradeNo, $method, $balance, $this->request->clientIp(), $this->request->url(), $this->getUser());
        return $this->json(200, "success", $pay->toArray());
    }

    /**
     * @throws RuntimeException
     */
    #[Interceptor(class: [PostDecrypt::class, Waf::class, Visitor::class], type: Interceptor::API)]
    #[Validator([
        [\App\Validator\User\Order::class, "tradeNo"]
    ])]
    public function getPayOrder(): Response
    {
        $tradeNo = $this->request->post("trade_no");
        $this->assertOrderOwnership((string)$tradeNo); //归属校验：只能查询自己的支付单
        $order = $this->payOrder->getPayOrder($tradeNo);
        return $this->json(data: $order->toArray());
    }

    /**
     * 订单归属校验：登录用户按 customer_id，游客按 client_id。
     * @param string $tradeNo
     * @return void
     * @throws \Kernel\Exception\JSONException
     */
    private function assertOrderOwnership(string $tradeNo): void
    {
        /**
         * @var \App\Model\Order $order
         */
        $order = \App\Model\Order::query()->where("trade_no", $tradeNo)->first();
        if (!$order) {
            throw new \Kernel\Exception\JSONException("订单不存在");
        }
        $customer = $this->getUser();
        $clientId = (string)$this->request->cookie("client_id");
        $ownByUser = $customer && (int)$order->customer_id === (int)$customer->id;
        $ownByClient = !empty($order->client_id) && $clientId !== "" && (string)$order->client_id === $clientId;
        if (!$ownByUser && !$ownByClient) {
            throw new \Kernel\Exception\JSONException("订单不存在");
        }
    }


    /**
     * @return Response
     * @throws ViewException
     */
    public function async(): Response
    {
        $tradeNo = $this->request->uriSuffix();
        if (!$tradeNo) {
            throw new ViewException("请勿随意修改URL");
        }
        return $this->payOrder->async($tradeNo, $this->request->clientIp());
    }

}
