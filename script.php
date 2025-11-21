<?php
/*
 * @package     RadicalMart Payment Tinkoff Plugin
 * @subpackage  plg_radicalmart_payment_tinkoff
 * @version     __DEPLOY_VERSION__
 * @author      RadicalMart Team - radicalmart.ru
 * @copyright   Copyright (c) 2025 RadicalMart. All rights reserved.
 * @license     GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link        https://radicalmart.ru/
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseDriver;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Filesystem\Path;

return new class () implements ServiceProviderInterface {
	public function register(Container $container)
	{
		$container->set(InstallerScriptInterface::class,
			new class ($container->get(AdministratorApplication::class)) implements InstallerScriptInterface {
				/**
				 * The application object
				 *
				 * @var  AdministratorApplication
				 *
				 * @since  __DEPLOY_VERSION__
				 */
				protected AdministratorApplication $app;

				/**
				 * The Database object.
				 *
				 * @var   DatabaseDriver
				 *
				 * @since  __DEPLOY_VERSION__
				 */
				protected DatabaseDriver $db;

				/**
				 * Language constant for errors.
				 *
				 * @var string
				 *
				 * @since __DEPLOY_VERSION__
				 */
				protected string $constant = "";

				/**
				 * Update methods.
				 *
				 * @var  array
				 *
				 * @since  __DEPLOY_VERSION__
				 */
				protected array $updateMethods = [];

				/**
				 * Constructor.
				 *
				 * @param   AdministratorApplication  $app  The application object.
				 *
				 * @since __DEPLOY_VERSION__
				 */
				public function __construct(AdministratorApplication $app)
				{
					$this->app = $app;
					$this->db  = Factory::getContainer()->get('DatabaseDriver');
				}

				/**
				 * Function called after the extension is installed.
				 *
				 * @param   InstallerAdapter  $adapter  The adapter calling this method
				 *
				 * @return  boolean  True on success
				 *
				 * @since   __DEPLOY_VERSION__
				 */
				public function install(InstallerAdapter $adapter): bool
				{
					$this->enablePlugin($adapter);

					return true;
				}

				/**
				 * Function called after the extension is updated.
				 *
				 * @param   InstallerAdapter  $adapter  The adapter calling this method
				 *
				 * @return  boolean  True on success
				 *
				 * @since   __DEPLOY_VERSION__
				 */
				public function update(InstallerAdapter $adapter): bool
				{
					return true;
				}

				/**
				 * Function called after the extension is uninstalled.
				 *
				 * @param   InstallerAdapter  $adapter  The adapter calling this method
				 *
				 * @return  boolean  True on success
				 *
				 * @since   __DEPLOY_VERSION__
				 */
				public function uninstall(InstallerAdapter $adapter): bool
				{
					return true;
				}

				/**
				 * Function called before extension installation/update/removal procedure commences.
				 *
				 * @param   string            $type     The type of change (install or discover_install, update, uninstall)
				 * @param   InstallerAdapter  $adapter  The adapter calling this method
				 *
				 * @return  boolean  True on success
				 *
				 * @since   __DEPLOY_VERSION__
				 */
				public function preflight(string $type, InstallerAdapter $adapter): bool
				{
					return true;
				}

				/**
				 * Function called after extension installation/update/removal procedure commences.
				 *
				 * @param   string            $type     The type of change (install or discover_install, update, uninstall)
				 * @param   InstallerAdapter  $adapter  The adapter calling this method
				 *
				 * @return  boolean  True on success
				 *
				 * @since   __DEPLOY_VERSION__
				 */
				public function postflight(string $type, InstallerAdapter $adapter): bool
				{
					$installer = $adapter->getParent();
					if ($type !== 'uninstall')
					{
						$this->checkFiscalizationInstaller($installer);

						// Run updates script
						if ($type === 'update')
						{
							foreach ($this->updateMethods as $method)
							{
								if (method_exists($this, $method))
								{
									$this->$method($adapter);
								}
							}
						}
					}

					return true;
				}

				/**
				 * Enable plugin after installation.
				 *
				 * @param   InstallerAdapter  $adapter  Parent object calling object.
				 *
				 * @since  __DEPLOY_VERSION__
				 */
				protected function enablePlugin(InstallerAdapter $adapter)
				{
					// Prepare plugin object
					$plugin          = new \stdClass();
					$plugin->type    = 'plugin';
					$plugin->element = $adapter->getElement();
					$plugin->folder  = (string) $adapter->getParent()->manifest->attributes()['group'];
					$plugin->enabled = 1;

					// Update record
					$this->db->updateObject('#__extensions', $plugin, ['type', 'element', 'folder']);
				}

				/**
				 * Method to check fiscalization plugin and install if needed.
				 *
				 * @param   Installer|null  $installer  Installer calling object.
				 *
				 * @throws  \Exception
				 *
				 * @since  2.1.0
				 */
				protected function checkFiscalizationInstaller(Installer $installer = null)
				{
					try
					{
						// Find extension
						$db    = $this->db;
						$query = $db->getQuery(true)
							->select('extension_id')
							->from($db->quoteName('#__extensions'))
							->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
							->where($db->quoteName('element') . ' = ' . $db->quote('fiscalization'))
							->where($db->quoteName('folder') . ' = ' . $db->quote('radicalmart'));
						if (!$db->setQuery($query, 0, 1)->loadResult())
						{
							// Download extension
							$src  = 'https://sovmart.ru/download?element=plg_radicalmart_fiscalization';
							$dest = Path::clean($installer->getPath('source') . '/plg_radicalmart_fiscalization.zip');

							if (!$context = file_get_contents($src))
							{
								throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_FISCALIZATION_DOWNLOAD'), -1);
							}
							if (!file_put_contents($dest, $context))
							{
								throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_FISCALIZATION_DOWNLOAD'), -1);
							}

							// Install extension
							if (!$package = InstallerHelper::unpack($dest, true))
							{
								throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_FISCALIZATION_INSTALL'), -1);
							}

							if (!$package['type'])
							{
								InstallerHelper::cleanupInstall('', $package['extractdir']);

								throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_FISCALIZATION_INSTALL'), -1);
							}

							$installer = Installer::getInstance();
							$installer->setPath('source', $package['dir']);
							if (!$installer->findManifest())
							{
								InstallerHelper::cleanupInstall('', $package['extractdir']);

								throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_FISCALIZATION_INSTALL'), -1);
							}

							if (!$installer->install($package['dir']))
							{
								InstallerHelper::cleanupInstall('', $package['extractdir']);

								throw new Exception(Text::_('PLG_RADICALMART_PAYMENT_PAYSELECTION_ERROR_FISCALIZATION_INSTALL'), -1);
							}

							InstallerHelper::cleanupInstall('', $package['extractdir']);
						}
					}
					catch (Exception $e)
					{
						$this->app->enqueueMessage($e->getMessage(), 'error');
					}
				}
			});
	}
};