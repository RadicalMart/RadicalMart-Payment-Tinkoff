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

namespace Joomla\Plugin\RadicalMartPayment\Tinkoff\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

class Tinkoff extends CMSPlugin implements SubscriberInterface
{
	/**
	 * Load the language file on instantiation.
	 *
	 * @var    bool
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected  $autoloadLanguage = true;

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public static function getSubscribedEvents(): array
	{
		return [

			'onRadicalMartPrepareMethodForm'      => 'onRadicalMartPrepareMethodForm',


		//	'onRadicalMartExpressPrepareConfigForm'      => 'onRadicalMartExpressPrepareConfigForm',
		];
	}

	public function onRadicalMartPrepareMethodForm()
	{

	}
}