<?php

namespace Pantheon\TerminusConversionTools\Utils;

use Pantheon\Terminus\Exceptions\TerminusException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Class Git.
 */
class Git
{
    /**
     * @var string
     */
    private string $workingDirectory;

    public const DEFAULT_REMOTE = 'origin';
    public const DEFAULT_BRANCH = 'master';

    /**
     * Git constructor.
     *
     * @param string $workingDirectory
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function __construct(string $workingDirectory)
    {
        $this->workingDirectory = $workingDirectory;

        try {
            $this->execute(['status']);
        } catch (Throwable $t) {
            throw new TerminusException(
                'Failed verify that {working_directory} is a valid Git repository: {error_message}',
                ['working_directory' => $workingDirectory, 'error_message' => $t->getMessage()]
            );
        }
    }

    /**
     * Commits the changes.
     *
     * @param string $commitMessage
     *   The commit message.
     * @param null|array $files
     *   The files to stage.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function commit(string $commitMessage, ?array $files = null): void
    {
        if (null === $files) {
            $this->execute(['add', '-A']);
        } else {
            $this->execute(['add', ...$files]);
        }

        $this->execute(['commit', '-m', $commitMessage]);
    }

    /**
     * Returns the result of `git diff` command.
     *
     * @param string $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function diff(...$options): string
    {
        return $this->execute(['diff', ...$options]);
    }

    /**
     * Returns the result of `git diff` command as a list of files affected.
     *
     * @param string $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function diffFileList(...$options): array
    {
        return array_filter(
            explode(PHP_EOL, $this->diff('--name-only', ...$options))
        );
    }

    /**
     * Returns the result of `git apply` command.
     *
     * @param string $patch
     * @param string ...$options
     *
     * @return string
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function apply(string $patch, ...$options): string
    {
        return $this->execute(['apply', ...$options], $patch);
    }

    /**
     * Returns TRUE is there is anything to commit.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function isAnythingToCommit(): bool
    {
        return '' !== $this->execute(['status', '--porcelain']);
    }

    /**
     * Performs force push of the branch.
     *
     * @param string $branchName
     *   The branch name.
     * @param array $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function push(string $branchName, ...$options): void
    {
        $this->execute(['push', self::DEFAULT_REMOTE, $branchName, ...$options]);
    }

    /**
     * Returns TRUE if the branch exists in the remote.
     *
     * @param string $branch
     *   The branch name.
     *
     * @return bool
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function isRemoteBranchExists(string $branch): bool
    {
        return '' !== trim($this->execute(['ls-remote', self::DEFAULT_REMOTE, $branch]));
    }

    /**
     * Adds remote.
     *
     * @param string $remote
     * @param string $name
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function addRemote(string $remote, string $name)
    {
        $this->execute(['remote', 'add', $name, $remote]);
    }

    /**
     * Fetches from the remote.
     *
     * @param string $remoteName
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function fetch(string $remoteName)
    {
        $this->execute(['fetch', $remoteName]);
    }

    /**
     * Performs checkout operation.
     *
     * @param array $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function checkout(...$options)
    {
        $this->execute(['checkout', ...$options]);
    }

    /**
     * Move files.
     *
     * @param array $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function move(...$options)
    {
        $this->execute(sprintf('git mv %s', implode(' ', $options)));
    }

    /**
     * Removes files.
     *
     * @param array $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function remove(...$options)
    {
        $this->execute(['rm', ...$options]);
    }

    /**
     * Performs reset operation.
     *
     * @param array $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function reset(...$options)
    {
        $this->execute(['reset', ...$options]);
    }

    /**
     * Performs diff operation.
     *
     * @param array $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function diff(...$options)
    {
        return $this->execute(['diff', ...$options]);
    }

    /**
     * Deletes remote branch.
     *
     * @param string $branch
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function deleteRemoteBranch(string $branch)
    {
        $this->execute(['push', self::DEFAULT_REMOTE, '--delete', $branch]);
    }

    /**
     * Returns HEAD commit hash value of the specified remote branch.
     *
     * @param string $branch
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function getHeadCommitHash(string $branch): string
    {
        $hash = trim(
            $this->execute(['log', '--format=%H', '-n', '1', sprintf('%s/%s', self::DEFAULT_REMOTE, $branch)])
        );
        if (preg_match('/^[0-9a-f]{40}$/i', $hash)) {
            return $hash;
        }

        throw new TerminusException(sprintf('"%s" is not a valid sha1 commit hash value', $hash));
    }

    /**
     * Returns the list of commit hashes for the branch.
     *
     * @param string $branch
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function getCommitHashes(string $branch): array
    {
        $commitHashes = $this->execute(['log', $branch, '--pretty=format:%H']);

        return preg_split('/\r\n|\n|\r/', $commitHashes);
    }

    /**
     * Executes the Git command.
     *
     * @param array|string $command
     * @param null|string $input
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function execute($command, ?string $input = null): string
    {
        try {
            if (is_string($command)) {
                $process = Process::fromShellCommandline($command, $this->workingDirectory);
            } else {
                $process = new Process(['git', ...$command], $this->workingDirectory, null, $input, 180);
            }
            $process->mustRun();
        } catch (Throwable $t) {
            throw new TerminusException(
                sprintf('Failed executing Git command: %s', $t->getMessage())
            );
        }

        return $process->getOutput();
    }
}
