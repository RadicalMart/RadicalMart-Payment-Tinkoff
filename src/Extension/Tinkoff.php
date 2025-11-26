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
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\RadicalMart\Site\Model\PaymentModel as RadicalMartPaymentModel;
use Joomla\Component\RadicalMartExpress\Site\Model\PaymentModel as RadicalMartExpressPaymentModel;
use Joomla\Event\SubscriberInterface;
use Joomla\Http\HttpFactory;
use Joomla\Plugin\RadicalMartPayment\Tinkoff\Helper\IntegrationHelper;
use Joomla\Registry\Registry;

class Tinkoff extends CMSPlugin implements SubscriberInterface
{
	const PaymentTypeCredit = 'credit';
	const PaymentTypeAcquiring = 'acquiring';

	/**
	 * Extension name.
	 *
	 * @var string
	 *
	 * @since __DEPLOY_VERSION__
	 */
	public string $extension = 'plg_radicalmart_payment_tinkoff';

	/**
	 * Test environment.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected bool $securePayTestEnvironment = false;

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
			'onRadicalMartPaymentCallback'        => 'onPaymentCallback',

//			'onRadicalMartExpressGetOrderPaymentMethods' => 'onGetOrderPaymentMethods',
//			'onRadicalMartExpressCheckOrderPay'          => 'onCheckOrderPay',
//			'onRadicalMartExpressPaymentCallback'        => 'onPaymentCallback',
//			'onRadicalMartExpressPrepareConfigForm'      => 'onRadicalMartExpressPrepareConfigForm',
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
		$method->params->set('terminal_key', '');
		$method->params->set('terminal_password', '');


		// Add RadicalMartExpress payment enable statuses
		if ($component === IntegrationHelper::RadicalMartExpress)
		{
			$method->params->set('statuses_available', [1]);
			$method->params->set('statuses_paid', 2);
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
		if (!$this->checkOrderPaymentPlugin($order))
		{
			return false;
		}


		// Check component
		$component = IntegrationHelper::getComponentFromContext($context);
		if (!$component)
		{
			return false;
		}

		// Get params
		$params = IntegrationHelper::getParamsHelper($component)::getPaymentMethodsParams($order->payment->id);

		// Check order status
		if (empty($order->status->id) || !in_array($order->status->id, $params->get('statuses_available', [])))
		{
			return false;
		}

		// Check access params
		if ($params->get('payment_type', self::PaymentTypeAcquiring) === self::PaymentTypeCredit)
		{
			// TODO Credit
			return false;
		}
		else
		{
			if (empty($params->get('terminal_key')) || empty($params->get('terminal_password')))
			{
				return false;
			}
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

		$component    = false;
		$debug        = false;
		$debugger     = 'payment.pay';
		$debuggerFile = 'site_payment_controller.php';
		$debugAction  = 'Init plugin';
		$debugData    = [
			'context' => $context,
		];

		try
		{
			$component = IntegrationHelper::getComponentFromContext($context);
			if (!$component)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_COMPONENT'), 500);
			}

			// Check order payment plugin
			$debug = IntegrationHelper::getDebugHelper($component);
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check payment method plugin', 'start',
				null, null, null, false);
			if (!$this->checkOrderPaymentPlugin($order))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_PLUGIN'), 500);
			}
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, null, false);

			// Get params
			$params      = IntegrationHelper::getParamsHelper($component)::getPaymentMethodsParams($order->payment->id);
			$paymentType = $params->get('payment_type', self::PaymentTypeAcquiring);

			// Prepare request data
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Prepare api request data', 'start',
				null, null, null, false);

			$request = ($paymentType === self::PaymentTypeCredit)
				? $this->getCreditPayRequest($component, $order, $links, $params)
				: $this->getAcquiringPayRequest($component, $order, $links, $params);

			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success');

			// Send request
			$debugData = [
				'request_url'     => $request['url'],
				'request_data'    => $request['data'],
				'request_headers' => $request['headers'],
			];
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Send api request', 'start', null, $debugData);
			$request_response = $this->sendPostRequest($request['url'], $request['data'], $request['headers']);
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [
				'response_data' => $request_response->toArray(),
			]);

			$log = [
				'plugin'       => $this->_name,
				'group'        => $this->_type,
				'payment_type' => $paymentType
			];
			if ($paymentType === self::PaymentTypeCredit)
			{
				$link = false;
			}
			else
			{
				$log['PaymentId'] = $request_response->get('PaymentId');
				$link             = $request_response->get('PaymentURL', false);
			}

			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Add order log', 'start',
				null, null, null, false);
			IntegrationHelper::addOrderLog($component, $order->id, 'tinkoff_pay_success', $log);
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, ['order_id' => $order->id]);

			return [
				'pay_instant' => true,
				'link'        => $link,
			];
		}
		catch (\Throwable $e)
		{
			$debugData['error']         = $e->getCode() . ': ' . $e->getMessage();
			$debugData['error_code']    = $e->getCode();
			$debugData['error_message'] = $e->getMessage();

			if ($debug)
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'error', $debugData['error'], $debugData);
			}

			IntegrationHelper::addLog($this->extension . '.pay.error', Log::ERROR,
				$e->getMessage(), $debugData, $e->getCode());

			if ($component)
			{
				IntegrationHelper::addOrderLog($component, $order->id, 'tinkoff_pay_error', [
					'plugin'        => $this->_name,
					'group'         => $this->_type,
					'error'         => $debugData['error'],
					'error_code'    => $debugData['error_code'],
					'error_message' => $debugData['error_message'],
				]);
			}

			throw new \Exception('Tinkoff: ' . $e->getMessage(), $e->getCode(), $e);
		}
	}

	/**
	 * Method to set RadicalMart & RadicalMartExpress order pay status after payment.
	 *
	 * @param   array                                                    $input   Input data.
	 * @param   RadicalMartExpressPaymentModel| RadicalMartPaymentModel  $model   RadicalMart model.
	 * @param   Registry                                                 $params  RadicalMart params.
	 *
	 * @throws \Exception
	 *
	 * @since  2.0.0
	 */
	public function onPaymentCallback(string                                                 $context, array $input,
	                                  RadicalMartExpressPaymentModel|RadicalMartPaymentModel $model, Registry $params): void
	{
		$component    = false;
		$order_id     = 0;
		$debug        = false;
		$debugger     = 'payment.callback';
		$debuggerFile = 'site_payment_controller.php';
		$debugAction  = 'Init plugin';
		$debugData    = [
			'context' => $context,
		];

		try
		{
			$component = IntegrationHelper::getComponentFromContext($context);
			if (!$component)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_COMPONENT'), 500);
			}

			// Check input data
			$debug     = IntegrationHelper::getDebugHelper($component);
			$debugData = [
				'input' => $input,
			];
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check payment type data', 'start', null,
				$debugData, null, false);

