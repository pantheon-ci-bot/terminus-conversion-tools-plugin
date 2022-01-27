<?php

namespace Pantheon\TerminusConversionTools\Utils;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusConversionTools\Exceptions\Git\GitException;
use Pantheon\TerminusConversionTools\Exceptions\Git\GitMergeConflictException;
use Pantheon\TerminusConversionTools\Exceptions\Git\GitNoDiffException;
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
    private string $repoPath;

    public const DEFAULT_REMOTE = 'origin';
    public const DEFAULT_BRANCH = 'master';

    /**
     * Git constructor.
     *
     * @param string $repoPath
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function __construct(string $repoPath)
    {
        $this->repoPath = $repoPath;

        try {
            $this->execute(['status']);
        } catch (Throwable $t) {
            throw new TerminusException(
                'Failed verify that {repo_path} is a valid Git repository: {error_message}',
                ['repo_path' => $repoPath, 'error_message' => $t->getMessage()]
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
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
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
     * Applies the patch provided in a form of `git diff` options using 3-way merge technique.
     *
     * @param array $diffOptions
     * @param string ...$options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitMergeConflictException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitNoDiffException
     */
    public function apply(array $diffOptions, ...$options): void
    {
        if (!$this->diffFileList(...$diffOptions)) {
            throw new GitNoDiffException(
                sprintf('No diff returned by `git diff %s`', implode(' ', $diffOptions))
            );
        }

        $patch = $this->diff(...$diffOptions);
        try {
            $this->execute(['apply', '--3way', ...$options], $patch);
        } catch (GitException $e) {
            if (1 !== preg_match('/Applied patch to \'(.+)\' with conflicts/', $e->getMessage())) {
                throw $e;
            }

            $unmergedFiles = $this->diffFileList('--diff-filter=U');
            throw new GitMergeConflictException(
                sprintf('Merge conflicts in files: %s', implode(', ', $unmergedFiles)),
                0,
                null,
                $unmergedFiles
            );
        }
    }

    /**
     * Returns TRUE is there is anything to commit.
     *
     * @return bool
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
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
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function push(string $branchName, ...$options): void
    {
        $this->execute(['push', self::DEFAULT_REMOTE, $branchName, ...$options]);
    }

    /**
     * Performs merge operation.
     *
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function merge(...$options): void
    {
        $this->execute(['merge', ...$options]);
    }

    /**
     * Returns TRUE if the branch exists in the remote.
     *
     * @param string $branch
     *   The branch name.
     *
     * @return bool
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
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
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function addRemote(string $remote, string $name)
    {
        try {
            $this->execute(['remote', 'show', $name]);
        } catch (GitException $e) {
            // If remote show fails is because it does not exist. Add it.
            $this->execute(['remote', 'add', $name, $remote]);
        }
        $this->execute(['remote', 'set-url', $name, $remote]);
    }

    /**
     * Fetches from the remote.
     *
     * @param string $remoteName
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
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
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
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
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
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
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
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
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function reset(...$options)
    {
        $this->execute(['reset', ...$options]);
    }

    /**
     * Returns the result of `git diff` command.
     *
     * @param array $options
     *
     * @return string
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function diff(...$options): string
    {
        return $this->execute(['diff', ...$options]);
    }

    /**
     * Returns the result of `git diff` command as a list of files affected.
     *
     * @param mixed ...$options
     *
     * @return array
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function diffFileList(...$options): array
    {
        return array_filter(
            explode(PHP_EOL, $this->diff('--name-only', ...$options))
        );
    }

    /**
     * Deletes remote branch.
     *
     * @param string $branch
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
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
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function getHeadCommitHash(string $branch): string
    {
        $hash = trim(
            $this->execute(['log', '--format=%H', '-n', '1', sprintf('%s/%s', self::DEFAULT_REMOTE, $branch)])
        );
        if (preg_match('/^[0-9a-f]{40}$/i', $hash)) {
            return $hash;
        }

        throw new GitException(sprintf('"%s" is not a valid sha1 commit hash value', $hash));
    }

    /**
     * Returns the list of commit hashes for the branch.
     *
     * @param string $branch
     *
     * @return array
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
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
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    private function execute($command, ?string $input = null): string
    {
        try {
            if (is_string($command)) {
                $process = Process::fromShellCommandline($command, $this->repoPath);
            } else {
                $process = new Process(['git', ...$command], $this->repoPath, null, $input, 180);
            }
            $process->mustRun();
        } catch (Throwable $t) {
            throw new GitException(
                sprintf('Failed executing Git command: %s', $t->getMessage())
            );
        }

        return $process->getOutput();
    }
}
