<?php
declare(strict_types=1);

/**
 *+------------------
 * madong
 *+------------------
 * Copyright (c) https://gitee.com/motion-code  All rights reserved.
 *+------------------
 * Author: Mr. April (405784684@qq.com)
 *+------------------
 * Official Website: http://www.madong.tech
 */
namespace core\foundation\base;

use app\adminapi\CurrentUser;
use app\model\system\recycle\RecycleBin;
use app\service\admin\system\recycle\RecycleBinService;
use Carbon\Carbon;
use core\foundation\exception\handler\AdminException;
use core\io\uuid\Snowflake;
use Illuminate\Database\Eloquent\SoftDeletes;
use support\Container;
use support\Model;

class BaseModel extends Model
{

    /**
     * 指明模型的ID是否自动递增。
     * false = 雪花ID（默认）；true = 数据库自增ID
     * 子类如需自增ID，覆盖为: public $incrementing = true;
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * 主键类型
     * 雪花ID默认为 'string'，自增ID子类应覆盖为: protected $keyType = 'int';
     *
     * @var string
     */
    protected $keyType = 'string';

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    const DELETED_AT = 'deleted_at';

    protected $appends = [];

    /**
     * 隐藏属性
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * 模型日期字段的存储格式。
     *
     * @var string
     */
    protected $dateFormat = 'U';

    /**
     * 指示模型是否主动维护时间戳。
     *
     * @var bool
     */
    public $timestamps = true;

    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    protected static function boot()
    {
        parent::boot();

        //注册创建事件
        static::creating(function ($model) {
            // 仅非自增主键时自动生成雪花ID
            if (!$model->getIncrementing() && !isset($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string)Snowflake::generate();
            }
            self::setCreatedBy($model);
        });

        // 注册更新事件
        static::updating(function ($model) {
            self::setUpdatedBy($model);
        });

        // 注册删除事件
        static::deleted(function ($model) {
            self::onAfterDelete($model);
        });
    }

    /**
     * 是否开启软删
     *
     * @return bool
     */
    public static function isSoftDeleteEnabled(): bool
    {
        return in_array(SoftDeletes::class, class_uses(static::class));
    }

    /**
     * 获取主键名称
     *
     * @return string
     */
    public function getPk(): string
    {
        return $this->getKeyName();
    }

    /**
     * 获取模型字段数据
     *
     * @param string $field
     *
     * @return mixed
     */
    public function getData(string $field): mixed
    {
        return $this->attributes[$field] ?? null;
    }

    /**
     * 写入模型字段数据
     *
     * @param string $name
     * @param mixed  $value
     */
    public function set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * 获取模型的字段列表
     *
     * @return array
     */
    public function getFields(): array
    {
        try {
            $tableName     = $this->getTable();
            $connection    = $this->getConnection();
            $prefix        = $connection->getTablePrefix();
            $fullTableName = $prefix . $tableName;
            $fields        = $connection->select("SHOW COLUMNS FROM `{$fullTableName}`");
            return array_map(function ($column) {
                return $column->Field;
            }, $fields);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 追加创建时间
     *
     * @return string|null
     */
    public function getCreatedDateAttribute(): ?string
    {
        if ($this->getAttribute($this->getCreatedAtColumn())) {
            try {
                $timestamp = $this->getRawOriginal($this->getCreatedAtColumn());
                if (empty($timestamp)) {
                    return null;
                }
                $carbonInstance = Carbon::createFromTimestamp($timestamp);
                return $carbonInstance->setTimezone(config('app.default_timezone'))->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * 追加更新时间
     *
     * @return string|null
     */
    public function getUpdatedDateAttribute(): ?string
    {
        if ($this->getAttribute($this->getUpdatedAtColumn())) {
            try {
                $timestamp = $this->getRawOriginal($this->getUpdatedAtColumn());
                if (empty($timestamp)) {
                    return null;
                }
                $carbonInstance = Carbon::createFromTimestamp($timestamp);
                return $carbonInstance->setTimezone(config('app.default_timezone'))->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * 删除事件
     *
     * @param \support\Model $model
     *
     * @throws \core\foundation\exception\handler\AdminException
     */
    public static function onAfterDelete(Model $model)
    {
        try {
            $table = $model->getTable();

            // 防止回收站自引用死循环
            if ($table === 'sys_recycle_bin') {
                return;
            }

            /** @var RecycleBinService $recycleService */
            $service = Container::make(RecycleBinService::class);
            $config  = $service->getTableConfig($table);

            // 检查是否启用回收站
            if (!$config['enabled']) {
                return;
            }

            $prefix = $model->getConnection()->getTablePrefix();

            // 准备数据（排除敏感字段）
            $excludeFields = array_merge(
                config('recycle_bin.exclude_fields', []),
                $config['exclude_fields'] ?? []
            );
            $tableData                = array_except($model->getAttributes(), $excludeFields);
            $tableData['original_id'] = $model->getAttribute($model->getPk());

            // 收集关联表数据
            $relations   = $config['relations'] ?? [];
            $relationData = [];
            foreach ($relations as $relation) {
                $relationName = $relation['name'];
                if (method_exists($model, $relationName)) {
                    $relatedData = $model->{$relationName}()->get();
                    $relationData[$relationName] = $relatedData->toArray();
                }
            }

            $data = self::prepareRecycleBinData($tableData, $table, $prefix, $relationData);
            
            $recycleModel = new RecycleBin();
            $recycleModel->create($data);
        } catch (\Exception $e) {
            throw new AdminException($e->getMessage());
        }
    }

    /**
     * 设置创建人
     *
     * @param Model $model
     *
     * @return void
     */
    private static function setCreatedBy(Model $model): void
    {
        $uid = Container::make(CurrentUser::class)->id();
        if ($uid && $model->isFillable('created_by')) {
            $model->setAttribute('created_by', $uid);
        }
    }

    /**
     * 设置更新人
     *
     * @param Model $model
     *
     * @return void
     */
    private static function setUpdatedBy(Model $model): void
    {
        $uid = Container::make(CurrentUser::class)->id();
        if ($uid && $model->isFillable('updated_by')) {
            $model->setAttribute('updated_by', $uid);
        }
    }

    private static function prepareRecycleBinData($tableData, $table, $prefix, $relationData = []): array
    {
        $data = [
            'original_id' => $tableData['original_id'] ?? ($tableData['id'] ?? ''),
            'data'        => json_encode($tableData, JSON_UNESCAPED_UNICODE),
            'table_name'  => $table,
            'table_prefix' => $prefix,
            'enabled'     => 0,
            'ip'          => request()->getRealIp(),
            'operate_by'  => Container::make(CurrentUser::class)->id(),
            'created_at'  => time(),
            'updated_at'  => time(),
        ];
        
        // 只有当 relation_data 字段存在时才添加
        // 避免字段不存在时的 SQL 错误
        try {
            $recycleTable = config('database.connections.mysql.prefix', '') . 'sys_recycle_bin';
            if (\support\DB::getSchemaBuilder()->hasColumn($recycleTable, 'relation_data')) {
                $data['relation_data'] = json_encode($relationData, JSON_UNESCAPED_UNICODE);
            }
        } catch (\Exception $e) {
            // 忽略错误，不添加 relation_data 字段
        }
        
        return $data;
    }

    /**
     * 动态获取数据库连接名
     */
    public function getConnectionName()
    {
        return parent::getConnectionName();
    }

}
