<?php
declare(strict_types=1);

use core\security\jwt\interfaces\BlacklistStorageInterface;
use core\security\jwt\interfaces\TokenStorageInterface;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestJwtToken;

/**
 * 后台鉴权加固（方案 §4.3 P1）回归用例：
 * 仅签名自洽但未在服务端注册 JTI 的令牌不得被当作合法后台身份。
 */
final class SecurityAdminAuthTest extends TestCase
{
    private const SECRET = 'test-only-secret-0123456789abcdefghijklmnop';
    private const PUBLIC_FALLBACK_SECRET = 'default-secret-key-for-development';

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id'   => '1',
            'jti'  => bin2hex(random_bytes(8)),
            'type' => 'access',
            'iat'  => time(),
            'exp'  => time() + 3600,
        ], $overrides);
    }

    private function makeJwt(
        array $payload,
        bool $registered,
        bool $blacklisted = false,
        string $signingSecret = self::SECRET
    ): TestJwtToken {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('exists')->willReturn($registered);

        $blacklist = $this->createMock(BlacklistStorageInterface::class);
        $blacklist->method('has')->willReturn($blacklisted);

        return new TestJwtToken(
            ['secret' => self::SECRET, 'algo' => 'HS256'],
            $tokenStorage,
            $blacklist,
            JWT::encode($payload, $signingSecret, 'HS256')
        );
    }

    public function testForgedTokenWithPublicFallbackKeyNeverBecomesAdminIdentity(): void
    {
        $jwt = $this->makeJwt($this->payload(), true, false, self::PUBLIC_FALLBACK_SECRET);
        self::assertSame([], $jwt->getVerifiedPayload());
    }

    public function testSelfSignedTokenWithoutRegisteredJtiIsRejected(): void
    {
        $jwt = $this->makeJwt($this->payload(), false);
        self::assertSame([], $jwt->getVerifiedPayload());
    }

    public function testBlacklistedTokenIsRejected(): void
    {
        $jwt = $this->makeJwt($this->payload(), true, true);
        self::assertSame([], $jwt->getVerifiedPayload());
    }

    public function testRefreshTokenIsRejectedAsAccessIdentity(): void
    {
        $jwt = $this->makeJwt($this->payload(['type' => 'refresh']), true);
        self::assertSame([], $jwt->getVerifiedPayload());
    }

    public function testTokenWithoutJtiIsRejected(): void
    {
        $jwt = $this->makeJwt($this->payload(['jti' => '']), true);
        self::assertSame([], $jwt->getVerifiedPayload());
    }

    public function testTokenWithoutUserIsRejected(): void
    {
        $jwt = $this->makeJwt($this->payload(['id' => '']), true);
        self::assertSame([], $jwt->getVerifiedPayload());
    }

    public function testValidRegisteredTokenIsAccepted(): void
    {
        $payload  = $this->payload();
        $verified = $this->makeJwt($payload, true)->getVerifiedPayload();

        self::assertSame('1', (string)$verified['id']);
        self::assertSame($payload['jti'], $verified['jti']);
    }

    public function testAdminMiddlewareUsesVerifiedPayload(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app/adminapi/middleware/AccessTokenMiddleware.php'
        );

        self::assertStringContainsString('getVerifiedPayload()', $source);
        self::assertStringNotContainsString('$jwt->getPayloadFromRequest()', $source);
        self::assertStringContainsString('SseHelper::sendSseErrorViaConnection', $source);
    }
}
