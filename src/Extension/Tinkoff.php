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

use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Http\HttpFactory;
use Joomla\Plugin\RadicalMartPayment\Tinkoff\Helper\IntegrationHelper;
use Joomla\Registry\Registry;

class Tinkoff extends CMSPlugin implements SubscriberInterface
{
	/**
	 * Plugin params prefix.
	 *
	 * @var   string
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected string $paramsPrefix = 'tinkoff_';

	/**
	 * Load the language file on instantiation.
	 *
	 * @var    bool
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected $autoloadLanguage = true;

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
			'onRadicalMartGetOrderPaymentMethods' => 'onGetOrderPaymentMethods',
			'onRadicalMartCheckOrderPay'          => 'onCheckOrderPay',
			'onRadicalMartPaymentPay'             => 'onPaymentPay',

			// 'onRadicalMartExpressGetOrderPaymentMethods' => 'onGetOrderPaymentMethods',
			// 'onRadicalMartExpressCheckOrderPay'          => 'onCheckOrderPay',
			// 'onRadicalMartExpressCheckOrderPay'          => 'onCheckOrderPay',
			//	'onRadicalMartExpressPrepareConfigForm'      => 'onRadicalMartExpressPrepareConfigForm',
		];
	}

	/**
	 * Prepare RadicalMart & RadicalMart Express order method data.
	 *
	 * @param   string  $context   Context selector string.
	 * @param   object  $method    Method data.
	 * @param   array   $formData  Order form data.
	 * @param   array   $products  Order products data.
	 * @param   array   $currency  Order currency data.
	 *
	 * @throws  \Exception
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public function onGetOrderPaymentMethods(string $context, object $method, array $formData,
	                                         array  $products, array $currency): void
	{
		// Set disabled
		$component = IntegrationHelper::getComponentFromContext($context);
		if (!$component)
		{
			$method->disabled = true;

			return;
		}
		$method->disabled = false;

		// Clean secret param
		$method->params->set('tinkoff_tid', '');
		$method->params->set('tinkoff_password', '');


		// Add RadicalMartExpress payment enable statuses
		if ($component === IntegrationHelper::RadicalMartExpress)
		{
			$method->params->set('payment_available', [1]);
			$method->params->set('paid_status', 2);
		}

		// Set order
		$method->order              = new \stdClass();
		$method->order->id          = $method->id;
		$method->order->title       = $method->title;
		$method->order->code        = $method->code;
		$method->order->description = $method->description;
		$method->order->price       = [];
	}

	/**
	 * Check can order pay.
	 *
	 * @param   string  $context  Context selector string.
	 * @param   object  $order    Order Item data.
	 *
	 * @throws  \Exception
	 *
	 * @return boolean True if can pay, False if not.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	public function onCheckOrderPay(string $context, object $order): bool
	{
		// Check order payment method
		if (empty($order->payment)
			|| empty($order->payment->id)
			|| empty($order->payment->plugin)
			|| $order->payment->plugin !== 'tinkoff')
		{
			return false;
		}

		$component = IntegrationHelper::getComponentFromContext($context);
		if (!$component)
		{
			return false;
		}

		// Get params
		$params = IntegrationHelper::getParamsHelper($component)::getPaymentMethodsParams($order->payment->id);

		// Check access params
		if (empty($params->get('tinkoff_tid')) || empty($params->get('tinkoff_password')))
		{
			return false;
		}

		// Check order status
		if (empty($order->status->id) || !in_array($order->status->id, $params->get('payment_available', [])))
		{
			return false;
		}

		return true;
	}

	/**
	 * Method to create transaction and redirect data to RadicalMart & RadicalMartExpress.
	 *
	 * @param   string    $context  Context selector string.
	 * @param   object    $order    Order data object.
	 * @param   array     $links    Plugin links.
	 * @param   Registry  $params   Component params.
	 *
	 * @throws  \Exception
	 *
	 * @return  array  Payment redirect data on success.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public function onPaymentPay(string $context, object $order, array $links, Registry $params): array
	{
		$data    = false;
		$url     = false;
		$request = false;

		$debug        = false;
		$debugger     = 'payment.pay';
		$debuggerFile = 'site_payment_controller.php';
		$debugAction  = 'Init plugin';

		try
		{

			$component = IntegrationHelper::getComponentFromContext($context);
			if (!$component)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_COMPONENT'), 500);
			}

			$debug = IntegrationHelper::getDebugHelper($component);

			// Check order payment method
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check payment method', 'start');
			if (empty($order->payment)
				|| empty($order->payment->id)
				|| empty($order->payment->plugin)
				|| $order->payment->plugin !== 'tinkoff')
			{

				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_PLUGIN'), 500);
			}

			// Check access params
			$params = IntegrationHelper::getParamsHelper($component)::getPaymentMethodsParams($order->payment->id);
			$test   = ((int) $params->get('tinkoff_test', 1) === 1);
			$credit = ((int) $params->get('tinkoff_credit', 0) === 1);
			if (empty($params->get('tinkoff_tid')) || empty($params->get('tinkoff_password')))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_API_ACCESS'), 403);
			}
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [], null, false);


			// Prepare request data
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Prepare api request data', 'start');
			if ($credit)
			{
				// TODO CREDIT
			}
			else
			{
				$data = $this->prepareSecurePayInitData($component, $order, $links, $params);
				$url  = ($test) ? 'https://rest-api-test.tinkoff.ru/v2/' : 'https://securepay.tinkoff.ru/v2/';
				$url  .= 'Init';
			}
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [], null, false);

			// Send request
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Send api request', 'start');
			$request = $this->sendPostRequest($url, $data);

			echo '<pre>', print_r($request, true), '</pre>';

			exit('2');
		}
		catch (\Throwable $e)
		{
			if ($debug)
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'error', $e->getCode() . ': ' . $e->getMessage(), [
					'context'       => $context,
					'message'       => $e->getMessage(),
					'error_code'    => $e->getCode(),
					'request_url'   => $url,
					'request_data'  => $data,
					'response_data' => $request
				]);
			}

			throw new \Exception('Tinkoff: ' . $e->getMessage(), 500, $e);
		}
	}

	/**
	 * Method to set post request.
	 *
	 * @param   string  $url      Request url.
	 * @param   array   $data     Request data.
	 * @param   array   $headers  Request headers.
	 *
	 * @throws \Exception
	 *
	 * @return Registry Request response Registry object.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function sendPostRequest(string $url, array $data, array $headers = []): Registry
	{
		if (!isset($headers['Content-Type']))
		{
			$headers['Content-Type'] = 'application/json';
		}

		// Send request
		$http = (new HttpFactory)->getHttp();
		$http->setOption('transport.curl', [
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_SSL_VERIFYPEER => 0
		]);

		$data = json_encode($data, JSON_UNESCAPED_UNICODE);

		$response = $http->post($url, $data, $headers);
		$code     = $response->getStatusCode();

		$stream = $response->getBody();
		$stream->rewind();
		$contents = $stream->getContents();
		$registry = (!empty($contents) && str_starts_with($contents, '{')) ? new Registry($contents) : false;

		if (!$registry || (int) $registry->get('ErrorCode') !== 0)
		{
			$message = ($registry) ? $registry->get('Message')
				: $code . ': ' . $response->getReasonPhrase();
			$code    = ($registry) ? $registry->get('ErrorCode') : $code;

			throw new \Exception($message, $code);
		}

		return $registry;
	}

	/**
	 * Method to prepare Secure Pay Init request data.
	 *
	 * @throws \Exception
	 *
	 * @return array Request data.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function prepareSecurePayInitData(string $component, object $order, array $links, Registry $params): array
	{
		$result = [
			'TerminalKey'     => trim($params->get('tinkoff_tid')),
			'Amount'          => round($order->total['final'] * 100, 2),
			'OrderId'         => $order->id . '_' . time(),
			'Description'     => $order->title,
			'NotificationURL' => $links['callback'],
			'SuccessURL'      => $links['success'] . '/' . $order->number,
			'FailURL'         => $links['error'] . '/' . $order->number,
		];

		if (!empty($order->receipt))
		{
			$result['Amount']      = $order->receipt->amount_integer;
			$result['Description'] = $order->receipt->order_description;
		}

		$result['Token'] = $this->getToken($result, trim($params->get('tinkoff_password')));

		$contacts = [];
		if (!empty($order->contacts))
		{
			if (!empty($order->contacts['email']))
			{
				$contacts['Email'] = $order->contacts['email'];
			}

			if (!empty($order->contacts['phone']))
			{
				$contacts['Phone'] = IntegrationHelper::getUserHelper($component)::cleanPhone($order->contacts['phone']);
			}
		}

		if (count($contacts) > 0)
		{
			$result['DATA'] = $contacts;
		}

		if ($order->receipt)
		{
			$result['Receipt'] = [
				'Taxation' => $params->get('tinkoff_sno', 'osn'),
				'Items'    => []
			];
			if (count($contacts) > 0)
			{
				$result['Receipt'] += $contacts;
			}

			foreach ($order->receipt->items as $item)
			{
				$result['Receipt']['Items'][] = [
					'Name'          => $item['name'],
					'Price'         => $item['price_discount_integer'],
					'Quantity'      => $item['quantity'],
					'Amount'        => $item['sum_integer'],
					'Tax'           => $item['vat']['type'],
					'PaymentMethod' => $item['payment_method'],
					'PaymentObject' => $item['payment_object'],
				];
			}
		}

		return $result;
	}

	/**
	 * Generates a token.
	 *
	 * @param   array   $args      Array of query params.
	 * @param   string  $password  Terminal password.
	 *
	 * @return  string Generated api request token.
	 *
	 * @since   1.0
	 */
	protected function getToken(array $args, string $password): string
	{
		$args['Password'] = $password;

		foreach ($args as $key => $value)
		{
			if (!is_object($value) && !is_array($value))
			{
				$args[$key] = (is_bool($value)) ? var_export($value, true) : (string) $value;
			}
		}

		ksort($args);

		if (isset($args['Token']))
		{
			unset($args['Token']);
		}

		$token = implode('', array_values($args));

		return hash('sha256', $token);
	}
}