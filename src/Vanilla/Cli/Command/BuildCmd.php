<?php
/**
 * @copyright 2009-2017 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GNU GPL v2
 * @package Vanilla\Cli\Command
 */

namespace Vanilla\Cli\Command;

use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;
use \Garden\Cli\Cli;
use \Vanilla\Cli\CliUtil;
use \Garden\Cli\Args;

/**
 * Class BuildCmd.
 */
class BuildCmd extends NodeCommandBase {

    public $basePath;

    /**
     * BuildCmd constructor.
     *
     * @param Cli $cli The CLI instance
     */
    public function __construct(Cli $cli) {
        parent::__construct($cli);
        $cli->description('Run the javascirpt build process.')
            ->opt('watch:w', 'Run the build process in watch mode. Best used with the livereload browsre extension.', false, 'bool')
            ->opt('process:p', 'Which version of the build process to use. This will override the one specified in the addon.json')
            ->opt('verbose:v', 'Show detailed build process output', false, 'bool')
            ->opt('reset:r', 'Reinstall the build tools dependencies before building.', false, 'bool');

        $this->basePath = realpath(__DIR__.'/../../FrontendTools/versions/');
    }

    /**
     * @inheritdoc
     */
    protected function doRun(Args $args) {
        $isVerbose = $args->getOpt('verbose') ?? false;
        $shouldRunReset = $args->getOpt('reset') ?? false;

        if ($shouldRunReset) {
            $this->runBuildReset($isVerbose);
        }
        $this->runBuildSetup($isVerbose);
        $this->spawnNodeProcessFromPackageMain(
            $this->determineBuildProcessFolder($args),
            $args
        );
    }

