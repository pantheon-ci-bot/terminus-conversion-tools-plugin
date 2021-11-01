<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Class RestoreMasterCommand.
 */
class RestoreMasterCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;
    use ConversionCommandsTrait;

    private const BACKUP_GIT_BRANCH = 'master-bckp';
    private const MASTER_GIT_BRANCH = 'master';
    private const DROPS_8_UPSTREAM_ID = 'drupal8';

    /**
     * Restore the master branch to the state before converting a standard Drupal8 site into a Drupal8 site managed by
     * Composer.
     *
     * @command conversion:restore-master
     *
     * @param string $site_id
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function restoreMaster(string $site_id): void
    {
        $site = $this->getSite($site_id);

        $localPath = $this->cloneSiteGitRepository(
            $site,
            sprintf('%s_composer_conversion', $site->getName())
        );

        $git = new Git($localPath);
        if (!$git->isRemoteBranchExists(self::BACKUP_GIT_BRANCH)) {
            throw new TerminusException(sprintf('The backup git branch "%s" does not exist', self::BACKUP_GIT_BRANCH));
        }

        $backupMasterCommitHash = $git->getHeadCommitHash(self::BACKUP_GIT_BRANCH);
        $masterCommitHash = $git->getHeadCommitHash(self::MASTER_GIT_BRANCH);
        if ($backupMasterCommitHash === $masterCommitHash) {
            $this->log()->warning(
                sprintf(
                    'Abort: the backup git branch "%s" matches "%s"',
                    self::BACKUP_GIT_BRANCH,
                    self::MASTER_GIT_BRANCH
                )
            );

            return;
        }

        if (!$this->input()->getOption('yes')
            && !$this->io()->confirm(
                sprintf(
                    'Are you sure you want to restore "%s" git branch to "%s" (the head commit of "%s" git branch)?',
                    self::MASTER_GIT_BRANCH,
                    $backupMasterCommitHash,
                    self::BACKUP_GIT_BRANCH
                )
            )
        ) {
            return;
        }

        $this->log()->notice(
            sprintf('Restoring "%s" git branch to "%s"...', self::MASTER_GIT_BRANCH, $backupMasterCommitHash)
        );
        $git->checkout(self::MASTER_GIT_BRANCH);
        $git->reset('--hard', $backupMasterCommitHash);
        $git->push(self::MASTER_GIT_BRANCH, '--force');

        $this->switchUpstream($site, self::DROPS_8_UPSTREAM_ID);

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $site->getEnvironments()->get('dev');
        $this->log()->notice(sprintf('Link to "dev" environment dashboard: %s', $devEnv->dashboardUrl()));
    }
}