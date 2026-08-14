<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\CLI\Command\Api;

use Shlinkio\Shlink\Rest\ApiKey\Model\ApiKeyMeta;
use Shlinkio\Shlink\Rest\Entity\ApiKey;
use Shlinkio\Shlink\Rest\Service\ApiKeyServiceInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Throwable;

use function chmod;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function is_dir;
use function is_file;
use function is_link;
use function is_writable;
use function link;
use function restore_error_handler;
use function set_error_handler;
use function tempnam;
use function trim;
use function unlink;

#[AsCommand(
    name: ProvisionKeyFileCommand::NAME,
    description: 'Idempotently provisions a named admin API key into an owner-readable file',
    help: <<<HELP
        The <info>%command.name%</info> command provisions a non-expiring admin API key and writes its plaintext
        value to an absolute path with owner-only permissions. The key is never printed to standard output.

        If the file already exists, its key must be valid, unrestricted and match the requested name. Any other
        existing state fails closed and is never overwritten.

            <info>%command.full_name% creator-signal-web-ui /run/secrets/provider/shlink-dashboard-api-key</info>
        HELP,
)]
class ProvisionKeyFileCommand extends Command
{
    public const string NAME = 'api-key:provision-file';

    public function __construct(private readonly ApiKeyServiceInterface $apiKeyService)
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Stable name of the unrestricted API key')] string $name,
        #[Argument(description: 'Absolute path where the plaintext API key will be stored')] string $outputFile,
    ): int {
        $name = trim($name);
        $outputFile = trim($outputFile);

        if ($name === '' || $outputFile === '' || !Path::isAbsolute($outputFile)) {
            $io->error('A non-empty key name and an absolute output file path are required.');
            return Command::INVALID;
        }

        if (is_link($outputFile)) {
            $io->error('Refusing to read or replace a symbolic-link API key file.');
            return Command::FAILURE;
        }

        if (file_exists($outputFile)) {
            return $this->validateExistingFile($io, $name, $outputFile);
        }

        $parentDirectory = dirname($outputFile);
        if (!is_dir($parentDirectory) || !is_writable($parentDirectory)) {
            $io->error('The output file parent directory must already exist and be writable.');
            return Command::FAILURE;
        }

        return $this->provisionNewFile($io, $name, $outputFile, $parentDirectory);
    }

    private function validateExistingFile(SymfonyStyle $io, string $name, string $outputFile): int
    {
        if (!is_file($outputFile)) {
            $io->error('The API key path exists but is not a regular file.');
            return Command::FAILURE;
        }

        if ((fileperms($outputFile) & 0o777) !== 0o400) {
            $io->error('The existing API key file must have owner-read-only permissions (0400).');
            return Command::FAILURE;
        }

        $contents = file_get_contents($outputFile);
        $key = $contents === false ? '' : trim($contents);
        if ($key === '') {
            $io->error('The existing API key file is empty or unreadable; it was not replaced.');
            return Command::FAILURE;
        }

        $result = $this->apiKeyService->check($key);
        $apiKey = $result->apiKey;
        if (!$result->isValid() || $apiKey === null || $apiKey->name !== $name || !ApiKey::isAdmin($apiKey)) {
            $io->error('The existing API key file does not match the requested valid admin key; it was not replaced.');
            return Command::FAILURE;
        }

        $io->success('The requested API key file is already provisioned.');
        return Command::SUCCESS;
    }

    private function provisionNewFile(
        SymfonyStyle $io,
        string $name,
        string $outputFile,
        string $parentDirectory,
    ): int {
        $apiKeyMeta = ApiKeyMeta::fromParams(name: $name);
        $temporaryFile = tempnam($parentDirectory, '.shlink-api-key-');
        if ($temporaryFile === false) {
            $io->error('Could not create a temporary API key file.');
            return Command::FAILURE;
        }

        try {
            $written = file_put_contents($temporaryFile, $apiKeyMeta->key . "\n");
            if ($written === false || !chmod($temporaryFile, 0o400)) {
                $io->error('Could not securely write the temporary API key file.');
                return Command::FAILURE;
            }

            try {
                $this->apiKeyService->create($apiKeyMeta);
            } catch (Throwable) {
                $io->error('Could not create the requested API key; no key file was published.');
                return Command::FAILURE;
            }

            set_error_handler(static fn (): bool => true);
            try {
                $published = link($temporaryFile, $outputFile);
            } finally {
                restore_error_handler();
            }

            if (!$published) {
                try {
                    $this->apiKeyService->deleteByName($name);
                } catch (Throwable) {
                    $io->error(
                        'Could not publish the API key file or roll back its database record; operator cleanup is required.',
                    );
                    return Command::FAILURE;
                }

                $io->error('Could not publish the API key file; its database record was rolled back.');
                return Command::FAILURE;
            }

            $io->success('The requested API key file was securely provisioned.');
            return Command::SUCCESS;
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }
}
