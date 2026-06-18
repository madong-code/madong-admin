<?php
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

namespace core\db;

use core\uuid\Snowflake;
use PDO;

/**
 * PDO导入数据服务类
 *
 * @author Mr.April
 * @since  1.0
 */
class DataImporterService
{

    /**
     * 获取 PDO 连接的方法
     *
     * @throws \Exception
     */
    public function getPdo($host, $username, $password, $port, $database = null): \PDO
    {
        try {
            $dsn = "mysql:host=$host;port=$port;";
            if ($database) {
                $dsn .= "dbname=$database";
            }
            $params = [
                \PDO::MYSQL_ATTR_INIT_COMMAND       => "set names utf8mb4",//连接建立后立即执行的SQL命令
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,               //查询结果使用缓冲模式
                \PDO::ATTR_EMULATE_PREPARES         => false,              //禁用后使用真正的预处理语句，可防御SQL注入；启用（true）时仅做字符串转义（默认行为）
                \PDO::ATTR_TIMEOUT                  => 5,                  //链接超时时间
                \PDO::ATTR_ERRMODE                  => \PDO::ERRMODE_EXCEPTION,//错误处理模式
            ];
            return new \PDO($dsn, $username, $password, $params);
        } catch (\Throwable $e) {
            if (stripos($e, 'Access denied for user')) {
                throw new \Exception('数据库用户名或密码错误');
            }
            if (stripos($e, 'Connection refused')) {
                throw new \Exception('Connection refused. 请确认数据库IP端口是否正确，数据库已经启动');
            }
            if (stripos($e, 'timed out')) {
                throw new \Exception('数据库连接超时，请确认数据库IP端口是否正确，安全组及防火墙已经放行端口');
            }
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * 导入通用数据
     *
     * @param \PDO   $pdo
     * @param string $tableName
     * @param array  $fields
     * @param array  $data
     * @param array  $options
     *
     * @throws \Exception
     */
    public function importData(\PDO $pdo, string $tableName, array $fields, array $data, array $options = []): void
    {
        $defaultOptions = [
            'idKey'            => 'id',
            'useSnowflakeId'   => true,
            'useAutoIncrement' => false,
            'pidKey'           => 'pid',
        ];
        $options        = array_merge($defaultOptions, $options);

        // MySQL 保留关键字列表
        $reservedWords = ['database', 'select', 'insert', 'update', 'delete', 'where'];

        foreach ($data as $row) {
            if ($options['useSnowflakeId']) {
                $fields[] = $options['idKey'];
                if (empty($row[$options['idKey']])) {
                    $row[$options['idKey']] = Snowflake::generate();
                }
            } elseif ($options['useAutoIncrement']) {
                unset($row[$options['idKey']]);
            }

            $filteredRow = array_intersect_key($row, array_flip($fields));

            // 转义保留关键字
            $columns = implode(", ", array_map(function ($col) use ($reservedWords) {
                return in_array(strtolower($col), $reservedWords) ? "`$col`" : $col;
            }, array_keys($filteredRow)));

            $placeholders = ":" . implode(", :", array_keys($filteredRow));

            $sql  = "INSERT INTO `$tableName` ($columns) VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);

            foreach ($filteredRow as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }

            $stmt->execute();
        }
    }

    /**
     * 导入树形数据
     *
     * @param \PDO   $pdo
     * @param string $tableName
     * @param array  $fields
     * @param array  $data
     * @param array  $options
     *
     * @throws \Exception
     */
    public function importTreeData(\PDO $pdo, string $tableName, array $fields, array $data, array $options = []): void
    {
        // 默认选项
        $defaultOptions = [
            'idKey'            => 'id', // ID 字段的键名
            'useSnowflakeId'   => true, // 是否使用雪花ID
            'useAutoIncrement' => false, // 是否使用自增ID
            'pidKey'           => 'pid', // 父节点ID字段的键名
            'pidDefault'       => 0,     //pid默认值
        ];
        // 合并选项
        $options = array_merge($defaultOptions, $options);

        $this->recursiveImport($pdo, $tableName, $fields, $data, null, $options);
    }

    /**
     * 导入树形数据-递归
     *
     * @param \PDO            $pdo
     * @param string          $tableName
     * @param array           $fields
     * @param array           $data
     * @param string|int|null $parentId
     * @param array           $options
     *
     * @throws \Exception
     */
    private function recursiveImport(\PDO $pdo, string $tableName, array $fields, array $data, null|string|int $parentId, array $options): void
    {
        // 默认选项
        $defaultOptions = [
            'idKey'            => 'id', // ID 字段的键名
            'useSnowflakeId'   => true, // 是否使用雪花ID
            'useAutoIncrement' => false, // 是否使用自增ID
            'pidKey'           => 'pid', // 父节点ID字段的键名
            'pidDefault'       => 0,     //pid默认值
        ];
        // 合并选项
        $options = array_merge($defaultOptions, $options);
        foreach ($data as $key => $item) {
            // 如果需要使用雪花ID
            if ($options['useSnowflakeId']) {
                $item[$options['idKey']] = Snowflake::generate(); // 生成雪花ID
            } elseif ($options['useAutoIncrement']) {
                unset($item[$options['idKey']]); // 自增ID由数据库处理
            }
            // 设置父节点ID
            $item[$options['pidKey']] = $parentId;
            if (empty($parentId)) {
                $item[$options['pidKey']] = $options['pidDefault'] ?? null;
            }

            // 导入当前节点
            $this->importData($pdo, $tableName, $fields, [$item], $options);

            // 获取当前节点的ID
            $currentId = $item[$options['idKey']];

            // 如果有子节点，递归导入
            if (isset($item['children']) && is_array($item['children'])) {
                $this->recursiveImport($pdo, $tableName, $fields, $item['children'], $currentId, $options);
            }
        }
    }

    /**
     * 导入字母表数据
     *
     * @param \PDO   $pdo
     * @param string $mainTable
     * @param array  $mainFields
     * @param string $itemsTable
     * @param array  $itemsFields
     * @param array  $data
     * @param array  $options
     *
     * @throws \Exception
     */
    public function importWithRelated(\PDO $pdo, string $mainTable, array $mainFields, string $itemsTable, array $itemsFields, array $data, array $options = []): void
    {
        // 默认选项
        $defaultOptions = [
            'idKey'            => 'id', // ID 字段的键名
            'useSnowflakeId'   => true, // 是否使用雪花ID
            'useAutoIncrement' => false, // 是否使用自增ID
            'pidKey'           => 'pid', // 父节点ID字段的键名
        ];
        $options        = array_merge($defaultOptions, $options);
        foreach ($data as $item) {
            // 如果需要使用雪花ID
            if ($options['useSnowflakeId']) {
                $item[$options['idKey']] = Snowflake::generate(); // 生成雪花ID
            } elseif ($options['useAutoIncrement']) {
                unset($item[$options['idKey']]); // 自增ID由数据库处理
            }
            // 导入当前节点
            $this->importData($pdo, $mainTable, $mainFields, [$item], $options);
            // 获取当前节点的ID
            $currentId = $item[$options['idKey']];
            // 如果有items子节点导入
            if (isset($item['items']) && is_array($item['items'])) {
                $items         = array_map(function ($item) use ($options, $currentId) {
                    $item[$options['pidKey']] = $currentId;
                    return $item;
                }, $item['items']);
                $itemsFields[] = $options['pidKey'];
                //导入子表数据
                $this->importData($pdo, $itemsTable, $itemsFields, $items, []);
            }
        }
    }

    /**
     * 安装数据库表结构（支持表前缀）
     *
     * @param PDO         $pdo             PDO数据库连接对象
     * @param array       $tablesToInstall 需要安装的表名数组
     * @param string      $database        数据库名
     * @param bool        $overwrite       是否覆盖已存在的表
     * @param string      $tablePrefix     表前缀（可选）
     * @param string|null $sqlFilePath     SQL文件路径（可选）
     *
     * @throws \Exception
     */
    public function installDatabaseTables(\PDO $pdo, array $tablesToInstall, string $database, bool $overwrite = false, string $tablePrefix = '', ?string $sqlFilePath = null): void
    {
        // 设置默认SQL文件路径
        if ($sqlFilePath === null) {
            $sqlFilePath = base_path() . '/resource/sql/install.sql';
        }

        // 获取当前数据库所有表名
        $tables = $this->getExistingTables($pdo, $database);

        // 检测插入的数据表冲突
        $tables_exist = [];
        foreach ($tables as $table) {
            $tables_exist[] = current($table);
        }

        // 如果指定了表前缀，需要检查带前缀的表名冲突
        $tablesConflict = [];
        if (!empty($tablePrefix)) {
            $prefixedTablesToInstall = array_map(function ($table) use ($tablePrefix) {
                return $tablePrefix . $table;
            }, $tablesToInstall);
            $tablesConflict          = array_intersect($prefixedTablesToInstall, $tables_exist);
        } else {
            $tablesConflict = array_intersect($tablesToInstall, $tables_exist);
        }

        // 处理表冲突
        if (!$overwrite && !empty($tablesConflict)) {
            throw new \Exception('以下表已存在且未选择覆盖模式: ' . implode(', ', $tablesConflict));
        }

        try {
            // 删除冲突表（覆盖模式时）
            if ($overwrite) {
                foreach ($tablesConflict as $table) {
                    $pdo->exec("DROP TABLE IF EXISTS `$table`");
                }
            }

            // 执行SQL安装文件
            if (!file_exists($sqlFilePath)) {
                throw new \Exception('数据库安装SQL文件不存在: ' . $sqlFilePath);
            }

            $sqlContent = file_get_contents($sqlFilePath);
            $sql_query  = $this->removeComments($sqlContent);

            // 如果指定了表前缀，替换SQL中的表名
            if (!empty($tablePrefix)) {
                $sql_query = $this->addPrefixToSqlTables($sql_query, $tablePrefix);
            }

            $sqlQueries = $this->splitSqlFile($sql_query, ';');

            foreach ($sqlQueries as $query) {
                if (!empty(trim($query))) {
                    $pdo->exec($query);
                }
            }
        } catch (\Exception $e) {
            throw new \Exception('数据库表安装失败: ' . $e->getMessage());
        }
    }

    /**
     * 统一添加表前缀到SQL语句中的表名
     * User  GQL
     * Date  2025/12/26 17:18
     *
     * @param string $sqlContent
     * @param string $tablePrefix
     *
     * @return string
     */
    private function addPrefixToSqlTables(string $sqlContent, string $tablePrefix): string
    {
        // 规范化前缀
        $tablePrefix = rtrim($tablePrefix, '_');

        // 1. 提取 CREATE TABLE 中的表名（包含已有前缀）
        $tableNames    = [];
        $createPattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' .
            '(?:`(?P<schema_bt>[^`]+)`|(?P<schema_nb>[A-Za-z0-9_]+))?\s*\.?\s*' .
            '(?:`(?P<table_bt>[^`]+)`|(?P<table_nb>[A-Za-z0-9_]+))/i';

        if (preg_match_all($createPattern, $sqlContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $tbl = isset($m['table_bt']) && $m['table_bt'] !== '' ? $m['table_bt'] : $m['table_nb'];
                if ($tbl) $tableNames[$tbl] = true;
            }
        }

        if (empty($tableNames)) {
            $fallbackPattern = '/(?:DROP|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|TRUNCATE\s+TABLE)\s+' .
                '(?:`(?P<table_bt>[^`]+)`|(?P<table_nb>[A-Za-z0-9_]+))/i';
            if (preg_match_all($fallbackPattern, $sqlContent, $fm, PREG_SET_ORDER)) {
                foreach ($fm as $m) {
                    $tbl = isset($m['table_bt']) && $m['table_bt'] !== '' ? $m['table_bt'] : $m['table_nb'];
                    if ($tbl) $tableNames[$tbl] = true;
                }
            }
        }

        if (empty($tableNames)) return $sqlContent;
        $tableNames = array_keys($tableNames);

        $contexts = [
            'CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?',
            'DROP\s+TABLE(?:\s+IF\s+EXISTS)?',
            'INSERT\s+(?:IGNORE\s+)?INTO',
            'REPLACE\s+INTO',
            'ALTER\s+TABLE',
            'TRUNCATE\s+TABLE',
            'UPDATE',
            'DELETE\s+FROM',
            'REFERENCES',
            'FROM',
            'JOIN',
        ];

        foreach ($tableNames as $tbl) {
            foreach ($contexts as $ctx) {
                $pattern = '/(' . $ctx . '\s+)' .
                    '(?:' .
                    '`(?P<schema_bt>[^`]+)`\s*\.\s*' .
                    ')?' .
                    '(?:' .
                    '`(?P<table_bt>[^`]+)`' .
                    '|' .
                    '(?P<table_nb>[A-Za-z0-9_]+)' .
                    ')/iu';

                $sqlContent = preg_replace_callback($pattern, function ($m) use ($tbl, $tablePrefix) {
                    $schema = '';
                    if (!empty($m['schema_bt'])) $schema = '`' . $m['schema_bt'] . '`.';

                    $origTable   = '';
                    $hasBacktick = false;
                    if (!empty($m['table_bt'])) {
                        $origTable   = $m['table_bt'];
                        $hasBacktick = true;
                    } elseif (!empty($m['table_nb'])) {
                        $origTable = $m['table_nb'];
                    }

                    if (strcasecmp($origTable, $tbl) !== 0) return $m[0];

                    // 如果规范化后前缀为空，表示不需要加前缀
                    if ($tablePrefix === '') {
                        return $m[1] . $schema . ($hasBacktick ? '`' . $origTable . '`' : $origTable);
                    }

                    // 如果已经以目标前缀开头（例如 md_），则保留原样（不重复加）
                    if (preg_match('/^' . preg_quote($tablePrefix, '/') . '_/i', $origTable)) {
                        return $m[1] . $schema . ($hasBacktick ? '`' . $origTable . '`' : $origTable);
                    }

                    // 构造新表名：前缀 + '_' + 原表名（保证只有一个下划线）
                    $newNameCore = $tablePrefix . '_' . $origTable;
                    if ($hasBacktick || strpos($schema, '`') !== false) {
                        $newNameCore = '`' . $newNameCore . '`';
                    }

                    return $m[1] . $schema . $newNameCore;
                }, $sqlContent);
            }

            $pattern2 = '/(?<![A-Za-z0-9_`\.])' .
                '(?:`(?P<schema_bt>[^`]+)`\s*\.\s*)?' .
                '(?:`(?P<table_bt>[^`]+)`|(?P<table_nb>[A-Za-z0-9_]+))' .
                '(?![A-Za-z0-9_`\.])/u';

            $sqlContent = preg_replace_callback($pattern2, function ($m) use ($tbl, $tablePrefix) {
                $schema = '';
                if (!empty($m['schema_bt'])) $schema = '`' . $m['schema_bt'] . '`.';

                $origTable = !empty($m['table_bt']) ? $m['table_bt'] : $m['table_nb'] ?? '';
                if ($origTable === '' || strcasecmp($origTable, $tbl) !== 0) return $m[0];

                if ($tablePrefix === '') return $m[0];

                if (preg_match('/^' . preg_quote($tablePrefix, '/') . '_/i', $origTable)) return $m[0];

                $newName = $tablePrefix . '_' . $origTable;
                if (!empty($m['table_bt']) || !empty($m['schema_bt'])) {
                    $newName = '`' . $newName . '`';
                }
                return $schema . $newName;
            }, $sqlContent);
        }

        return $sqlContent;
    }


    /**
     * 获取当前数据库所有表名
     *
     * @param \PDO   $pdo
     * @param string $database
     *
     * @return array
     */
    private function getExistingTables(\PDO $pdo, string $database): array
    {
        $smt = $pdo->query("show databases like '$database'");
        if (empty($smt->fetchAll())) {
            $pdo->exec("create database $database");
        }
        $pdo->exec("use $database");
        $smt = $pdo->query("show tables");
        return $smt->fetchAll();
    }

    /**
     * 去除sql文件中的注释
     *
     * @param $sql
     *
     * @return string
     */
    protected function removeComments($sql): string
    {
        return preg_replace("/(\n--[^\n]*)/", "", $sql);
    }

    /**
     * 分割sql文件
     *
     * @param $sql
     * @param $delimiter
     *
     * @return array
     */
    function splitSqlFile($sql, $delimiter): array
    {
        $tokens      = explode($delimiter, $sql);
        $output      = array();
        $matches     = array();
        $token_count = count($tokens);
        for ($i = 0; $i < $token_count; $i++) {
            if (($i != ($token_count - 1)) || (strlen($tokens[$i] > 0))) {
                $total_quotes     = preg_match_all("/'/", $tokens[$i], $matches);
                $escaped_quotes   = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $tokens[$i], $matches);
                $unescaped_quotes = $total_quotes - $escaped_quotes;

                if (($unescaped_quotes % 2) == 0) {
                    $output[]   = $tokens[$i];
                    $tokens[$i] = "";
                } else {
                    $temp       = $tokens[$i] . $delimiter;
                    $tokens[$i] = "";

                    $complete_stmt = false;
                    for ($j = $i + 1; (!$complete_stmt && ($j < $token_count)); $j++) {
                        $total_quotes     = preg_match_all("/'/", $tokens[$j], $matches);
                        $escaped_quotes   = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $tokens[$j], $matches);
                        $unescaped_quotes = $total_quotes - $escaped_quotes;
                        if (($unescaped_quotes % 2) == 1) {
                            $output[]      = $temp . $tokens[$j];
                            $tokens[$j]    = "";
                            $temp          = "";
                            $complete_stmt = true;
                            $i             = $j;
                        } else {
                            $temp       .= $tokens[$j] . $delimiter;
                            $tokens[$j] = "";
                        }

                    }
                }
            }
        }
        return $output;
    }
}