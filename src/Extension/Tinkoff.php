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
use Joomla\Component\RadicalMart\Site\Model\PaymentModel as RadicalMartPaymentModel;
use Joomla\Component\RadicalMartExpress\Site\Model\PaymentModel as RadicalMartExpressPaymentModel;
use Joomla\Event\SubscriberInterface;
use Joomla\Http\HttpFactory;
use Joomla\Plugin\RadicalMartPayment\Tinkoff\Helper\IntegrationHelper;
use Joomla\Registry\Registry;

class Tinkoff extends CMSPlugin implements SubscriberInterface
{
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
	protected bool $test_environment = false;

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
		$component    = $this->getApplication()->getInput()->getCmd('option');
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

			// Check order payment method
			$debug = IntegrationHelper::getDebugHelper($component);
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check payment method', 'start');
			if (empty($order->payment)
				|| empty($order->payment->id)
				|| empty($order->payment->plugin)
				|| $order->payment->plugin !== 'tinkoff')
			{

				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_PLUGIN'), 500);
			}

			// Check params
			$params = IntegrationHelper::getParamsHelper($component)::getPaymentMethodsParams($order->payment->id);
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
				$url  = ($this->test_environment) ? 'https://rest-api-test.tinkoff.ru/v2/'
					: 'https://securepay.tinkoff.ru/v2/';
				$url  .= 'Init';
			}
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [], null, false);

			// Send request
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Send api request', 'start', null,
				[
					'request_url'  => $url,
					'request_data' => $data,
				]);
			$request = $this->sendPostRequest($url, $data);
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [
				'response_data' => $request->toArray(),
			]);

			$log = [
				'plugin' => $this->_name,
				'group'  => $this->_type,
				'credit' => $credit
			];

			if ($credit)
			{
				// TODO CREDIT
				$link = false;
			}
			else
			{
				$log['PaymentId'] = $request->get('PaymentId');
				$link             = $request->get('PaymentURL', false);
			}

			IntegrationHelper::addOrderLog($component, $order->id, 'tinkoff_create', $log);

			return [
				'pay_instant' => true,
				'link'        => $link,
			];
		}
		catch (\Throwable $e)
		{
			$log = [
				'context'       => $context,
				'component'     => $component,
				'message'       => $e->getMessage(),
				'error_code'    => $e->getCode(),
				'request_url'   => $url,
				'request_data'  => $data,
				'response_data' => ($request) ? $request->toArray() : null,
			];

			if ($debug)
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'error',
					$e->getCode() . ': ' . $e->getMessage(), $log);
			}

			IntegrationHelper::logError($this->extension, $e->getMessage(), $e->getCode(), $log);
			IntegrationHelper::addOrderLog($component, $order->id, 'tinkoff_create_error', [
				'plugin'        => $this->_name,
				'group'         => $this->_type,
				'error_code'    => $e->getCode(),
				'error_message' => $e->getMessage(),
			]);

			throw new \Exception('Tinkoff: ' . $e->getMessage(), 500, $e);
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
		$app = $this->getApplication();

		$debug              = false;
		$order_id           = false;
		$transactionOrderId = false;
		$transactionStatus  = false;

		$data    = false;
		$url     = false;
		$request = false;

		$component = $app->getInput()->getCmd('option');

		$debugger     = 'payment.callback';
		$debuggerFile = 'site_payment_controller.php';
		$debugAction  = 'Init plugin';

		try
		{
			$component = IntegrationHelper::getComponentFromContext($context);
			if (!$component)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_COMPONENT'), 500);
			}

			// Check input data
			$debug = IntegrationHelper::getDebugHelper($component);
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check input data', 'start', null, $input);
			if (empty($input['Status']) || !in_array($input['Status'], [
					'CONFIRMED', 'AUTHORIZED',
				]))
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'response', 'Incorrect Input Status');

				$this->setCallbackResponse();

				return;
			}
			$transactionStatus = $input['Status'];

			if (empty($input['PaymentId']))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_TRANSACTION_NOT_FOUND'), 404);
			}

			if (empty($input['OrderId']))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_ORDER_NOT_FOUND!'), 404);
			}

			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [], null, false);

			$transactionOrderId = $input['OrderId'];
			$order_id           = (int) explode('_', $transactionOrderId)[0];

			// Get order
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Get order', 'start', null, [
				'transaction_OrderId' => $transactionOrderId,
				'order_id'            => $order_id,
			]);
			if (!$order = $model->getOrder($order_id, 'id'))
			{
				$messages = [];
				foreach ($model->getErrors() as $error)
				{
					$messages[] = ($error instanceof \Exception) ? $error->getMessage() : $error;
				}

				if (empty($messages))
				{
					$messages[] = Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_ORDER_NOT_FOUND2');
				}

				$order_id = 0;

				throw new \Exception(implode(PHP_EOL, $messages), 500);
			}

			$order_id     = $order->id;
			$order_number = $order->number;
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [
				'order_id'     => $order_id,
				'order_number' => $order_number,
			], false);

			// Check order payment method
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check payment method', 'start');
			if (empty($order->payment)
				|| empty($order->payment->id)
				|| empty($order->payment->plugin)
				|| $order->payment->plugin !== 'tinkoff')
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_PLUGIN'));
			}

			// Check params
			$params = IntegrationHelper::getParamsHelper($component)::getPaymentMethodsParams($order->payment->id);
			$credit = ((int) $params->get('tinkoff_credit', 0) === 1);
			if (empty($params->get('tinkoff_tid')) || empty($params->get('tinkoff_password')))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_API_ACCESS'), 403);
			}
			$tinkoff_tid      = trim($params->get('tinkoff_tid'));
			$tinkoff_password = trim($params->get('tinkoff_password'));

			// Check order status
			if (empty($order->status->id) || !in_array($order->status->id, $params->get('payment_available', [])))
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_ORDER_STATUS'), 500);
			}
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, false);

			// Validate  data
			$token = $this->getToken($app->getInput()->json->getArray(), $tinkoff_password);
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check token', 'start', null, [
				'input_token' => $input['Token'],
				'check_token' => $token,
			]);
			if ($token !== $input['Token'])
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_SIGNATURE_FAILED'));
			}
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, false);

			// Validate request
			if ($credit)
			{
				// TODO Credit
			}
			else
			{
				$data          = [
					'TerminalKey' => $tinkoff_tid,
					'OrderId'     => $transactionOrderId,
				];
				$data['Token'] = $this->getToken($data, $tinkoff_password);

				$url = ($this->test_environment) ? 'https://rest-api-test.tinkoff.ru/v2/'
					: 'https://securepay.tinkoff.ru/v2/';
				$url .= 'CheckOrder';
			}

			// Send request
			$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Send api request', 'start', null,
				[
					'request_url'  => $url,
					'request_data' => $data,
				]);
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
				'payment_Status'          => $paymentStatus,
				'payment__OrderId '       => $paymentOrderId,
				'payment__ParsedOrderId ' => $paymentParsedOrderId,
				'payment_PaymentId'       => $paymentPaymentId,
				'order_id'                => $order_id,
				'order_number'            => $order_number,
			]);

			if ($paymentParsedOrderId !== $order_id)
			{
				throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_PARTPAY_ERROR_ORDER_NOT_FOUND'), 403);
			}

			// Check transaction status
			if (!$credit && !in_array($paymentStatus, ['CONFIRMED', 'AUTHORIZED']))
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'response', 'Incorrect Transaction Status');

				$this->setCallbackResponse();

				return;
			}
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, false);

			// Add log
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
				$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Add order log', 'start');

				$model->addLog($order->id, 'tinkoff_paid', [
					'plugin'    => $this->_name,
					'group'     => $this->_type,
					'PaymentId' => $paymentPaymentId,
					'user_id'   => -1
				]);
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, false);
			}

			// Set paid status
			$paidStatus = (int) $params->get('paid_status', 0);
			if (!empty($paidStatus))
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Change order status', 'start', null, [
					'order_id'      => $order_id,
					'order_number'  => $order_number,
					'new_status_id' => $paidStatus,
				]);

				if (!$model->updateStatus($order->id, $paidStatus, false, -1))
				{
					$messages = [];
					foreach ($model->getErrors() as $error)
					{
						$messages[] = ($error instanceof \Exception) ? $error->getMessage() : $error;
					}

					throw new \Exception(implode(PHP_EOL, $messages));
				}


				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, false);
			}

			$debug::addDebug($debugger, $debuggerFile, 'Callback response', 'response');

			$this->setCallbackResponse();
		}
		catch (\Throwable $e)
		{
			$log = [
				'context'             => $context,
				'component'           => $component,
				'message'             => $e->getMessage(),
				'error_code'          => $e->getCode(),
				'transaction_OrderId' => $transactionOrderId,
				'transaction_status'  => $transactionStatus,
				'order_id'            => $order_id,
				'request_url'         => $url,
				'request_data'        => $data,
				'response_data'       => $request
			];

			if ($debug)
			{
				$debug::addDebug($debugger, $debuggerFile, $debugAction, 'error',
					$e->getCode() . ': ' . $e->getMessage(), $log);
			}

			IntegrationHelper::logError($this->extension, $e->getMessage(), $e->getCode(), $log);
			if ($order_id)
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
	 * Method to set Callback api valid response.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function setCallbackResponse(): void
	{
		echo 'OK';
		$this->getApplication()->close(200);
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