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

use core\foundation\base\BaseModel;
use core\business\service\DynamicTableService;

class DynamicModel extends BaseModel
{
    protected $dynamicTable;

    protected $tablePrefix = 'dyn_';

    protected $tableSuffix = '';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $softDelete = false;

    protected $schemaCacheKey = 'dynamic_table_schema:';

    protected static $dynamicTableService;

    protected static function getDynamicTableService(): DynamicTableService
    {
        if (self::$dynamicTableService === null) {
            self::$dynamicTableService = new DynamicTableService();
        }
        return self::$dynamicTableService;
    }

    public function setDynamicTable(string $table): self
    {
        $this->dynamicTable = $table;
        $this->table = $this->buildTableName($table);
        return $this;
    }

    public function getDynamicTable(): string
    {
        return $this->dynamicTable ?? '';
    }

    protected function buildTableName(string $table): string
    {
        return $this->tablePrefix . $table . $this->tableSuffix;
    }

    public function setTablePrefix(string $prefix): self
    {
        $this->tablePrefix = $prefix;
        return $this;
    }

    public function setTableSuffix(string $suffix): self
    {
        $this->tableSuffix = $suffix;
        return $this;
    }

    public function getName()
    {
        return $this->dynamicTable ?? parent::getName();
    }

    public static function createDynamic(string $table, array $schema = [])
    {
        $model = new static();
        $model->setDynamicTable($table);

        if (!empty($schema)) {
            $model->parseSchema($schema);
        }

        return $model;
    }

    protected function parseSchema(array $schema): void
    {
        if (isset($schema['pk'])) {
            $this->pk = $schema['pk'];
        }

        if (isset($schema['autoWriteTimestamp'])) {
            $this->autoWriteTimestamp = $schema['autoWriteTimestamp'];
        }

        if (isset($schema['createTime'])) {
            $this->createTime = $schema['createTime'];
        }

        if (isset($schema['updateTime'])) {
            $this->updateTime = $schema['updateTime'];
        }

        if (isset($schema['softDelete'])) {
            $this->softDelete = $schema['softDelete'];
        }
    }

    public static function tableExists(string $table): bool
    {
        return self::getDynamicTableService()->tableExists($table);
    }

    public static function getTableSchema(string $table): array
    {
        $cacheKey = self::$schemaCacheKey . $table;
        $ttl = config('cache.ttl', 3600);

        try {
            return cache()->remember($cacheKey, $ttl, function () use ($table) {
                return self::getDynamicTableService()->getTableSchema($table);
            });
        } catch (\Exception $e) {
            return self::getDynamicTableService()->getTableSchema($table);
        }
    }

    public static function createTable(string $table, array $fields, array $options = []): bool
    {
        $result = self::getDynamicTableService()->createTable($table, $fields, $options);

        cache()->delete(self::$schemaCacheKey . $table);

        return $result;
    }

    public static function updateTableSchema(string $table, array $changes): bool
    {
        $result = self::getDynamicTableService()->updateTableSchema($table, $changes);

        cache()->delete(self::$schemaCacheKey . $table);

        return $result;
    }

    public static function dropTable(string $table): bool
    {
        $result = self::getDynamicTableService()->dropTable($table);

        cache()->delete(self::$schemaCacheKey . $table);

        return $result;
    }

    public function addField(string $field, string $type, array $options = []): bool
    {
        $table = $this->getDynamicTable();
        if (!$table) {
            throw new \Exception('Dynamic table name not set');
        }

        $result = self::getDynamicTableService()->addField($table, $field, $type, $options);

        cache()->delete(self::$schemaCacheKey . $table);

        return $result;
    }

    public function dropField(string $field): bool
    {
        $table = $this->getDynamicTable();
        if (!$table) {
            throw new \Exception('Dynamic table name not set');
        }

        $result = self::getDynamicTableService()->dropField($table, $field);

        cache()->delete(self::$schemaCacheKey . $table);

        return $result;
    }

    public function modifyField(string $field, string $type, array $options = []): bool
    {
        $table = $this->getDynamicTable();
        if (!$table) {
            throw new \Exception('Dynamic table name not set');
        }

        $result = self::getDynamicTableService()->modifyField($table, $field, $type, $options);

        cache()->delete(self::$schemaCacheKey . $table);

        return $result;
    }

    public function getAvailableFields(): array
    {
        $table = $this->getDynamicTable();
        if (!$table) {
            return [];
        }

        $schema = self::getTableSchema($table);

        $systemFields = ['create_time', 'update_time', 'delete_time', 'id'];
        $allowedFields = [];

        foreach ($schema as $field => $definition) {
            if (in_array($field, $systemFields)) {
                continue;
            }

            $allowedFields[$field] = $definition;
        }

        return $allowedFields;
    }

    public function validateField(string $field, $value): bool
    {
        $schema = self::getTableSchema($this->getDynamicTable());

        if (!isset($schema[$field])) {
            return false;
        }

        $definition = $schema[$field];

        $type = $definition['type'] ?? 'string';
        return $this->validateType($value, $type, $definition);
    }

    protected function validateType($value, string $type, array $definition): bool
    {
        switch (strtolower($type)) {
            case 'integer':
            case 'int':
                return is_numeric($value);

            case 'float':
            case 'decimal':
                return is_numeric($value);

            case 'string':
            case 'text':
                return is_string($value);

            case 'boolean':
                return is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false']);

            case 'date':
            case 'datetime':
                return strtotime($value) !== false;

            case 'json':
                if (is_string($value)) {
                    json_decode($value);
                    return json_last_error() === JSON_ERROR_NONE;
                }
                return is_array($value) || is_object($value);

            default:
                return true;
        }
    }

    public function getFieldDefinition(string $field): ?array
    {
        $schema = self::getTableSchema($this->getDynamicTable());
        return $schema[$field] ?? null;
    }

    public function getFields(): array
    {
        return array_keys(self::getTableSchema($this->getDynamicTable()));
    }

    public static function clearSchemaCache(?string $table = null): void
    {
        if ($table) {
            cache()->delete(self::$schemaCacheKey . $table);
        }
    }
}
