<?php

declare(strict_types=1);

namespace core\business\plugin;

/**
 * PHP 数组配置文件格式化输出器
 *
 * 以 info.php 风格生成 return [...] 短数组语法配置文件：
 * 4 空格缩进、同层字符串键 => 对齐、空数组收拢为 []、字符串单引号转义。
 */
final class PhpArrayFormatter
{
    /**
     * 导出为可被 include 的配置文件内容
     *
     * @param array  $data   配置数据
     * @param string $header 文件头注释（不含 <?php 标签，可空）
     */
    public static function export(array $data, string $header = ''): string
    {
        $prefix = $header !== '' ? "<?php\n\n{$header}\n\nreturn " : "<?php\n\nreturn ";

        return $prefix . self::formatValue($data, 0, false) . ";\n";
    }

    private static function formatValue(mixed $value, int $depth, bool $trailingComma): string
    {
        if (!is_array($value)) {
            return self::formatScalar($value);
        }

        if ($value === []) {
            return '[]' . ($trailingComma ? ',' : '');
        }

        $pad   = str_repeat('    ', $depth + 1);
        $close = str_repeat('    ', $depth) . ']' . ($trailingComma ? ',' : '');

        // 同层字符串键按最长宽度对齐 =>
        $width = 0;
        foreach (array_keys($value) as $key) {
            if (is_string($key)) {
                $width = max($width, strlen(self::formatKey($key)));
            }
        }

        $lines = ['['];
        foreach ($value as $key => $item) {
            $keyPart = self::formatKey($key);
            $align   = (is_string($key) && $width > strlen($keyPart)) ? str_repeat(' ', $width - strlen($keyPart)) : '';

            if (is_array($item)) {
                $lines[] = "{$pad}{$keyPart}{$align} => " . self::formatValue($item, $depth + 1, true);
            } else {
                $lines[] = "{$pad}{$keyPart}{$align} => " . self::formatScalar($item) . ',';
            }
        }
        $lines[] = $close;

        return implode("\n", $lines);
    }

    private static function formatKey(int|string $key): string
    {
        return var_export($key, true);
    }

    private static function formatScalar(mixed $value): string
    {
        return match (true) {
            is_string($value)               => var_export($value, true),
            is_bool($value)                 => $value ? 'true' : 'false',
            is_null($value)                 => 'null',
            is_int($value), is_float($value) => (string) $value,
            default                         => var_export($value, true),
        };
    }
}
