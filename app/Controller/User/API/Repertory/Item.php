<?php
declare (strict_types=1);

namespace App\Controller\User\API\Repertory;

use App\Controller\User\Base;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\PostDecrypt;
use App\Interceptor\Supplier;
use App\Interceptor\User;
use App\Interceptor\Waf;
use App\Model\RepertoryItem as Model;
use App\Model\RepertoryItemSku;
use App\Service\Common\Query;
use App\Service\Common\RepertoryItem;
use App\Service\Common\Ship;
use App\Validator\Common;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Relations\HasMany;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Validator;
use Kernel\Context\Interface\Response;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Plugin\Plugin;
use Kernel\Plugin\Usr;
use Kernel\Util\Str;
use Kernel\Waf\Filter;

#[Interceptor(class: [PostDecrypt::class, Waf::class, User::class, Supplier::class], type: Interceptor::API)]
class Item extends Base
{

    #[Inject]
    private Query $query;

    #[Inject]
    private RepertoryItem $repertoryItem;

    #[Inject]
    private \App\Service\Common\RepertoryItemSku $sku;

    #[Inject]
    private Ship $ship;

    /**
     * @return Response
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    #[Validator([
        [Common::class, ["page", "limit"]]
    ])]
    public function get(): Response
    {
        $map = $this->request->post();
        $get = new Get(Model::class);
        $get->setWhere($map);

        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setOrderBy(...$this->query->getOrderBy($map, "sort", "asc"));
        $raw = [];

        $data = $this->query->get($get, function (Builder $builder) use (&$raw) {
            $builder = $builder->where("user_id", $this->getUser()->id);

            $raw['under_review_count'] = (clone $builder)->where("status", 0)->count();
            $raw['shelves_not_count'] = (clone $builder)->where("status", 1)->count();
            $raw['shelves_have_count'] = (clone $builder)->where("status", 2)->count();
            $raw['banned_count'] = (clone $builder)->where("status", 3)->count();

            return $builder->with(['sku' => function (HasMany $hasMany) {
                $hasMany->orderBy("sort", "asc")->select(["id", "name", "repertory_item_id", "picture_thumb_url", "picture_url", "supply_price", "cost"]);
            }])
                ->withCount("order as order_count")
                ->withCount("todayOrder as today_count")
                ->withCount("yesterdayOrder as yesterday_count")
                ->withCount("weekdayOrder as weekday_count")
                ->withCount("monthOrder as month_count")
                ->withCount("lastMonthOrder as last_month_count")
                ->withCount("userItem as user_item_count");
        });

        $env = Usr::inst()->userToEnv($this->getUser()->id);

        foreach ($data['list'] as &$dat) {
            $plugin = Plugin::instance()->getPlugin($dat['plugin'], $env);
            if ($plugin) {
                $dat["plugin_name"] = $plugin->info['name'] . "(v{$plugin->info['version']})";
            }

            foreach ($dat['sku'] as &$sku) {
                try {
                    $sku['stock'] = $this->ship->stock($sku['id']);
                } catch (\Throwable $e) {
                    $sku['stock'] = "异常";
                }
            }
        }

        return $this->json(data: array_merge($data, $raw));
    }

    /**
     * @return Response
     * @throws JSONException
     * @throws RuntimeException
     */
    #[Validator([
        [\App\Validator\Admin\Item::class, "name"]
    ])]
    public function save(): Response
    {
        $reExaminationFields = ["picture_url", "picture_thumb_url", "name", "introduce"];

        $save = new Save(Model::class);
        $map = $this->request->post(flags: Filter::NORMAL);
        $save->enableCreateTime();

        $skuTempId = $map['sku_temp_id'] ?? "";
        unset($map['sku_temp_id']);
        if (!isset($map['id'])) {
            $count = RepertoryItemSku::query()->where("temp_id", $skuTempId)->where("user_id", $this->getUser()->id)->count();
            if ($count <= 0) {
                throw new JSONException("根据系统的逻辑，每个商品必须至少添加一个SKU。");
            }
            $map['api_code'] = Str::generateRandStr(5);
        } else {
            $item = Model::where("user_id", $this->getUser()->id)->find($map['id']);
            if (!$item) {
                throw new JSONException("商品不存在");
            }

            foreach ($reExaminationFields as $field) {
                if (isset($map[$field])) {
                    if (trim($item->$field) != trim($map[$field])) {
                        $save->addForceMap("status", 0);
                        break;
                    }
                }
            }
        }

        $save->addForceMap("user_id", $this->getUser()->id);
        $save->setMap(map: $map, forbidden: ["user_id", "status", "sort", "create_time"]);
        try {
            if (isset($map['id'])) {
                //刷新缓存
                $repertoryItem = Model::find($map['id']);
                if ((isset($map['plugin']) && $repertoryItem->plugin != $map['plugin']) || (isset($map['status']) && $repertoryItem->status != $map['status'])) {
                    $this->sku->syncCacheForItem($repertoryItem->id);
                }
                $this->repertoryItem->forceSyncRemoteItemPrice((int)$map['id']);
            }
            $saved = $this->query->save($save);
            if (!isset($map['id'])) {
                RepertoryItemSku::query()->where("temp_id", $skuTempId)->where("user_id", $this->getUser()->id)->update([
                    "repertory_item_id" => $saved->id
                ]);
            }
        } catch (\Exception $exception) {
            throw new JSONException("保存失败，错误：" . $exception->getMessage());
        }
        return $this->json(message: "保存成功");
    }

    /**
     * @return Response
     * @throws RuntimeException
     */
    #[Validator([
        [Common::class, ["status", "list"]]
    ])]
    public function updateStatus(): Response
    {
        $list = (array)$this->request->post("list", Filter::INTEGER);
        $status = $this->request->post("status", Filter::INTEGER);
        \App\Model\RepertoryItem::query()->where("user_id", $this->getUser()->id)->where("status", "!=", 0)->whereIn("id", $list)->update(["status" => $status == 1 ? 2 : 1]);
        return $this->json();
    }

    /**
     * @return Response
     * @throws RuntimeException
     */
    public function del(): Response
    {
        $delete = new Delete(Model::class, (array)$this->request->post("list"));
        $delete->setWhere("user_id", $this->getUser()->id);
        $this->query->delete($delete);
        return $this->json(message: "删除成功");
    }
}