    /**
     * Get the directory of the build process to execute
     *
     * @param Args $args The arguments from the CLI
     *
     * @return string
     */
    public function determineBuildProcessFolder(Args $args): string {
        $processVersion = $args->getOpt('process');

        if (!$processVersion) {
            $addonJsonPath = getcwd().'/addon.json';

            if (file_exists($addonJsonPath)) {
                $addonJson = json_decode(file_get_contents($addonJsonPath), true);
                $processVersion = array_key_exists('buildProcessVersion', $addonJson) ?
                    $addonJson['buildProcessVersion'] :
                    'legacy';
            } else {
                $processVersion = 'legacy';
            }
        }

        $path = $this->basePath.'/'.$processVersion;

        if (!file_exists($path)) {
            $buildDirectories = glob($this->basePath.'/*');
            $validBuildDirectories = [];

            foreach ($buildDirectories as $directory) {
                $validBuildDirectories []= basename($directory);
            }

            $validString = implode(', ', $validBuildDirectories);
            CliUtil::error("Could not find build process version $processVersion
Available build process versions are
    $validString");
        }

        CliUtil::write("\nStarting build process version $processVersion");
        return $path;
    }

    /**
     * Install the node dependencies for a folder.
     *
     * Compares the `installedVersion` in vanillabuild.json
     * and the `version` in package.json to determine if installation is needed.
     * Creates vanillabuild.json if it doesn't exist.
     *
     * @param string $directoryPath The absolute path to run the command in
     * @param bool $shouldResetDirectory Whether or not to set the directory back to the working directory
     * @param bool $isVerbose Determines if verbose output should be printed
     *
     * @return void
     */
    private function installNodeDepsForFolder(string $directoryPath, bool $shouldResetDirectory = true, bool $isVerbose = false) {
        $workingDirectory = getcwd();
        $packageJsonPath = "$directoryPath/package.json";
        $vanillaBuildPath = "$directoryPath/vanillabuild.json";
        $folderName = basename($directoryPath);

        $isVerbose && CliUtil::write(PHP_EOL."Checking dependencies for $folderName");

        if (!file_exists($packageJsonPath)) {
            CliUtil::write("Skipping install for $folderName - No package.json exists");
            return;
        }

        $packageJson = json_decode(file_get_contents($packageJsonPath), true);
        $shouldUpdate = true;
        $vanillaBuild = false;
        $outputMessage = '';

        if (file_exists($vanillaBuildPath)) {
            $vanillaBuild = json_decode(file_get_contents($vanillaBuildPath), true);
            $hasHadNodeUpdate = version_compare($vanillaBuild['nodeVersion'], $this->nodeVersion, '<');
            $packageVersion = $packageJson['version'];
            $installedVersion = $vanillaBuild['installedVersion'];

            if ($hasHadNodeUpdate) {
                CliUtil::write("
This tools dependencies were installed with Node.js version {$vanillaBuild['nodeVersion']}
    Current Node.js version is {$this->nodeVersion}
$folderName's dependencies will need to be reinstalled");
                $this->deleteNodeDepsForFolder($directoryPath, $isVerbose);
                $shouldUpdate = true;
            } else {
                $shouldUpdate = version_compare($packageVersion, $installedVersion, '>');
            }

            if ($shouldUpdate) {
                CliUtil::write("Installing dependencies for $folderName
    Installed Version - $installedVersion
    Current Version - $packageVersion");
            } elseif ($isVerbose) {
                CliUtil::write("Skipping install for $folderName - Already installed
    Installed Version - $installedVersion
    Current Version - $packageVersion");
            }
        } else {
            CliUtil::write("Installing dependencies for $folderName - No Installed Version Found");
        }

        if ($shouldUpdate) {
            $command = 'yarn install';

            chdir($directoryPath);
            $isVerbose ? system($command) : `$command`;
            $shouldResetDirectory && chdir($workingDirectory);

            $newVanillaBuildContents = [
                'installedVersion' => $packageJson['version'],
                'nodeVersion' => $this->nodeVersion,
            ];

            $isVerbose && CliUtil::write("Writing new `vanillabuild.json` file.");
            file_put_contents('vanillabuild.json', json_encode($newVanillaBuildContents));
        }
    }

    /**
     * Delete the node_modules folder and vanillabuild.json file for a directory
     *
     * @param string $directoryPath The directory to do the deletion in.
     * @param bool $isVerbose Whether to verbose output
     *
     * @return void
     */
    private function deleteNodeDepsForFolder(string $directoryPath, bool $isVerbose = false) {
        $vanillaBuildPath = "$directoryPath/vanillabuild.json";
        $folderName = basename($directoryPath);

        CliUtil::write("Deleting dependencies for $folderName");

        $dir = realpath("$directoryPath/node_modules");
        if (PHP_OS === 'Windows') {
            $command = "rd /s /q {$dir}";
        } else {
            $command = "rm -rf {$dir}";
        }

        $isVerbose ? system($command) : `$command`;
        unlink($vanillaBuildPath);
        CliUtil::write("Dependencies deleted for $folderName");
    }

    /**
     * Delete all build tool installation artificts, delete dependencies to start fresh.
     *
     * This tool manages its own dependencies. Sometimes there can be an issue if say, node
     * is upgraded and a native dependancy needs to be rebuilt.
     *
     * @param bool $isVerbose Whether to verbose output
     *
     * @return void
     */
    public function runBuildReset(bool $isVerbose = false) {
        $baseToolsPath = realpath(__DIR__.'/../../FrontendTools');
        $processVersionPaths = glob("$baseToolsPath/versions/*", \GLOB_ONLYDIR);

        $this->deleteNodeDepsForFolder($baseToolsPath, $isVerbose);
        foreach ($processVersionPaths as $processVersionPath) {
            $this->deleteNodeDepsForFolder($processVersionPath, $isVerbose);
        }
    }

    /**
     * Run the setup for the build process.
     *
     * This will only re-install dependencies if the `installedVersion` of
     * the vanillabuild.json file is less than the `version` in its package.json
     *
     * @param bool $isVerbose Whether to verbose output
     *
     * @return void
     */
    public function runBuildSetup(bool $isVerbose) {
        $workingDirectory = getcwd();
        $baseToolsPath = realpath(__DIR__.'/../../FrontendTools');
        $processVersionPaths = glob("$baseToolsPath/versions/*", \GLOB_ONLYDIR);

        $this->installNodeDepsForFolder($baseToolsPath, false, $isVerbose);
        foreach ($processVersionPaths as $processVersionPath) {
            $this->installNodeDepsForFolder($processVersionPath, false, $isVerbose);
        }

        // Change the working directory back
        chdir($workingDirectory);
    }
}
