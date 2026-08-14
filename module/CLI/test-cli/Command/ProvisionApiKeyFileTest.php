<?php

declare(strict_types=1);

namespace ShlinkioCliTest\Shlink\CLI\Command;

use PHPUnit\Framework\Attributes\Test;
use Shlinkio\Shlink\CLI\Command\Api\ProvisionKeyFileCommand;
use Shlinkio\Shlink\TestUtils\CliTest\CliTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

use function bin2hex;
use function chmod;
use function file_get_contents;
use function fileperms;
use function is_file;
use function random_bytes;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function trim;
use function unlink;

class ProvisionApiKeyFileTest extends CliTestCase
{
    #[Test]
    public function provisionsOwnerReadableKeyAndValidatesItOnRerun(): void
    {
        [$name, $outputFile] = $this->newKeyIdentity();

        try {
            [$firstOutput, $firstExitCode] = $this->exec([ProvisionKeyFileCommand::NAME, $name, $outputFile]);
            $plainKey = file_get_contents($outputFile);

            self::assertSame(Command::SUCCESS, $firstExitCode);
            self::assertIsString($plainKey);
            self::assertNotSame('', $plainKey);
            self::assertFalse(str_contains($firstOutput, trim($plainKey)));
            self::assertSame(0o400, fileperms($outputFile) & 0o777);

            [$rerunOutput, $rerunExitCode] = $this->exec([ProvisionKeyFileCommand::NAME, $name, $outputFile]);

            self::assertSame(Command::SUCCESS, $rerunExitCode);
            self::assertStringContainsString('already provisioned', $rerunOutput);
            self::assertSame($plainKey, file_get_contents($outputFile));
            self::assertFalse(str_contains($rerunOutput, trim($plainKey)));
        } finally {
            $this->removeKeyFile($outputFile);
        }
    }

    #[Test]
    public function existingDatabaseNameWithoutPlaintextFileFailsClosed(): void
    {
        [$name, $outputFile] = $this->newKeyIdentity();

        try {
            $this->exec([ProvisionKeyFileCommand::NAME, $name, $outputFile]);
            $plainKey = file_get_contents($outputFile);
            self::assertIsString($plainKey);
            $this->removeKeyFile($outputFile);

            $process = new Process(['bin/cli', ProvisionKeyFileCommand::NAME, $name, $outputFile, '--no-ansi']);
            $exitCode = $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertFalse(is_file($outputFile));
            self::assertStringContainsString('no key file was published', $output);
            self::assertFalse(str_contains($output, trim($plainKey)));
        } finally {
            $this->removeKeyFile($outputFile);
        }
    }

    /** @return array{string, string} */
    private function newKeyIdentity(): array
    {
        $suffix = bin2hex(random_bytes(8));
        return [
            'creator-signal-web-ui-' . $suffix,
            sprintf('%s/shlink-management-key-%s', sys_get_temp_dir(), $suffix),
        ];
    }

    private function removeKeyFile(string $outputFile): void
    {
        if (is_file($outputFile)) {
            chmod($outputFile, 0o600);
            unlink($outputFile);
        }
    }
}
