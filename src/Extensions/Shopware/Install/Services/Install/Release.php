<?php

namespace Shopware\Install\Services\Install;

use Shopware\Install\Services\PostInstall;
use Shopware\Install\Services\VcsGenerator;
use Shopware\Install\Struct\InstallationRequest;
use ShopwareCli\Config;
use Shopware\Install\Services\ReleaseDownloader;
use Shopware\Install\Services\ConfigWriter;
use Shopware\Install\Services\Database;
use Shopware\Install\Services\Demodata;
use ShopwareCli\Services\IoService;
use ShopwareCli\Services\ProcessExecutor;

/**
 * This install service will run all steps needed to setup shopware in the correct order
 *
 * Class Release
 * @package Shopware\Install\Services\Install
 */
class Release
{
    /**
     * @var Config
     **/
    protected $config;

    /**
     * @var  VcsGenerator
     */
    protected $vcsGenerator;

    /**
     * @var  ConfigWriter
     */
    protected $configWriter;

    /**
     * @var  Database
     */
    protected $database;

    /**
     * @var  Demodata
     */
    protected $demoData;

    /**
     * @var ReleaseDownloader
     */
    private $releaseDownloader;

    /**
     * @var \Shopware\Install\Services\Demodata
     */
    private $demodata;

    /**
     * @var \ShopwareCli\Services\IoService
     */
    private $ioService;
    /**
     * @var \Shopware\Install\Services\PostInstall
     */
    private $postInstall;

    /**
     * @var ProcessExecutor
     */
    private $processExecutor;

    /**
     * @param ReleaseDownloader $releaseDownloader
     * @param Config $config
     * @param VcsGenerator $vcsGenerator
     * @param ConfigWriter $configWriter
     * @param Database $database
     * @param Demodata $demodata
     * @param IoService $ioService
     * @param PostInstall $postInstall
     * @param ProcessExecutor $processExecutor
     */
    public function __construct(
        ReleaseDownloader $releaseDownloader,
        Config $config,
        VcsGenerator $vcsGenerator,
        ConfigWriter $configWriter,
        Database $database,
        Demodata $demodata,
        IoService $ioService,
        PostInstall $postInstall,
        ProcessExecutor $processExecutor
    ) {
        $this->releaseDownloader = $releaseDownloader;
        $this->config = $config;
        $this->vcsGenerator = $vcsGenerator;
        $this->configWriter = $configWriter;
        $this->database = $database;
        $this->demodata = $demodata;
        $this->ioService = $ioService;
        $this->postInstall = $postInstall;
        $this->processExecutor = $processExecutor;
    }

    /**
     * @param InstallationRequest $request
     */
    public function installShopware(InstallationRequest $request)
    {
        $this->releaseDownloader->downloadRelease($request->getRelease(), $request->getInstallDir());

        if ($request->getRelease() === 'latest' || version_compare($request->getRelease(), '5.1.2', '>=')) {
            $this->createDatabase($request);
            $this->createShopwareConfig($request);
            $this->runInstaller($request);
        } else {
            $this->generateVcsMapping($request->getInstallDir());
            $this->createShopwareConfig($request);
            $this->setupDatabase($request);
            $this->lockInstaller($request->getInstallDir());
        }

        $this->ioService->writeln("<info>Running post release scripts</info>");
        $this->postInstall->fixPermissions($request->getInstallDir());
        $this->postInstall->setupTheme($request->getInstallDir());
        $this->postInstall->importCustomDeltas($request->getDbName());
        $this->postInstall->runCustomScripts($request->getInstallDir());

        $this->demodata->runLicenseImport($request->getInstallDir());

        $this->ioService->writeln("<info>Install completed</info>");
    }

    private function createDatabase(InstallationRequest $request)
    {
        $this->database->setup(
            $request->getDbUser() ?: $this->config['DatabaseConfig']['user'],
            $request->getDbPassword() ?: $this->config['DatabaseConfig']['pass'],
            $request->getDbName(),
            $request->getDbHost() ?: $this->config['DatabaseConfig']['host'],
            $request->getDbPort() ?: $this->config['DatabaseConfig']['port'] ?: 3306
        );
    }

    private function generateVcsMapping($installDir)
    {
        $this->vcsGenerator->createVcsMapping($installDir, array_map(function ($repo) {
            return $repo['destination'];
        }, $this->config['ShopwareInstallRepos']));
    }

    private function runInstaller(InstallationRequest $request)
    {
        $delegateOptions = [
            'dbHost', 'dbPort', 'dbSocket', 'dbUser', 'dbPassword', 'dbName',
            'shopLocale', 'shopHost', 'shopPath', 'shopName', 'shopEmail', 'shopCurrency',
            'adminUsername', 'adminPassword', 'adminEmail', 'adminLocale', 'adminName'
        ];

        $arguments = [];
        foreach ($request->all() as $key => $value) {
            if (!in_array($key, $delegateOptions) || strlen($value) === 0) {
                continue;
            }

            $key = strtolower(preg_replace("/[A-Z]/", "-$0", $key));
            $arguments[] = sprintf('--%s="%s"', $key, $value);
        }

        if ($request->getNoSkipImport()) {
            $arguments[] = '--no-skip-import';
        }

        if ($request->getSkipAdminCreation()) {
            $arguments[] = '--skip-admin-creation';
        }

        $arguments = join(" ", $arguments);

        $this->processExecutor->execute("php {$request->getInstallDir()}/recovery/install/index.php {$arguments}");
    }

    /**
     * Write shopware's config.php
     *
     * @param InstallationRequest $request
     */
    private function createShopwareConfig(InstallationRequest $request)
    {
        $this->configWriter->writeConfigPhp(
            $request->getInstallDir(),
            $request->getDbUser() ?: $this->config['DatabaseConfig']['user'],
            $request->getDbPassword() ?: $this->config['DatabaseConfig']['pass'],
            $request->getDbName(),
            $request->getDbHost() ?: $this->config['DatabaseConfig']['host'],
            $request->getDbPort() ?: $this->config['DatabaseConfig']['port'] ?: 3306
        );
    }

    private function setupDatabase(InstallationRequest $request)
    {
        $this->database->setup(
            $request->getDbUser() ?: $this->config['DatabaseConfig']['user'],
            $request->getDbPassword() ?: $this->config['DatabaseConfig']['pass'],
            $request->getDbName(),
            $request->getDbHost() ?: $this->config['DatabaseConfig']['host'],
            $request->getDbPort() ?: $this->config['DatabaseConfig']['port'] ?: 3306
        );
        $this->database->importReleaseInstallDeltas($request->getInstallDir());

        if ($request->getSkipAdminCreation() !== true) {
            $this->database->createAdmin(
                $request->getAdminUsername(),
                $request->getAdminName(),
                $request->getAdminEmail(),
                $request->getAdminLocale(),
                $request->getAdminPassword()
            );
        }
    }

    /**
     * Create install.lock in SW5
     *
     * @param string $installDir
     */
    private function lockInstaller($installDir)
    {
        if (file_exists($installDir . '/recovery/install/data')) {
            touch($installDir . '/recovery/install/data/install.lock');
        }
    }
}