			$paymentType = false;
			if (!empty(($input['Status']) && !empty($input['PaymentId'])))
			{
				$paymentType = self::PaymentTypeAcquiring;
			}
			elseif (!empty($input['id']) && !empty($input['status']))
			{
				$paymentType = self::PaymentTypeCredit;
			}

			if (!$paymentType)
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'response',
					Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_PAYMENT_TYPE'));

				$this->setCallbackResponse();

				return;
			}
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [
				'payment_data' => $paymentType,
			]);

			if ($paymentType === self::PaymentTypeCredit)
			{
				$this->getCreditCallback($component, $order_id, $debugAction, $debugData, $input, $model);
			}
			else
			{
				$this->getAcquiringCallback($component, $order_id, $debugAction, $debugData, $input, $model);
			}

			$debug::addDebug($debugger, $debuggerFile, 'Callback response', 'response');

			$this->setCallbackResponse();
		}
		catch (\Throwable $e)
		{
			$debugData['error']         = $e->getCode() . ': ' . $e->getMessage();
			$debugData['error_code']    = $e->getCode();
			$debugData['error_message'] = $e->getMessage();

			if ($debug)
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'error', $debugData['error'], $debugData);
			}

			IntegrationHelper::addLog($this->extension . '.callback.error', Log::ERROR,
				$e->getMessage(), $debugData, $e->getCode());

			if ($component && $order_id)
			{
				IntegrationHelper::addOrderLog($component, $order_id, 'tinkoff_callback_error', [
					'plugin'        => $this->_name,
					'group'         => $this->_type,
					'error_code'    => $e->getCode(),
					'error_message' => $e->getMessage(),
				]);

			}

			$this->setCallbackResponse();
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

		$data = json_encode($data, JSON_UNESCAPED_UNICODE);

		// Send request
		$http = (new HttpFactory)->getHttp(
			['transport.curl' =>
				 [
					 CURLOPT_SSL_VERIFYHOST => 0,
					 CURLOPT_SSL_VERIFYPEER => 0
				 ]
			]
		);

		$response = $http->post($url, $data, $headers);
		$code     = $response->getStatusCode();
		$message  = $response->getReasonPhrase();
		$contents = (string) $response->getBody();
		if (empty($contents) && !str_starts_with($contents, '{'))
		{
			throw new \Exception($message, $code);
		}

		$registry = new Registry($contents);
		if ((int) $registry->get('ErrorCode') !== 0)
		{

			throw new \Exception($registry->get('Message', $message), $registry->get('ErrorCode', $code));
		}

		return $registry;
	}

	/**
	 * Method to check order payment plugin.
	 *
	 * @param   object  $order
	 *
	 * @return bool True if current plugin, false if not.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function checkOrderPaymentPlugin(object $order): bool
	{
		return ((!empty($order->payment) && !empty($order->payment->plugin)) && $order->payment->plugin === $this->_name);
	}

	/**
	 * Method to set valid callback response.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function setCallbackResponse(): void
	{
		echo 'OK';
		$this->getApplication()->close(200);
	}

	/**
	 * Method to prepare Acquiring Init request data.
	 *
	 * @param   string    $component  Component selector string.
	 * @param   object    $order      Order data object.
	 * @param   array     $links      Plugin links.
	 * @param   Registry  $params     Payment method params.
	 *
	 * @throws \Exception
	 *
	 * @return array Request param data.
	 *
	 * @since __DEPLOY_VERSION__
	 */

	protected function getCreditPayRequest(string $component, object $order, array $links, Registry $params): array
	{
		$result = [
			'url'     => false,
			'data'    => [],
			'headers' => [],
		];


		return $result;
	}

	/**
	 * Method to set Acquiring callback result.
	 *
	 * @param   string                                                   $component    Component selector string.
	 * @param   int                                                      $order_id     Current order id.
	 * @param   string                                                   $debugAction  Current debug action.
	 * @param   array                                                    $debugData    Current debug data.
	 * @param   array                                                    $input        Input data.
	 * @param   RadicalMartExpressPaymentModel| RadicalMartPaymentModel  $model        RadicalMart model.
	 *
	 * @throws \Exception
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function getCreditCallback(string $component, int &$order_id, string &$debugAction, array &$debugData,
	                                     array  $input, RadicalMartExpressPaymentModel|RadicalMartPaymentModel $model)
	{
		// TODO Credit
		throw new \Exception('WORK IN PROGRESS', 500);
	}

	/**
	 * Method to prepare Acquiring Init request data.
	 *
	 * @param   string    $component  Component selector string.
	 * @param   object    $order      Order data object.
	 * @param   array     $links      Plugin links.
	 * @param   Registry  $params     Payment method params.
	 *
	 * @throws \Exception
	 *
	 * @return array Request param data.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function getAcquiringPayRequest(string $component, object $order, array $links, Registry $params): array
	{
		if (empty($params->get('terminal_key')) || empty($params->get('terminal_password')))
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_API_ACCESS'), 403);
		}

		$url = ((int) $params->get('test_acquiring_environment', 0) === 1)
			? 'https://rest-api-test.tinkoff.ru/v2/'
			: 'https://securepay.tinkoff.ru/v2/';
		$url .= 'Init';

		$result = [
			'url'     => $url,
			'data'    => [
				'TerminalKey'     => trim($params->get('terminal_key')),
				'Amount'          => round($order->total['final'] * 100, 2),
				'OrderId'         => $order->id . '_' . time(),
				'Description'     => $order->title,
				'NotificationURL' => $links['callback'],
				'SuccessURL'      => $links['success'] . '/' . $order->number,
				'FailURL'         => $links['error'] . '/' . $order->number,
			],
			'headers' => [],
		];

		if (!empty($order->receipt))
		{
			$result['data']['Amount']      = $order->receipt->amount_integer;
			$result['data']['Description'] = $order->receipt->order_description;
		}

		$result['data']['Token'] = $this->getAcquiringToken($result['data'], $params->get('terminal_password'));

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
			$result['data']['DATA'] = $contacts;
		}

		if ($order->receipt)
		{
			$result['data']['Receipt'] = [
				'Taxation' => $params->get('taxation', 'osn'),
				'Items'    => []
			];
			if (count($contacts) > 0)
			{
				$result['data']['Receipt'] += $contacts;
			}

			foreach ($order->receipt->items as $item)
			{
				$result['data']['Receipt']['Items'][] = [
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
	 * Method to set Acquiring callback result.
	 *
	 * @param   string                                                   $component    Component selector string.
	 * @param   int                                                      $order_id     Current order id.
	 * @param   string                                                   $debugAction  Current debug action.
	 * @param   array                                                    $debugData    Current debug data.
	 * @param   array                                                    $input        Input data.
	 * @param   RadicalMartExpressPaymentModel|RadicalMartPaymentModel  $model        RadicalMart model.
	 *
	 * @throws \Exception
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function getAcquiringCallback(string $component, int &$order_id, string &$debugAction, array &$debugData,
	                                        array  $input, RadicalMartExpressPaymentModel|RadicalMartPaymentModel $model): void
	{
		$debug        = IntegrationHelper::getDebugHelper($component);
		$debugger     = 'payment.callback';
		$debuggerFile = 'site_payment_controller.php';

		$paidStatuses = ['CONFIRMED', 'AUTHORIZED'];
		$debugData    = [
			'input_status'  => $input['Status'],
			'paid_statuses' => $paidStatuses,
		];

		// Check input data
		$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check input data', 'start', null,
			$debugData, null, false);
		if (!in_array($input['Status'], $paidStatuses))
		{
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'response',
				Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_INPUT_STATUS'));

			$this->setCallbackResponse();

			return;
		}
		if (empty($input['PaymentId']))
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_TRANSACTION_NOT_FOUND'), 404);
		}
		if (empty($input['OrderId']))
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_ORDER_NOT_FOUND!'), 404);
		}
		$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success');

		// Get order
		$order_id  = (int) explode('_', $input['OrderId'])[0];
		$debugData = [
			'input_OrderId' => $input['OrderId'],
			'order_id'      => $order_id,
		];
		$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Get order', 'start', null, $debugData);

		if (!$order = $model->getOrder($order_id, 'id'))
		{
			$messages = [];
			foreach ($model->getErrors() as $error)
			{
				$messages[] = ($error instanceof \Exception) ? $error->getMessage() : $error;
			}

			if (empty($messages))
			{
				$messages[] = Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_ORDER_NOT_FOUND');
			}

			$order_id = 0;

			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'error', null, [
				'messages' => $messages,
			]);
			throw new \Exception(implode(PHP_EOL, $messages), 500);
		}

		$order_id     = (int) $order->id;
		$order_number = $order->number;

		$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [
			'order_id'     => $order_id,
			'order_number' => $order->number,
		]);

		// Check order payment method
		$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check payment method', 'start', null, null,
			null, false);
		if (!$this->checkOrderPaymentPlugin($order))
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_PLUGIN'));
		}

		// Check params
		$params = IntegrationHelper::getParamsHelper($component)::getPaymentMethodsParams($order->payment->id);
		if (empty($params->get('terminal_key')) || empty($params->get('terminal_password')))
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_API_ACCESS'), 403);
		}
		if ($params->get('payment_type', self::PaymentTypeAcquiring) === self::PaymentTypeCredit)
		{
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'response',
				Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_PAYMENT_TYPE'));

			$this->setCallbackResponse();

			return;
		}
		$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, null, false);

		// Check signature
		$token     = $this->getAcquiringToken($this->getApplication()->getInput()->json->getArray(),
			$params->get('terminal_password'));
		$debugData = [
			'input_token' => $input['Token'],
			'check_token' => $token,
		];
		$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check signature', 'start', null, $debugData,
			null, false);
		if ($token !== $input['Token'])
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_SIGNATURE'));
		}
		$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, false);

		// Validate request
		$data          = [
			'TerminalKey' => $params->get('terminal_key'),
			'OrderId'     => $input['OrderId'],
		];
		$data['Token'] = $this->getAcquiringToken($data, $params->get('terminal_password'));

		$url = ((int) $params->get('test_acquiring_environment', 0) === 1)
			? 'https://rest-api-test.tinkoff.ru/v2/'
			: 'https://securepay.tinkoff.ru/v2/';
		$url .= 'CheckOrder';

		$debugData = [
			'request_url'  => $url,
			'request_data' => $data
		];
		$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Send validate api request', 'start', null, $debugData);

		$request = $this->sendPostRequest($url, $data);

		$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [
			'response_data' => $request->toArray(),
		]);


		// Check payment.
		$paymentOrderId       = $request->get('OrderId', '');
		$paymentParsedOrderId = (int) explode('_', $paymentOrderId)[0];
		$paymentStatus        = false;
		$paymentPaymentId     = false;
		foreach ($request->get('Payments', []) as $payment)
		{
			if ((int) $payment->Success !== 1)
			{
				continue;
			}

			$paymentPaymentId = $payment->PaymentId;
			$paymentStatus    = $payment->Status;

			break;
		}

		$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check payment data', 'start', null, [
			'payment_Status'         => $paymentStatus,
			'payment_OrderId '       => $paymentOrderId,
			'payment_ParsedOrderId ' => $paymentParsedOrderId,
			'payment_PaymentId'      => $paymentPaymentId,
			'order_id'               => $order_id,
			'order_number'           => $order_number,
		]);

		if ($paymentParsedOrderId !== $order_id)
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PARTPAY_ERROR_ORDER_NOT_FOUND'), 403);
		}

		if (!in_array($paymentStatus, $paidStatuses))
		{
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'response',
				Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_INPUT_STATUS'));

			$this->setCallbackResponse();

			return;
		}

		$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, null, false);

		// Add order log
		$addLog = true;
		foreach ($order->logs as $log)
		{
			if ($log['action'] === 'tinkoff_paid' && $log['PaymentId'] === $paymentPaymentId)
			{
				$addLog = false;
				break;
			}
		}
		if ($addLog)
		{
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Add order log', 'start', null, null, null, false);

			$model->addLog($order->id, 'tinkoff_paid', [
				'plugin'    => $this->_name,
				'group'     => $this->_type,
				'PaymentId' => $paymentPaymentId,
				'user_id'   => -1
			]);
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, null, false);
		}

		// Set paid status
		$paid = (int) $params->get('statuses_paid', 0);
		if (!empty($paid) && (int) $order->status->id !== $paid)
		{
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Change order status', 'start', null, [
				'order_id'      => $order_id,
				'order_number'  => $order_number,
				'new_status_id' => $paid,
			]);

			if (!$model->updateStatus($order->id, $paid, false, -1))
			{
				$messages = [];
				foreach ($model->getErrors() as $error)
				{
					$messages[] = ($error instanceof \Exception) ? $error->getMessage() : $error;
				}

				throw new \Exception(implode(PHP_EOL, $messages));
			}

			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, null, false);
		}
	}

	/**
	 * Generates a token.
	 *
	 * @param   array   $args      Array of query params.
	 * @param   string  $password  Terminal password.
	 *
	 * @return  string Generated api request token.
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected function getAcquiringToken(array $args, string $password): string
	{
		$password = trim($password);

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