<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Exception;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Class ConversionCommandsImportSiteTest.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
class ConversionCommandsImportSiteTest extends ConversionCommandsTestBase
{
    private string $archiveFilePath;

    private const SITE_NAME = 'site-archive-d9';
    private const SITE_ARCHIVE_FILE_NAME = 'site-archive-d9.tar.gz';
    private const DRUPAL_RECOMMENDED_UPSTREAM_ID = 'drupal-recommended';

    /**
     * @inheritdoc
     *
     * @throws \Exception
     */
    protected function setUp(): void
    {
        $archiveUrl = sprintf(
            'https://%s-%s.pantheonsite.io/sites/default/files/%s',
            self::DEV_ENV,
            self::SITE_NAME,
            self::SITE_ARCHIVE_FILE_NAME
        );

        $this->archiveFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::SITE_ARCHIVE_FILE_NAME;
        if (!file_put_contents($this->archiveFilePath, fopen($archiveUrl, 'r'))) {
            throw new Exception(sprintf('Failed to download site archive %s', $archiveUrl));
        }

        $this->siteName = uniqid('fixture-term3-conv-plugin-site-import-');
        $command = sprintf(
            'site:create %s %s %s',
            $this->siteName,
            $this->siteName,
            self::DRUPAL_RECOMMENDED_UPSTREAM_ID
        );
        $this->terminus(
            $command,
            [sprintf('--org=%s', $this->getOrg())]
        );

        if ($this->isCiEnv()) {
            $this->addGitHostToKnownHosts();
        }

        $this->httpClient = HttpClient::create();
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $fs = new Filesystem();
        if (is_file($this->archiveFilePath)) {
            $fs->remove($this->archiveFilePath);
        }

        $extractedPath = basename($this->archiveFilePath, 'tar.gz');
        if (is_dir($extractedPath)) {
            $fs->remove($extractedPath);
        }
    }

    /**
     * @group site_import
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function testConversionCommands(): void
    {
        $command = sprintf(
            'conversion:import-site %s %s',
            $this->siteName,
            $this->archiveFilePath
        );

        $this->terminus($command);
        sleep(30);

        $this->assertPagesExists(self::DEV_ENV);

        $testFileUrl = sprintf('%s/sites/default/files/umami-bundle.png', $this->getBaseTestUrl(self::DEV_ENV));
        $this->assertEqualsInAttempts(
            fn() => $this->httpClient->request('HEAD', $testFileUrl)->getStatusCode(),
            200,
            sprintf('Test file "%s" not found', $testFileUrl)
        );
    }

    /**
     * @inheritdoc
     */
    protected function getUrlsToTestByModule(): array
    {
        return [
            'webform' => 'form/contact',
            'custom1' => 'custom1/page',
            'custom2' => 'custom2/page',
        ];
    }

    /**
     * @inheritdoc
     */
    protected function getUpstreamIdEnvName(): string
    {
        return 'n/a';
    }
}
