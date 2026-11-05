<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 静态回归：确保修复点不被后续改动回退（标准版适配，剔除 SaaS 专有结构断言）。
 */
final class SecurityConfigTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relative);
    }

    public function testDownloadControllerUsesGuardAndWhitelistedQueuePayload(): void
    {
        $source = $this->source('app/adminapi/controller/system/FilesController.php');

        self::assertStringContainsString('ExportPathGuard::resolve', $source);
        self::assertStringContainsString("'file_path' => \$safeRelative", $source);
        self::assertStringNotContainsString('$filePath     = runtime_path() . $file;', $source);
    }

    public function testQueueConsumerHasItsOwnGuard(): void
    {
        $source = $this->source('app/queue/redis/RemoveExcelFile.php');

        self::assertStringContainsString('ExportPathGuard::resolve', $source);
        self::assertStringNotContainsString('runtime_path() . $filePath', $source);
    }

    public function testInstallerGeneratesSecretInAllEnvWritePaths(): void
    {
        $source = $this->source('core/business/install/InstallService.php');

        self::assertGreaterThanOrEqual(3, substr_count($source, 'JwtSecret::ensureInEnv'));
        self::assertStringContainsString('JWT_SECRET=', $this->source('.example.env'));
    }

    public function testSecretRotationCommandExists(): void
    {
        $source = $this->source('app/command/JwtSecretGenerateCommand.php');

        self::assertStringContainsString("name: 'jwt:secret'", $source);
        self::assertStringContainsString('JwtSecret::ensureInEnv', $source);
    }

    public function testCliInstallCreatesEnvBeforeLock(): void
    {
        $source = $this->source('core/business/install/InstallService.php');
        $start  = strpos($source, 'public function install(array $params): \Generator');
        self::assertNotFalse($start);

        $installMethod = substr($source, (int)$start, 9000);
        self::assertLessThan(
            (int)strpos($installMethod, '$this->createInstallLock();'),
            (int)strpos($installMethod, '$this->copyDatabaseTemplateAndCreateEnv('),
            '.env 必须在 install.lock 之前生成，避免留下无密钥的已安装态'
        );
    }

    public function testCliInstallReportsRuntimeErrorsAndFinishesGenerator(): void
    {
        $base = $this->source('app/command/BaseCommand.php');
        $cli  = $this->source('app/command/install/InstallCommand.php');

        self::assertStringContainsString("case 'runtime_error'", $base);
        self::assertStringContainsString('$completed ? Command::SUCCESS : Command::FAILURE', $cli);
    }

    public function testEnvironmentCheckNeverLeavesKeylessEnv(): void
    {
        $source = $this->source('core/business/install/InstallService.php');

        self::assertStringContainsString("JwtSecret::ensureInEnv(\$content)", $source);
        self::assertStringContainsString('JwtSecret::isValid($existingSecret)', $source);
    }

    public function testFrontendBuildIsOptionalAndNonBlocking(): void
    {
        $installService = $this->source('core/business/install/InstallService.php');
        self::assertStringContainsString('前端构建/依赖安装失败，安装继续', $installService);

        $cli = $this->source('app/command/install/InstallCommand.php');
        self::assertStringContainsString('默认不构建', $cli);
        self::assertStringContainsString("\$params['build_project'] = \$buildProject === '1' ? '1' : '0';", $cli);

        $webStore = (string)file_get_contents(
            dirname(__DIR__, 3) . '/template/install/src/store/module/install.ts'
        );
        self::assertStringContainsString('build_project: false', $webStore);
    }

    public function testReservedRootAdminIsEditableButCannotBeCreated(): void
    {
        $source = $this->source('app/adminapi/validate/system/admin/AdminValidate.php');

        self::assertStringContainsString('not_in:root', $source);
        self::assertStringContainsString('isExistingRootAdmin', $source);
        self::assertStringNotContainsString('TenantScope', $source);
    }

    public function testPasswordInputDoesNotCorruptQuestionStream(): void
    {
        $base = $this->source('app/command/BaseCommand.php');
        self::assertStringContainsString('fgets(STDIN)', $base);
        self::assertStringNotContainsString('stream_get_contents(STDIN, 1)', $base);

        $cli = $this->source('app/command/install/InstallCommand.php');
        self::assertStringContainsString('$input->isInteractive()', $cli);
    }

    public function testJwtSecretBlockStaysAtEndOfEnvTemplates(): void
    {
        $example = $this->source('.example.env');
        self::assertMatchesRegularExpression('/^JWT_SECRET=\r?$/m', $example);
        self::assertLessThan(
            (int)strpos($example, 'JWT_SECRET='),
            (int)strpos($example, 'APP_EVENT_LOOP='),
            '.example.env 的 JWT 段必须位于模板末尾且保持空值'
        );

        $installService = $this->source('core/business/install/InstallService.php');
        $start = strpos($installService, 'private function getDefaultEnvContent()');
        self::assertNotFalse($start);
        $heredoc = substr($installService, (int)$start, 1200);
        self::assertLessThan(
            (int)strpos($heredoc, 'JWT_SECRET='),
            (int)strpos($heredoc, 'LOG_CHANNEL = daily'),
            '默认 .env 模板的 JWT 段必须位于末尾'
        );
    }

    public function testEnvTemplatesKeepEmptyEventLoopValue(): void
    {
        $example = $this->source('.example.env');
        self::assertMatchesRegularExpression('/^APP_EVENT_LOOP=\r?$/m', $example);

        $installService = $this->source('core/business/install/InstallService.php');
        self::assertMatchesRegularExpression('/^APP_EVENT_LOOP\s*=\s*$/m', $installService);
    }

    public function testExampleEnvKeepsStandardDatabaseName(): void
    {
        self::assertMatchesRegularExpression('/^DB_DATABASE=madong\r?$/m', $this->source('.example.env'));
    }

    public function testInstalledCheckHasNoDebugOutput(): void
    {
        $source = $this->source('core/business/install/InstallService.php');
        self::assertStringNotContainsString('Checking for install.lock at:', $source);
    }

    public function testReloadOnlyHappensInsideWorker(): void
    {
        $source = $this->source('core/business/install/InstallService.php');
        self::assertStringContainsString('Worker::getAllWorkers()', $source);
    }

    public function testAdminapiAuthUsesVerifiedPayload(): void
    {
        $source = $this->source('app/adminapi/middleware/AccessTokenMiddleware.php');
        self::assertStringContainsString('getVerifiedPayload()', $source);
        self::assertStringNotContainsString('$jwt->id()', $source);
    }
}
