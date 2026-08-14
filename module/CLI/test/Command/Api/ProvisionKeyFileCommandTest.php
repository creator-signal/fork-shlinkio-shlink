<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\CLI\Command\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shlinkio\Shlink\CLI\Command\Api\ProvisionKeyFileCommand;
use Shlinkio\Shlink\Rest\ApiKey\Model\ApiKeyMeta;
use Shlinkio\Shlink\Rest\Entity\ApiKey;
use Shlinkio\Shlink\Rest\Service\ApiKeyCheckResult;
use Shlinkio\Shlink\Rest\Service\ApiKeyServiceInterface;
use ShlinkioTest\Shlink\CLI\Util\CliTestUtils;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function bin2hex;
use function chmod;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;

class ProvisionKeyFileCommandTest extends TestCase
{
    private const string NAME = 'creator-signal-web-ui';

    private CommandTester $commandTester;
    private MockObject&ApiKeyServiceInterface $apiKeyService;
    private string $directory;
    private string $outputFile;

    protected function setUp(): void
    {
        $this->directory = sprintf('%s/shlink-provision-key-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        mkdir($this->directory, 0o700, recursive: true);
        $this->outputFile = $this->directory . '/api-key';
        $this->apiKeyService = $this->createMock(ApiKeyServiceInterface::class);
        $this->commandTester = CliTestUtils::testerForCommand(new ProvisionKeyFileCommand($this->apiKeyService));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (scandir($this->directory) ?: [] as $file) {
            $path = $this->directory . '/' . $file;
            if ($file !== '.' && $file !== '..' && is_file($path)) {
                chmod($path, 0o600);
                unlink($path);
            }
        }
        rmdir($this->directory);
    }

    #[Test]
    public function provisionsNewAdminKeyWithoutPrintingIt(): void
    {
        $plainKey = null;
        $this->apiKeyService
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(static function (ApiKeyMeta $meta) use (&$plainKey): bool {
                $plainKey = $meta->key;
                return $meta->name === self::NAME && ApiKey::isAdmin(ApiKey::fromMeta($meta));
            }))
            ->willReturnCallback(ApiKey::fromMeta(...));

        $exitCode = $this->execute();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNotNull($plainKey);
        self::assertSame($plainKey . "\n", file_get_contents($this->outputFile));
        self::assertFalse(str_contains($this->commandTester->getDisplay(), $plainKey));
        self::assertStringContainsString('securely provisioned', $this->commandTester->getDisplay());
    }

    #[Test]
    public function validatesMatchingExistingAdminKeyWithoutRotatingIt(): void
    {
        $plainKey = 'existing-management-key';
        $apiKey = ApiKey::fromMeta(ApiKeyMeta::fromParams(key: $plainKey, name: self::NAME));
        file_put_contents($this->outputFile, $plainKey . "\n");
        chmod($this->outputFile, 0o400);

        $this->apiKeyService
            ->expects($this->once())
            ->method('check')
            ->with($plainKey)
            ->willReturn(
                new ApiKeyCheckResult($apiKey),
            );
        $this->apiKeyService->expects($this->never())->method('create');

        $exitCode = $this->execute();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame($plainKey . "\n", file_get_contents($this->outputFile));
        self::assertFalse(str_contains($this->commandTester->getDisplay(), $plainKey));
        self::assertStringContainsString('already provisioned', $this->commandTester->getDisplay());
    }

    #[Test]
    public function refusesMismatchedExistingKeyWithoutReplacingIt(): void
    {
        $plainKey = 'wrong-management-key';
        $apiKey = ApiKey::fromMeta(ApiKeyMeta::fromParams(key: $plainKey, name: 'different-name'));
        file_put_contents($this->outputFile, $plainKey . "\n");
        chmod($this->outputFile, 0o400);

        $this->apiKeyService
            ->expects($this->once())
            ->method('check')
            ->with($plainKey)
            ->willReturn(
                new ApiKeyCheckResult($apiKey),
            );
        $this->apiKeyService->expects($this->never())->method('create');

        $exitCode = $this->execute();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame($plainKey . "\n", file_get_contents($this->outputFile));
        self::assertFalse(str_contains($this->commandTester->getDisplay(), $plainKey));
        self::assertStringContainsString('was not replaced', $this->commandTester->getDisplay());
    }

    #[Test]
    public function requiresAbsoluteOutputPath(): void
    {
        $this->apiKeyService->expects($this->never())->method('create');

        $exitCode = $this->commandTester->execute([
            'name' => self::NAME,
            'output-file' => 'relative/api-key',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('absolute output file path', $this->commandTester->getDisplay());
    }

    #[Test]
    public function refusesExistingKeyFileWithPermissiveMode(): void
    {
        file_put_contents($this->outputFile, "existing-management-key\n");
        chmod($this->outputFile, 0o640);
        $this->apiKeyService->expects($this->never())->method('check');
        $this->apiKeyService->expects($this->never())->method('create');

        $exitCode = $this->execute();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('owner-read-only permissions (0400)', $this->commandTester->getDisplay());
    }

    #[Test]
    public function doesNotPublishFileWhenDatabaseCreationFails(): void
    {
        $this->apiKeyService->expects($this->once())->method('create')->willThrowException(new RuntimeException());

        $exitCode = $this->execute();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse(is_file($this->outputFile));
        self::assertStringContainsString('no key file was published', $this->commandTester->getDisplay());
    }

    private function execute(): int
    {
        return $this->commandTester->execute([
            'name' => self::NAME,
            'output-file' => $this->outputFile,
        ]);
    }
}
