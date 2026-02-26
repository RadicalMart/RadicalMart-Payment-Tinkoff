<?php
/*
 * @package     RadicalMart Payment Tinkoff Plugin
 * @subpackage  plg_radicalmart_payment_tinkoff
 * @version     2.0.1
 * @author      RadicalMart Team - radicalmart.ru
 * @copyright   Copyright (c) 2026 RadicalMart. All rights reserved.
 * @license     GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link        https://radicalmart.ru/
 */

namespace Joomla\Plugin\RadicalMartPayment\Tinkoff\Field\Tinkoff;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\ListField;
use Joomla\Registry\Registry;

class PromoCodeField extends ListField
{
	/**
	 * Field context for get tariffs request.
	 *
	 * @var string|null
	 *
	 * @since 2.0.0
	 */
	protected ?string $context = null;

	/**
	 * List of promo codes.
	 *
	 * @var  array|null
	 *
	 * @since  2.0.0
	 */
	protected ?array $codes = null;

	/**
	 * Method to attach a Form object to the field.
	 *
	 * @param   \SimpleXMLElement  $element  The SimpleXMLElement object representing the `<field>` tag.
	 * @param   mixed              $value    The form field value to validate.
	 * @param   string             $group    The field name group control value.
	 *
	 * @return  bool  True on success.
	 *
	 * @since  2.0.0
	 */
	public function setup(\SimpleXMLElement $element, $value, $group = null): bool
	{
		if (!parent::setup($element, $value, $group))
		{
			return false;
		}

		$this->context = (!empty($this->element['context'])) ? (string) $this->element['context'] : $this->context;

		$this->codes = (!empty($this->element['codes']))
			? (new Registry((string) $this->element['codes']))->toArray() : [];

		return true;
	}

	/**
	 * Method to get the field options.
	 *
	 * @throws  \Exception
	 *
	 * @return  array  The field option objects.
	 *
	 * @since  2.0.0
	 */
	protected function getOptions(): array
	{
		// Prepare options
		$options = parent::getOptions();

		if (empty($this->codes))
		{
			$this->codes = [];
		}

		if (!str_contains($this->context, '.checkout'
			&& !empty($this->value) && !isset($this->codes[$this->value])))
		{
			$this->codes[$this->value] = $this->value;
		}

		foreach ($this->codes as $value => $text)
		{
			$option        = new \stdClass();
			$option->value = $value;
			$option->text  = $text;

			$options[] = $option;
		}

		return $options;
	}
}