<?php
declare(strict_types=1);

use app\command\install\InstallCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 标准版 CLI 安装守门用例：默认值与模板一致、构建默认关闭、显式参数可覆盖。
 */
final class InstallCommandTest extends TestCase
{
    private function collect(array $options): array
    {
        $command = new InstallCommand();
        $method  = new \ReflectionMethod($command, 'collectInstallParams');
        $method->setAccessible(true);

        $input  = new ArrayInput($options, $command->getDefinition());
        $output = new BufferedOutput();
        $io     = new SymfonyStyle($input, $output);

        return $method->invoke($command, $io, $input, false);
    }

    public function testDefaultDatabaseNameMatchesEnvTemplate(): void
    {
        self::assertSame('madong', $this->collect([])['db_database']);
    }

    public function testDefaultAdminUsernameIsAdmin(): void
    {
        self::assertSame('admin', $this->collect([])['admin_username']);
    }

    public function testBuildProjectIsDisabledByDefault(): void
    {
        self::assertSame('0', $this->collect([])['build_project']);
    }

    public function testBuildProjectCanBeEnabledExplicitly(): void
    {
        self::assertSame('1', $this->collect(['--build-project' => '1'])['build_project']);
    }

    public function testInvalidBuildProjectValueFallsBackToDisabled(): void
    {
        self::assertSame('0', $this->collect(['--build-project' => 'yes'])['build_project']);
    }

    public function testExplicitDatabaseNameWins(): void
    {
        self::assertSame('madong_testx', $this->collect(['--db-name' => 'madong_testx'])['db_database']);
    }

    public function testNoTenantParamsAreEmitted(): void
    {
        $params = $this->collect([]);

        self::assertArrayNotHasKey('enable_tenant', $params);
        self::assertArrayNotHasKey('tenant_mode', $params);
    }
}
