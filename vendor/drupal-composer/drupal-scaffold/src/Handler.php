<?php

/**
 * @file
 * Contains \DrupalComposer\DrupalScaffold\Handler.
 */

namespace DrupalComposer\DrupalScaffold;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\RemoteFilesystem;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

class Handler {

  const PRE_DRUPAL_SCAFFOLD_CMD = 'pre-drupal-scaffold-cmd';
  const POST_DRUPAL_SCAFFOLD_CMD = 'post-drupal-scaffold-cmd';

  /**
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * @var \Composer\Package\PackageInterface
   */
  protected $drupalCorePackage;

  /**
   * Handler constructor.
   *
   * @param Composer $composer
   * @param IOInterface $io
   */
  public function __construct(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * @param $operation
   * @return mixed
   */
  protected function getCorePackage($operation) {
    if ($operation instanceof InstallOperation) {
      $package = $operation->getPackage();
    }
    elseif ($operation instanceof UpdateOperation) {
      $package = $operation->getTargetPackage();
    }
    if (isset($package) && $package instanceof PackageInterface && $package->getName() == 'drupal/core') {
      return $package;
    }
    return NULL;
  }

  /**
   * Marks scaffolding to be processed after an install or update command.
   *
   * @param \Composer\Installer\PackageEvent $event
   */
  public function onPostPackageEvent(\Composer\Installer\PackageEvent $event){
    $package = $this->getCorePackage($event->getOperation());
    if ($package) {
      // By explicitly setting the core package, the onPostCmdEvent() will
      // process the scaffolding automatically.
      $this->drupalCorePackage = $package;
    }
  }

  /**
   * Post install command event to execute the scaffolding.
   *
   * @param \Composer\Script\Event $event
   */
  public function onPostCmdEvent(\Composer\Script\Event $event) {
    // Only install the scaffolding if drupal/core was installed,
    // AND there are no scaffolding files present.
    if (isset($this->drupalCorePackage)) {
      $this->downloadScaffold();
      // Generate the autoload.php file after generating the scaffold files.
      $this->generateAutoload();
    }
  }

  /**
   * Downloads drupal scaffold files for the current process.
   */
  public function downloadScaffold() {
    $drupalCorePackage = $this->getDrupalCorePackage();
    $webroot = realpath($this->getWebRoot());

    // Collect options, excludes and settings files.
    $options = $this->getOptions();
    $files = array_diff($this->getIncludes(), $this->getExcludes());

    // Call any pre-scaffold scripts that may be defined.
    $dispatcher = new EventDispatcher($this->composer, $this->io);
    $dispatcher->dispatch(self::PRE_DRUPAL_SCAFFOLD_CMD);

    $version = $this->getDrupalCoreVersion($drupalCorePackage);

    $remoteFs = new RemoteFilesystem($this->io);

    $fetcher = new PrestissimoFileFetcher($remoteFs, $options['source'], $files, $this->io, $this->composer->getConfig());
    $fetcher->fetch($version, $webroot);

    $initialFileFetcher = new InitialFileFetcher($remoteFs, $options['source'], $this->getInitial());
    $initialFileFetcher->fetch($version, $webroot);

    // Call post-scaffold scripts.
    $dispatcher->dispatch(self::POST_DRUPAL_SCAFFOLD_CMD);
  }

  /**
   * Generate the autoload file at the project root.  Include the
   * autoload file that Composer generated.
   */
  public function generateAutoload() {
    $vendorPath = $this->getVendorPath();
    $webroot = $this->getWebRoot();

    // Calculate the relative path from the webroot (location of the
    // project autoload.php) to the vendor directory.
    $fs = new SymfonyFilesystem();
    $relativeVendorPath = $fs->makePathRelative($vendorPath, realpath($webroot));

    $fs->dumpFile($webroot . "/autoload.php", $this->autoLoadContents($relativeVendorPath));
  }

  /**
   * Build the contents of the autoload file.
   *
   * @return string
   */
  protected function autoLoadContents($relativeVendorPath) {
    $relativeVendorPath = rtrim($relativeVendorPath, '/');

    $autoloadContents = <<<EOF
<?php

/**
 * @file
 * Includes the autoloader created by Composer.
 *
 * This file was generated by drupal-composer/drupal-scaffold.
 * https://github.com/drupal-composer/drupal-scaffold
 *
 * @see composer.json
 * @see index.php
 * @see core/install.php
 * @see core/rebuild.php
 * @see core/modules/statistics/statistics.php
 */

return require __DIR__ . '/$relativeVendorPath/autoload.php';

EOF;
    return $autoloadContents;
  }

  /**
   * Get the path to the 'vendor' directory.
   *
   * @return string
   */
  public function getVendorPath() {
    $config = $this->composer->getConfig();
    $filesystem = new Filesystem();
    $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
    $vendorPath = $filesystem->normalizePath(realpath($config->get('vendor-dir')));

    return $vendorPath;
  }

  /**
   * Look up the Drupal core package object, or return it from where we cached
   * it in the $drupalCorePackage field.
   *
   * @return PackageInterface
   */
  public function getDrupalCorePackage() {
    if (!isset($this->drupalCorePackage)) {
      $this->drupalCorePackage = $this->getPackage('drupal/core');
    }
    return $this->drupalCorePackage;
  }

  /**
   * Returns the Drupal core version for the given package.
   *
   * @param \Composer\Package\PackageInterface $drupalCorePackage
   *
   * @return string
   */
  protected function getDrupalCoreVersion(PackageInterface $drupalCorePackage) {
    $version = $drupalCorePackage->getPrettyVersion();
    if ($drupalCorePackage->getStability() == 'dev' && substr($version, -4) == '-dev') {
      $version = substr($version, 0, -4);
      return $version;
    }
    return $version;
  }

  /**
   * Retrieve the path to the web root.
   *
   *  @return string
   */
  public function getWebRoot() {
    $drupalCorePackage = $this->getDrupalCorePackage();
    $installationManager = $this->composer->getInstallationManager();
    $corePath = $installationManager->getInstallPath($drupalCorePackage);
    // Webroot is the parent path of the drupal core installation path.
    $webroot = dirname($corePath);

    return $webroot;
  }

  /**
   * Retrieve a package from the current composer process.
   *
   * @param string $name
   *   Name of the package to get from the current composer installation.
   *
   * @return PackageInterface
   */
  protected function getPackage($name) {
    return $this->composer->getRepositoryManager()->getLocalRepository()->findPackage($name, '*');
  }

  /**
   * Retrieve excludes from optional "extra" configuration.
   *
   * @return array
   */
  protected function getExcludes() {
    return $this->getNamedOptionList('excludes', 'getExcludesDefault');
  }

  /**
   * Retrieve list of additional settings files from optional "extra" configuration.
   *
   * @return array
   */
  protected function getIncludes() {
    return $this->getNamedOptionList('includes', 'getIncludesDefault');
  }

  /**
   * Retrieve list of initial files from optional "extra" configuration.
   *
   * @return array
   */
  protected function getInitial() {
    return $this->getNamedOptionList('initial', 'getInitialDefault');
  }

  /**
   * Retrieve a named list of options from optional "extra" configuration.
   * Respects 'omit-defaults', and either includes or does not include the
   * default values, as requested.
   *
   * @return array
   */
  protected function getNamedOptionList($optionName, $defaultFn) {
    $options = $this->getOptions($this->composer);
    $result = array();
    if (empty($options['omit-defaults'])) {
      $result = $this->$defaultFn();
    }
    $result = array_merge($result, (array) $options[$optionName]);

    return $result;
  }

  /**
   * Retrieve excludes from optional "extra" configuration.
   *
   * @return array
   */
  protected function getOptions() {
    $extra = $this->composer->getPackage()->getExtra() + ['drupal-scaffold' => []];
    $options = $extra['drupal-scaffold'] + [
      'omit-defaults' => FALSE,
      'excludes' => [],
      'includes' => [],
      'initial' => [],
      'source' => 'http://cgit.drupalcode.org/drupal/plain/{path}?h={version}',
      // Github: https://raw.githubusercontent.com/drupal/drupal/{version}/{path}
    ];
    return $options;
  }

  /**
   * Holds default excludes.
   */
  protected function getExcludesDefault() {
    return [];
  }

  /**
   * Holds default settings files list.
   */
  protected function getIncludesDefault() {
    $version = $this->getDrupalCoreVersion($this->getDrupalCorePackage());
    list($major, $minor) = explode('.', $version, 3);
    $version = "$major.$minor";

    /**
     * Files from 8.3.x
     *
     * @see http://cgit.drupalcode.org/drupal/tree/?h=8.3.x
     */
    $common = [
      '.csslintrc',
      '.editorconfig',
      '.eslintignore',
      '.eslintrc.json',
      '.gitattributes',
      '.htaccess',
      'index.php',
      'robots.txt',
      'sites/default/default.settings.php',
      'sites/default/default.services.yml',
      'sites/development.services.yml',
      'sites/example.settings.local.php',
      'sites/example.sites.php',
      'update.php',
      'web.config'
    ];

    // Version specific variations.
    switch ($version) {
      case '8.0':
      case '8.1':
      case '8.2':
        $common[] = '.eslintrc';
        $common = array_diff($common, ['.eslintrc.json']);
        break;
    }

    sort($common);
    return $common;
  }

  /**
   * Holds default initial files.
   */
  protected function getInitialDefault() {
    return [];
  }

}
