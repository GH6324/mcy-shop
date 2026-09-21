<?php
declare(strict_types=1);

namespace App\Entity\Query;

class Save
{
    /**
     * 创建模型
     * @var string
     */
    public string $model;

    /**
     * @var int|null
     */
    public ?int $id = null;

    /**
     * 数据结构体
     * @var array
     */
    public array $map = [];

    /**
     * @var array
     */
    public array $forceMap = [];


    /**
     * 归属/作用域约束条件（列 => 值）。
     * 设置后，按 id 定位待修改记录时会附加这些 WHERE 条件；
     * 若客户端提供了 id 但在约束下查不到记录，则拒绝操作（不再静默新增），
     * 用于杜绝“凭自增 id 跨租户接管他人记录”的越权。
     * @var array
     */
    public array $where = [];


    /**
     * 中间表
     * @var array
     */
    public array $middle = [];


    /**
     * 是否可以修改
     * @var bool
     */
    public bool $isModifiable = true;

    /**
     * 是否可以新增
     * @var bool
     */
    public bool $isAddable = true;

    /**
     * @var bool
     */
    public bool $isAddCreateTime = false;


    /**
     * 新增字段白名单
     * @var array
     */
    public array $addWhitelist = [];

    /**
     * 修改字段白名单
     * @var array
     */
    public array $modifiableWhitelist = [];


    /**
     * @param string $model
     */
    public function __construct(string $model)
    {
        $this->model = $model;
    }

    /**
     * @return void
     */
    public function disableModifiable(): void
    {
        $this->isModifiable = false;
    }

    /**
     * @return void
     */
    public function disableAddable(): void
    {
        $this->isAddable = false;
    }

    /**
     * @return void
     */
    public function enableCreateTime(): void
    {
        $this->isAddCreateTime = true;
    }

    /**
     * @param array $map
     * @param array $bypass
     * @param array $forbidden
     * @return void
     */
    public function setMap(array $map, array $bypass = [], array $forbidden = []): void
    {
        if ($this->id === null) {
            $this->id = (isset($map['id']) && is_numeric($map['id'])) ? (int)$map['id'] : null;
        }

        foreach ($map as $key => $value) {
            $key = strtolower(trim((string)$key));
            if ($value === '' || $key == "id" || (!in_array($key, $bypass) && !empty($bypass))) { //$value === '' ||
                continue;
            }

            if (in_array($key, $forbidden) && !empty($forbidden)) {
                continue;
            }

            if (is_scalar($value)) {
                $this->addMap($key, trim((string)$value));
                continue;
            }

            $this->addMap($key, $value);
        }
    }

    /**
     * @param int $id
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function addMap(string $name, mixed $value): void
    {
        if (isset($this->map[$name])) {
            return;
        }
        $this->map[$name] = $value;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function addForceMap(string $name, mixed $value): void
    {
        if (isset($this->forceMap[$name])) {
            return;
        }
        $this->forceMap[$name] = $value;
    }

    /**
     * 追加归属/作用域约束（用于按 id 定位记录时的 WHERE 过滤）。
     * @param string $column
     * @param mixed $value
     * @return void
     */
    public function addWhere(string $column, mixed $value): void
    {
        $this->where[] = [$column, $value];
    }

    /**
     * @param string $key
     * @return array|null
     */
    public function getMiddle(string $key): ?array
    {
        if (!array_key_exists($key, $this->middle)) {
            return null;
        }
        return $this->middle[$key];
    }

    /**
     * @param string $key
     * @param string $middle
     * @param string $foreignKey
     * @param string $localKey
     * @return void
     */
    public function setMiddle(string $key, string $middle, string $foreignKey, string $localKey): void
    {
        $this->middle[$key] = [
            'middle' => $middle,
            'foreignKey' => $foreignKey,
            'localKey' => $localKey
        ];
    }

    /**
     * @param string ...$column
     * @return void
     */
    public function setAddWhitelist(string ...$column): void
    {
        $this->addWhitelist = $column;
    }

    /**
     * @param string ...$column
     * @return void
     */
    public function setModifiableWhitelist(string ...$column): void
    {
        $this->modifiableWhitelist = $column;
    }
}