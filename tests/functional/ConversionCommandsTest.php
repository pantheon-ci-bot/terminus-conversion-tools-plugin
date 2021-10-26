<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Tests\Traits\TerminusTestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Class ConversionCommandsTest.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
class ConversionCommandsTest extends TestCase
{
    use TerminusTestTrait;

    private const DROPS_8_UPSTREAM_ID = 'drupal8';
    private const DEV_ENV = 'dev';

    /**
     * @var string
     */
    private string $siteName;

    /**
     * @var string
     */
    private string $branch;

    /**
     * @var \Symfony\Contracts\HttpClient\HttpClientInterface
     */
    protected $httpClient;

    /**
     * @inheritdoc
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function setUp(): void
    {
        $this->branch = sprintf('test-%s', substr(uniqid(), -6, 6));
        $this->httpClient = HttpClient::create();

        $this->siteName = uniqid('site-drops8-non-composer-');
        $command = sprintf(
            'site:create %s %s %s',
            $this->siteName,
            $this->siteName,
            $this->getUpstreamId()
        );
        $this->terminus(
            $command,
            [sprintf('--org=%s', $this->getOrg())]
        );
        $this->terminus(
            sprintf('drush %s.dev -- site-install demo_umami', $this->siteName),
            ['-y']
        );

        $this->terminus(sprintf('connection:set %s.dev %s', $this->siteName, 'git'));
        $this->terminus(
            sprintf('site:upstream:set %s %s', $this->siteName, self::DROPS_8_UPSTREAM_ID),
        );

        $contribProjects = [
            'webform',
            'metatag',
            'token',
            'entity',
            'imce',
            'field_group',
            'ctools',
            'date',
            'pathauto',
            'google_analytics',
            'adminimal_theme',
            'bootstrap',
            'omega',
        ];
        $customProjects = [
            'custom1',
            'custom2',
            'custom3',
        ];
        foreach (array_merge($contribProjects, $customProjects) as $name) {
            $this->terminus(
                sprintf('drush %s.dev -- en %s', $this->siteName, $name),
                ['-y']
            );
        }
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        $this->terminus(
            sprintf('site:delete %s', $this->siteName),
            ['--quiet'],
            false
        );
    }

    /**
     * @covers \Pantheon\TerminusConversionTools\Commands\ConvertToComposerSiteCommand
     * @covers \Pantheon\TerminusConversionTools\Commands\ReleaseComposerifyToMasterCommand
     * @covers \Pantheon\TerminusConversionTools\Commands\RestoreMasterCommand
     *
     * @group convert_composer
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function testConversionComposerCommands(): void
    {
        if ($this->isCiEnv()) {
            $this->addGitHostToKnownHosts();
        }

        $this->assertCommand(
            sprintf('conversion:composer %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );

        $this->assertCommand(
            sprintf('conversion:release-to-master %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );

        $this->assertCommand(
            sprintf('conversion:restore-master %s', $this->siteName),
            self::DEV_ENV
        );
    }

    /**
     * Asserts the command executes as expected.
     *
     * @param string $command
     * @param string $env
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function assertCommand(string $command, string $env): void
    {
        $this->terminus($command);
        sleep(60);
        $this->terminus(sprintf('env:clear-cache %s.%s', $this->siteName, $env), [], false);
        $this->assertPagesExists($env);
    }

    /**
     * Asserts pages returns HTTP Status 200 for a set of predefined URLs.
     *
     * @param string $env
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function assertPagesExists(string $env): void
    {
        $baseUrl = sprintf('https://%s-%s.pantheonsite.io', $env, $this->siteName);
        $this->assertEqualsInAttempts(
            fn () => $this->httpClient->request('HEAD', $baseUrl)->getStatusCode(),
            200,
            sprintf(
                'Front page "%s" must return HTTP status code 200',
                $baseUrl
            )
        );

        $pathsToTest = [
            'webform' => 'form/contact',
            'custom1' => 'custom1/page',
            'custom2' => 'custom2/page',
            'custom3' => 'custom3/page',
        ];
        foreach ($pathsToTest as $module => $path) {
            $url = sprintf('%s/%s', $baseUrl, $path);
            $this->assertEquals(
                200,
                $this->httpClient->request('HEAD', $url)->getStatusCode(),
                sprintf('Module "%s" must provide page by path "%s" (%s)', $module, $path, $url)
            );
        }
    }

    /**
     * Returns the upstream ID of the fixture Drops-8 site.
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function getUpstreamId(): string
    {
        if (!getenv('TERMINUS_UPSTREAM_ID')) {
            throw new TerminusException('Missing "TERMINUS_UPSTREAM_ID" env var');
        }

        return getenv('TERMINUS_UPSTREAM_ID');
    }

    /**
     * Adds site's Git host to known_hosts file.
     */
    private function addGitHostToKnownHosts(): void
    {
        $gitInfo = $this->terminusJsonResponse(
            sprintf('connection:info %s.dev --fields=git_host,git_port', $this->siteName)
        );
        $this->assertIsArray($gitInfo);
        $this->assertNotEmpty($gitInfo);
        $this->assertArrayHasKey('git_host', $gitInfo);
        $this->assertArrayHasKey('git_port', $gitInfo);

        $addGitHostToKnownHostsCommand = sprintf(
            'ssh-keyscan -p %d %s 2>/dev/null >> ~/.ssh/known_hosts',
            $gitInfo['git_port'],
            $gitInfo['git_host']
        );
        exec($addGitHostToKnownHostsCommand);
    }

    /**
     * Asserts the actual result is equal to the expected one in multiple attempts.
     *
     * @param callable $callable
     *   Callable which provides the actual result.
     * @param mixed $expected
     *   Expected result.
     * @param string $message
     *   Message.
     */
    private function assertEqualsInAttempts(
        callable $callable,
        $expected,
        string $message = ''
    ): void {
        $attempts = 18;
        $intervalSeconds = 10;

        do {
            $actual = $callable();
            if ($actual === $expected) {
                break;
            }

            sleep($intervalSeconds);
            $attempts--;
        } while ($attempts > 0);

        $this->assertEquals($expected, $actual, $message);
    }
}
