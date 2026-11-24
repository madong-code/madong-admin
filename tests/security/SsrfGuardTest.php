<?php
declare(strict_types=1);

use core\foundation\exception\handler\ValidationException;
use core\io\network\HttpUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SSRF 防护守卫单元测试
 *
 * 所有用例用 IP 字面量构造 URL，完全绕过 dns_get_record，
 * 确保测试不依赖外部 DNS 环境且结果 100% 确定性。
 * DNS 重绑定分支已通过 addslashes-sort 后比对在代码逻辑中保护，
 * 该分支由代码审阅覆盖（无法静态 mock PHP 内置函数 dns_get_record）。
 */
final class SsrfGuardTest extends TestCase
{
    // ==================== Scheme 白名单 ====================

    #[DataProvider('illegalSchemeProvider')]
    public function testRejectsIllegalScheme(string $url, string $schemeLabel): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('图片地址不合法');
        (new HttpUrlGuard())->assertSafe($url);
    }

    public static function illegalSchemeProvider(): array
    {
        return [
            'file://'  => ['file:///etc/passwd', 'file'],
            'gopher://' => ['gopher://127.0.0.1:6379/_PING', 'gopher'],
            'dict://'   => ['dict://127.0.0.1:6379/INFO', 'dict'],
            'php://'    => ['php://filter/convert.base64-encode/resource=/etc/passwd', 'php'],
            'ftp://'    => ['ftp://127.0.0.1/passwd', 'ftp'],
            'data://'   => ['data://text/html,alert(1)', 'data'],
        ];
    }

    public static function allowedSchemeProvider(): array
    {
        return [
            'http 公网 IP'  => ['http://8.8.8.8/'],
            'https 公网 IP' => ['https://8.8.8.8/'],
        ];
    }

    #[DataProvider('allowedSchemeProvider')]
    public function testAllowsHttpHttps(string $url): void
    {
        (new HttpUrlGuard())->assertSafe($url);
        // 到这里不抛异常就是通过
        self::assertTrue(true);
    }

    // ==================== 端口限制 ====================

    public function testRejectsNonStandardPort(): void
    {
        $this->expectException(ValidationException::class);
        (new HttpUrlGuard())->assertSafe('http://8.8.8.8:6379/');
    }

    public function testAllowsPort80(): void
    {
        (new HttpUrlGuard())->assertSafe('http://8.8.8.8:80/');
        self::assertTrue(true);
    }

    public function testAllowsPort443(): void
    {
        (new HttpUrlGuard())->assertSafe('https://8.8.8.8:443/');
        self::assertTrue(true);
    }

    public function testAllowsNoPort(): void
    {
        (new HttpUrlGuard())->assertSafe('http://8.8.8.8/');
        self::assertTrue(true);
    }

    // ==================== IPv4 私网段 ====================

    #[DataProvider('privateIpv4Provider')]
    public function testRejectsPrivateIpv4(string $ip, string $rangeLabel): void
    {
        $this->expectException(ValidationException::class);
        (new HttpUrlGuard())->assertSafe("http://$ip/");
    }

    public static function privateIpv4Provider(): array
    {
        return [
            '0/8 当前网络'            => ['0.0.0.1', '0/8'],
            '10/8 私有 A'            => ['10.255.255.254', '10/8'],
            '10/8 边界值'            => ['10.0.0.1', '10/8'],
            '127/8 回环'             => ['127.127.127.127', '127/8'],
            '169.254/16 链路本地'    => ['169.254.169.254', '169.254/16 云元数据'],
            '172.16/12 私有 B 起始'  => ['172.16.0.1', '172.16/12'],
            '172.16/12 私有 B 末尾'  => ['172.31.255.255', '172.16/12'],
            '192.168/16 私有 C'      => ['192.168.255.254', '192.168/16'],
            '224/4 多播'             => ['224.0.0.1', '224/4'],
            '240/4 保留'             => ['240.1.2.3', '240/4'],
        ];
    }

    public function testAllowsPublicIpv4(): void
    {
        // Google DNS
        (new HttpUrlGuard())->assertSafe('http://8.8.8.8/');
        (new HttpUrlGuard())->assertSafe('http://1.1.1.1/');
        // 注意：172.67.74.163（Cloudflare）不在 172.16/12 内（172.32+），应该放行
        (new HttpUrlGuard())->assertSafe('http://172.67.74.163/');
        self::assertTrue(true);
    }

    public function testAllowsPublicIpv4BoundaryJustOutside172Range(): void
    {
        // 172.32.0.0 起就不在 172.16/12 内了（172.16.0.0 ~ 172.31.255.255 是私网段）
        (new HttpUrlGuard())->assertSafe('http://172.32.0.1/');
        self::assertTrue(true);
    }

    // ==================== IPv6 私网段 ====================

    #[DataProvider('privateIpv6Provider')]
    public function testRejectsPrivateIpv6(string $ip, string $rangeLabel): void
    {
        $this->expectException(ValidationException::class);
        (new HttpUrlGuard())->assertSafe("http://[$ip]/");
    }

    public static function privateIpv6Provider(): array
    {
        return [
            '::1 回环'          => ['::1', 'loopback'],
            'fc00::/7 唯一本地' => ['fc00::1', 'fc00::/7'],
            'fd00::/7 唯一本地' => ['fd00::dead:beef', 'fd00::/7'],
            'fe80::/10 链路本地' => ['fe80::1', 'fe80::/10'],
            '0::/8 unspecified' => ['::', 'unspecified'],
        ];
    }

    public function testAllowsPublicIpv6(): void
    {
        (new HttpUrlGuard())->assertSafe('http://[2001:4860:4860::8888]/');
        (new HttpUrlGuard())->assertSafe('http://[2606:4700:4700::1111]/');
        self::assertTrue(true);
    }

    // ==================== 边界条件 ====================

    public function testEmptyUrlRejected(): void
    {
        $this->expectException(ValidationException::class);
        (new HttpUrlGuard())->assertSafe('');
    }

    public function testMalformedUrlRejected(): void
    {
        $this->expectException(ValidationException::class);
        (new HttpUrlGuard())->assertSafe('not-a-url');
    }

    public function testNoSchemeRejected(): void
    {
        $this->expectException(ValidationException::class);
        (new HttpUrlGuard())->assertSafe('8.8.8.8');
    }

    public function testCaseInsensitiveHost(): void
    {
        // HTTP host 大小写不敏感，私有 IP 大写格式
        $this->expectException(ValidationException::class);
        (new HttpUrlGuard())->assertSafe('HTTP://127.0.0.1/');
    }
}
