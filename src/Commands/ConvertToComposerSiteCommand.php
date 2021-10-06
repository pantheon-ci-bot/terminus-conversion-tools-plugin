<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Utils\Composer;
use Pantheon\TerminusConversionTools\Utils\Drupal8Projects;
use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Class ConvertToComposerSiteCommand.
 */
class ConvertToComposerSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    private const TARGET_GIT_BRANCH = 'composerify';

    /**
     * @var \Pantheon\Terminus\Helpers\LocalMachineHelper
     */
    private $localMachineHelper;

    /**
     * Convert a standard Drupal8 site into a Drupal8 site managed by Composer.
     *
     * @command conversion:composer
     *
     * @param string $site_id
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusAlreadyExistsException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    public function convert(string $site_id)
    {
        $site = $this->getSite($site_id);

        if (!$site->getFramework()->isDrupal8Framework()) {
            throw new TerminusException(
                'The site {site_name} is not a Drupal 8 based site.',
                ['site_name' => $site->getName()]
            );
        }

        if ('drupal8' !== $site->getUpstream()->get('machine_name')) {
            throw new TerminusException(
                'The site {site_name} is not a "drops-8" upstream based site.',
                ['site_name' => $site->getName()]
            );
        }

        /** @var \Pantheon\Terminus\Models\Environment $env */
        $env = $site->getEnvironments()->get('dev');

        $sourceSitePath = $this->cloneSiteGitRepository(
            $site,
            $env,
            sprintf('%s_source', $site->getName())
        );

        $this->log()->notice(sprintf('Detecting contrib modules and themes in %s...', $sourceSitePath));
        $drupal8ComponentsDetector = new Drupal8Projects($sourceSitePath);
        $projects = $drupal8ComponentsDetector->getContribProjects();
        if (0 < count($projects)) {
            $projectsInline = array_map(
                fn ($project) => sprintf('%s (%s)', $project['name'], $project['version']),
                $projects
            );

            $this->log()->notice(
                sprintf(
                    '%d contrib modules and/or themes are detected: %s',
                    count($projects),
                    implode(', ', $projectsInline)
                )
            );
        } else {
            $this->log()->notice(sprintf('No contrib modules or themes were detected in %s', $sourceSitePath));
        }

        $destinationSitePath = $this->cloneSiteGitRepository(
            $site,
            $env,
            sprintf('%s_destination', $site->getName())
        );

        $this->log()->notice(sprintf('Checking out "%s" git branch...', self::TARGET_GIT_BRANCH));
        $git = new Git($destinationSitePath);
        $git->createAndCheckoutBranch(self::TARGET_GIT_BRANCH);

        $this->log()->notice('Adding contrib projects to Composer...');
        $composer = new Composer($destinationSitePath);
        foreach ($projects as $project) {
            $packageName = sprintf('drupal/%s', $project['name']);
            $packageVersion = sprintf('^%s', $project['version']);
            $composer->require($packageName, $packageVersion);
            $git->commit(sprintf('Add %s (%s) project to Composer', $packageName, $packageVersion));
            $this->log()->notice(sprintf('%s (%s) is added', $packageName, $packageVersion));
        }
        $this->log()->notice('Contrib projects have been added to Composer');

        if ($git->isRemoteBranchExists(self::TARGET_GIT_BRANCH)
            && !$this->input()->getOption('yes')
            && !$this->io()
                ->confirm(
                    sprintf(
                        'The branch "%s" already exists. Are you sure you want to override it?',
                        self::TARGET_GIT_BRANCH
                    )
                )
        ) {
            return;
        }
        $this->log()->notice(sprintf('Pushing changes to "%s" git branch...', self::TARGET_GIT_BRANCH));
        $git->forcePush(self::TARGET_GIT_BRANCH);

        $this->log()->notice('Done!');
    }

    /**
     * Clones the site repository to local machine and return the absolute path to the local copy.
     *
     * @param \Pantheon\Terminus\Models\Site $site
     * @param \Pantheon\Terminus\Models\Environment $env
     * @param $siteDirName
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusAlreadyExistsException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function cloneSiteGitRepository(Site $site, Environment $env, $siteDirName): string
    {
        $path = $site->getLocalCopyDir($siteDirName);
        $this->log()->notice(
            sprintf('Cloning %s site repository into "%s"...', $site->getName(), $path)
        );
        $gitUrl = $env->connectionInfo()['git_url'] ?? null;
        $this->getLocalMachineHelper()->cloneGitRepository($gitUrl, $path, true);
        $this->log()->notice(
            sprintf('The %s site repository has been cloned into "%s"', $site->getName(), $path)
        );

        return $path;
    }

    /**
     * Returns the LocalMachineHelper.
     *
     * @return \Pantheon\Terminus\Helpers\LocalMachineHelper
     */
    private function getLocalMachineHelper(): LocalMachineHelper
    {
        if (isset($this->localMachineHelper)) {
            return $this->localMachineHelper;
        }

        $this->localMachineHelper = $this->getContainer()->get(LocalMachineHelper::class);

        return $this->localMachineHelper;
    }
}
