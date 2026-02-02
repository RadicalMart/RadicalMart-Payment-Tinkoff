<?php
/*
 * @package     RadicalMart Payment Tinkoff Plugin
 * @subpackage  plg_radicalmart_payment_tinkoff
 * @version     __DEPLOY_VERSION__
 * @author      RadicalMart Team - radicalmart.ru
 * @copyright   Copyright (c) 2026 RadicalMart. All rights reserved.
 * @license     GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link        https://radicalmart.ru/
 */

namespace Joomla\Plugin\RadicalMartPayment\Tinkoff\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\RadicalMart\Site\Model\PaymentModel as RadicalMartPaymentModel;
use Joomla\Component\RadicalMartExpress\Site\Model\PaymentModel as RadicalMartExpressPaymentModel;
use Joomla\Event\SubscriberInterface;
use Joomla\Http\HttpFactory;
use Joomla\Http\Response;
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
			'onRadicalMartGetOrderForm'           => 'onRadicalMartGetOrderForm',
			'onRadicalMartGetOrderLogs'           => 'onGetOrderLogs',
			'onRadicalMartCheckOrderPay'          => 'onCheckOrderPay',
			'onRadicalMartPaymentPay'             => 'onPaymentPay',
			'onRadicalMartPaymentCallback'        => 'onPaymentCallback',

			'onRadicalMartExpressGetOrderPaymentMethods' => 'onGetOrderPaymentMethods',
			'onRadicalMartExpressGetOrderLogs'           => 'onGetOrderLogs',
			'onRadicalMartExpressCheckOrderPay'          => 'onCheckOrderPay',
			'onRadicalMartExpressPaymentPay'             => 'onPaymentPay',
			'onRadicalMartExpressPaymentCallback'        => 'onPaymentCallback',
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
		$method->params->set('shop_id', '');
		$method->params->set('showcase_id', '');
		$method->params->set('showcase_password', '');

		// Set order
		$method->order              = new \stdClass();
		$method->order->id          = $method->id;
		$method->order->title       = $method->title;
		$method->order->code        = $method->code;
		$method->order->description = $method->description;
		$method->order->price       = [];
	}

	/**
	 * Prepare RadicalMart order forms.
	 *
	 * @param   string        $context   Context selector string.
	 * @param   Form          $form      Order form object.
	 * @param   array         $formData  Form data array.
	 * @param   array         $products  Shipping method data.
	 * @param   object        $shipping  Shipping method data.
	 * @param   object|false  $payment   Payment method data.
	 * @param   array         $currency  Order currency data.
	 *
	 * @throws \Exception
	 *
	 * @since __DEPLOY_VERSION__
	 */
	public function onRadicalMartGetOrderForm(string $context, Form $form, array $formData,
	                                          array  $products, object $shipping, object|bool $payment,
	                                          array  $currency): void
	{
		$formName = $form->getName();
		if (!in_array($formName, ['com_radicalmart.checkout', 'com_radicalmart.order', 'com_radicalmart.order_site']))
		{
			return;
		}

		$order          = new \stdClass();
		$order->payment = $payment;
		if (!$this->checkOrderPaymentPlugin($order))
		{
			return;
		}

		$params = $this->getPaymentMethodParams(IntegrationHelper::RadicalMart, $payment->id);

		if ($params->get('payment_type', self::PaymentTypeAcquiring) === self::PaymentTypeCredit)
		{

			$xml = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><form/>');
			$xml->addAttribute('addfieldprefix', ($formName === 'com_radicalmart.order')
				? 'Joomla\Component\RadicalMart\Administrator\Field' : 'Joomla\Component\RadicalMart\Site\Field');

			$fieldset = $xml->addChild('fieldset');
			$fieldset->addAttribute('name', 'payment');

			$fields = $fieldset->addChild('fields');
			$fields->addAttribute('name', 'payment');

			$field = $fields->addChild('field');

			$field->addAttribute('label', 'PLG_RADICALMART_PAYMENT_TINKOFF_CREDIT_PROMO_CODE_LABEL');

			if ($formName === 'com_radicalmart.order' || $formName === 'com_radicalmart.checkout')
			{
				$codes = [];
				$field->addAttribute('name', 'promo_code');
				foreach ($params->get('promo_codes', []) as $value => $label)
				{
					$codes[$value] = [
						'id'       => $value,
						'title'    => $label,
						'media'    => [],
						'state'    => 1,
						'disabled' => false,
					];
				}
				$field->addAttribute('methods', (new Registry($codes))->toString());
				$field->addAttribute('type', 'method_payment');
			}
			else
			{
				$field->addAttribute('name', 'promo_code_text');
				$field->addAttribute('type', 'value_text');

				$codes     = $params->get('promo_codes', []);
				$promoCode = (!empty($formData) && !empty($formData['payment'])
					&& !empty($formData['payment']['promo_code'])) ? $formData['payment']['promo_code'] : 'undefined';
				$value     = (isset($codes[$promoCode])) ? $codes[$promoCode] : $promoCode;

				$field->addAttribute('default', $value);
			}

			if ($formName === 'com_radicalmart.checkout')
			{
				$field->addAttribute('parentclass', 'w-100 uk-width-1-1');
				$field->addAttribute('labelclass', 'h4 uk-h4');
			}

			$form->load($xml->asXML());
		}
	}


	/**
	 * Method to display logs in RadicalMart & RadicalMart Express order.
	 *
	 * @param   string  $context  Context selector string.
	 * @param   array   $log      Log data.
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public function onGetOrderLogs(string $context, array &$log)
	{
		if (!str_contains($log['action'], 'tinkoff'))
		{
			return;
		}

		$event              = str_replace('tinkoff_', '', $log['action']);
		$log['action_text'] = Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_LOGS_' . $event);
		if ($event === 'pay_error' || $event === 'callback_error')
		{
			$log['message'] = (!empty($log['error_message'])) ? $log['error_message'] : '';

			return;
		}

		$payment_type = (!empty($log['payment_type'])) ? $log['payment_type'] : self::PaymentTypeAcquiring;
		$constant     = 'PLG_RADICALMART_PAYMENT_TINKOFF_LOGS_' . $event . '_' . $payment_type;

		if ($event === 'pay_success')
		{
			$value = '';
			if ($payment_type === self::PaymentTypeCredit && !empty($log['bidId']))
			{
				$value = $log['bidId'];
			}
			elseif ($payment_type === self::PaymentTypeAcquiring && !empty($log['PaymentId']))
			{
				$value = $log['PaymentId'];
			}

			$log['message'] = Text::sprintf($constant, $value);
		}
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
		$params = $this->getPaymentMethodParams($component, $order->payment->id);

		// Check order status
		if (empty($order->status->id) || !in_array($order->status->id, $params->get('statuses_available', [])))
		{
			return false;
		}

		// Check access params
		if ($params->get('payment_type', self::PaymentTypeAcquiring) === self::PaymentTypeCredit)
		{
			if (empty($params->get('shop_id'))
				|| empty($params->get('showcase_id')) || empty($params->get('showcase_password')))
			{
				return false;
			}
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
			$params      = $this->getPaymentMethodParams($component, $order->payment->id);
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
			$response = $this->sendPostRequest($request['url'], $request['data'], $request['headers']);
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [
				'response_data' => $response->toArray(),
			]);


			$log = [
				'plugin'       => $this->_name,
				'group'        => $this->_type,
				'payment_type' => $paymentType
			];
			if ($paymentType === self::PaymentTypeCredit)
			{
				$log['orderNumber'] = $request['data']['orderNumber'];
				$log['bidId']       = $response->get('id');
				$link               = $response->get('link');
			}
			else
			{
				$log['OrderId']   = $request['data']['OrderId'];
				$log['PaymentId'] = $response->get('PaymentId');
				$link             = $response->get('PaymentURL', false);
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
			if (!empty($input['Status']) && !empty($input['PaymentId']))
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
	 * Method to set POST request.
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

		return $this->parseResponse($http->post($url, $data, $headers));
	}

	/**
	 * Method to set GET request.
	 *
	 * @param   string  $url      Request url.
	 * @param   array   $headers  Request headers.
	 *
	 * @throws \Exception
	 *
	 * @return Registry Request response Registry object.
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function sendGetRequest(string $url, array $headers = []): Registry
	{
		if (!isset($headers['Content-Type']))
		{
			$headers['Content-Type'] = 'application/json';
		}

		// Send request
		$http = (new HttpFactory)->getHttp(
			['transport.curl' =>
				 [
					 CURLOPT_SSL_VERIFYHOST => 0,
					 CURLOPT_SSL_VERIFYPEER => 0
				 ]
			]
		);

		return $this->parseResponse($http->get($url, $headers));
	}

	/**
	 * Method to parse api resonse.
	 *
	 * @param   Response  $response  Http Response
	 *
	 * @throws \Exception
	 *
	 * @return Registry
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function parseResponse(Response $response): Registry
	{
		$code     = $response->getStatusCode();
		$message  = $response->getReasonPhrase();
		$contents = (string) $response->getBody();
		if (empty($contents) || !str_starts_with($contents, '{'))
		{
			if (!empty($contents))
			{
				$message = $contents;
			}
			throw new \Exception($message, $code);
		}

		$registry = new Registry($contents);
		if ((int) $registry->get('ErrorCode') !== 0)
		{
			throw new \Exception($registry->get('Message', $message), $registry->get('ErrorCode', $code));
		}
		elseif (!empty($registry->get('errors')) || !empty($registry->get('validations')))
		{
			$messages = [];

			if (!empty($registry->get('errors')))
			{
				foreach ($registry->get('errors') as $error)
				{
					$messages[] = $error;
				}
			}

			if (!empty($registry->get('validations')))
			{
				$validations = $registry->get('validations');
				if (!is_array($validations))
				{
					$validations = (new Registry($validations))->toArray();
				}
				$messages[] = 'Validations Errors:';
				foreach ($validations as $field => $error)
				{
					$messages[] = $field . ': ' . $error;
				}
			}

			throw new \Exception(implode(PHP_EOL, $messages), $code);
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
	 * Method to get Payment method params.
	 *
	 * @param   string  $component  Component selector string.
	 * @param   int     $method_id  Payment method id.
	 *
	 * @return Registry Payment method params
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function getPaymentMethodParams(string $component, int $method_id): Registry
	{
		$params = IntegrationHelper::getParamsHelper($component)::getPaymentMethodsParams($method_id);

		// Trim params
		foreach (['terminal_key', 'terminal_password', 'shop_id', 'showcase_id', 'showcase_password'] as $path)
		{
			$params->set($path, trim($params->get($path, '')));
		}

		// Add RadicalMartExpress payment enable statuses
		if ($component === IntegrationHelper::RadicalMartExpress)
		{
			$params->set('statuses_available', [1]);
			$params->set('statuses_paid', 2);
			$params->set('payment_type', self::PaymentTypeAcquiring);
		}

		if (!empty($params->get('promo_codes')))
		{
			if (!is_array($params->get('promo_codes')))
			{
				$codes = [];

				foreach ((new Registry($params->get('promo_codes')))->toArray() as $promo_code)
				{
					$codes[trim($promo_code['promo_value'])] = trim($promo_code['promo_label']);
				}

				$params->set('promo_codes', $codes);
			}
		}


		return $params;
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
		if (empty($params->get('shop_id'))
			|| empty($params->get('showcase_id')) || empty($params->get('showcase_password')))
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_API_ACCESS'), 403);
		}

		$test = ((int) $params->get('test_credit', 0) === 1);
		$url  = ($test)
			? 'https://forma.tinkoff.ru/api/partners/v2/orders/create-demo'
			: 'https://forma.tbank.ru/api/partners/v2/orders/create';

		$orderNumber = $order->number;
		if ($test)
		{
			$orderNumber .= '_||_' . time();
		}

		$promoCode = (!empty($order->formData) && !empty($order->formData['payment'])
			&& !empty($order->formData['payment']['promo_code'])) ? $order->formData['payment']['promo_code'] : 'undefined';

		$promoCodes = $params->get('promo_codes', []);
		if (!isset($promoCodes[$promoCode]))
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_PROMO_CODE_NOT_FOUND'), 404);
		}

		$result = [
			'url'     => $url,
			'data'    => [
				'shopId'      => $params->get('shop_id'),
				'showcaseId'  => $params->get('showcase_id'),
				'sum'         => $order->total['final'],
				'items'       => [],
				'orderNumber' => $orderNumber,
				'promoCode'   => $promoCode,
				'webhookURL'  => $links['callback'],
				'successURL'  => $links['success'] . '/' . $order->number,
				'failURL'     => $links['error'] . '/' . $order->number,
			],
			'headers' => [],
		];


		if (!empty($order->receipt))
		{
			$result['data']['sum'] = $order->receipt->amount;
			foreach ($order->receipt->items as $item)
			{
				$result['data']['items'][] = [
					'name'     => $item['name'],
					'quantity' => $item['quantity'],
					'price'    => $item['price_discount'],
				];
			}
		}
		else
		{
			foreach ($order->products as $product)
			{
				$result['data']['items'][] = [
					'name'     => $product->title,
					'quantity' => $product->order['quantity'],
					'price'    => $product->order['final'],
				];
			}
			if (!empty($order->shipping) && !empty($order->shipping->order)
				&& !empty($order->shipping->order->price)
				&& (!empty($order->shipping->order->price['base']) || !empty($order->shipping->order->price['final'])))
			{

				$shipping = $order->shipping;
				$price    = (!empty($shipping->order->price['base']))
					? $shipping->order->price['base'] : $shipping->order->price['final'];

				$result['data']['items'][] = [
					'name'     => (!empty($shipping->order->title)) ? $shipping->order->title : $shipping->title,
					'quantity' => 1,
					'price'    => $price,
				];
			}
		}

		if (!empty($order->contacts))
		{
			$contact = [];
			$fio     = [];
			if (!empty($order->contacts['first_name']))
			{
				$fio['firstName'] = $order->contacts['first_name'];
			}
			if (!empty($order->contacts['last_name']))
			{
				$fio['lastName'] = $order->contacts['last_name'];
			}
			if (!empty($order->contacts['second_name']))
			{
				$fio['middleName'] = $order->contacts['second_name'];
			}
			if (count($fio) > 0)
			{
				$contact['fio'] = $fio;
			}

			if (!empty($order->contacts['phone']))
			{
				$contact['mobilePhone'] = $order->contacts['phone'];
			}
			if (!empty($order->contacts['email']))
			{
				$contact['email'] = $order->contacts['email'];
			}

			if (count($contact) > 0)
			{
				$result['data']['values'] = ['contact' => $contact];
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
	 * @param   RadicalMartExpressPaymentModel| RadicalMartPaymentModel  $model        RadicalMart model.
	 *
	 * @throws \Exception
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function getCreditCallback(string $component, int &$order_id, string &$debugAction, array &$debugData,
	                                     array  $input, RadicalMartExpressPaymentModel|RadicalMartPaymentModel $model)
	{
		$debug        = IntegrationHelper::getDebugHelper($component);
		$debugger     = 'payment.callback';
		$debuggerFile = 'site_payment_controller.php';

		$paidStatuses = ['signed'];
		$inputStatus  = (!empty($input['status'])) ? (string) $input['status'] : false;
		$debugData    = [
			'input_status'  => $input['status'],
			'paid_statuses' => $paidStatuses,
		];

		// Check input data
		$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check input data', 'start', null,
			$debugData, null, false);
		if ($inputStatus && !in_array($inputStatus, $paidStatuses))
		{
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'response',
				Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_INPUT_STATUS'));

			$this->setCallbackResponse();

			return;
		}

		if (empty($input['id']))
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_ORDER_NOT_FOUND!'), 404);
		}

		$test         = (!empty($input['demo']));
		$order_number = ($test) ? explode('_||_', $input['id'])[0] : $input['id'];
		if (empty($order_number))
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_ORDER_NOT_FOUND!'), 404);
		}

		// Get order
		$debugData = [
			'input_id'     => $input['id'],
			'order_number' => $order_number,
		];
		$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Get order', 'start', null, $debugData);

		if (!$order = $model->getOrder($order_number))
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
			'order_number' => $order_number,
		]);

		// Check order payment method
		$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check payment method', 'start', null, null,
			null, false);
		if (!$this->checkOrderPaymentPlugin($order))
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_PLUGIN'));
		}

		// Check params
		$params = $this->getPaymentMethodParams($component, $order->payment->id);
		if ($params->get('payment_type', self::PaymentTypeAcquiring) !== self::PaymentTypeCredit)
		{
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'response',
				Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_PAYMENT_TYPE'));

			$this->setCallbackResponse();

			return;
		}
		if (empty($params->get('shop_id'))
			|| empty($params->get('showcase_id')) || empty($params->get('showcase_password')))
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_API_ACCESS'), 403);
		}
		$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, null, null, false);

		// Validate request
		$url = 'https://forma.tbank.ru/api/partners/v2/orders/';
		$url .= ($test) ? $input['id'] : $order_number;
		$url .= '/info';

		$token = $params->get('showcase_id') . ':' . $params->get('showcase_password');
		if ($test)
		{
			$token = 'demo-' . $token;
		}
		$token = base64_encode($token);

		$debugData = [
			'request_url' => $url,
			'token'       => $token,
		];
		$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Send validate api request', 'start', null, $debugData);

		$response = $this->sendGetRequest($url, [
			'Authorization' => 'Basic ' . $token,
		]);

		$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [
			'response_data' => $response->toArray(),
		]);

		// Check payment
		$paymentId       = $response->get('id', '');
		$paymentParsedId = (!empty($response->get('demo'))) ? explode('_||_', $paymentId)[0] : $paymentId;

		$paymentStatus = (!empty($response->get('status'))) ? (string) $response->get('status') : false;

		$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Check payment data', 'start', null, [
			'payment_status'         => $paymentStatus,
			'payment_id '            => $paymentId,
			'payment_ParsedOrderId ' => $paymentParsedId,
			'order_id'               => $order_id,
			'order_number'           => $order_number,
		]);

		if ($paymentParsedId !== $order_number)
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
		$uid    = $paymentId . '_' . $response->get('created_at', '');
		foreach ($order->logs as $log)
		{
			if ($log['action'] === 'tinkoff_paid' && $log['credit_uid'] === $uid)
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
				'credit_id' => $uid,
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

		$url = ((int) $params->get('test_acquiring', 0) === 1)
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
	 * @param   string                                                  $component    Component selector string.
	 * @param   int                                                     $order_id     Current order id.
	 * @param   string                                                  $debugAction  Current debug action.
	 * @param   array                                                   $debugData    Current debug data.
	 * @param   array                                                   $input        Input data.
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
		$params = $this->getPaymentMethodParams($component, $order->payment->id);
		if ($params->get('payment_type', self::PaymentTypeAcquiring) === self::PaymentTypeCredit)
		{
			$debug::addDebug($debugger, $debuggerFile, $debugAction, 'response',
				Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_PAYMENT_TYPE'));

			$this->setCallbackResponse();

			return;
		}
		if (empty($params->get('terminal_key')) || empty($params->get('terminal_password')))
		{
			throw new \Exception(Text::_('PLG_RADICALMART_PAYMENT_TINKOFF_ERROR_INCORRECT_API_ACCESS'), 403);
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

		$url = ((int) $params->get('test_acquiring', 0) === 1)
			? 'https://rest-api-test.tinkoff.ru/v2/'
			: 'https://securepay.tinkoff.ru/v2/';
		$url .= 'CheckOrder';

		$debugData = [
			'request_url'  => $url,
			'request_data' => $data
		];
		$debug::addDebug($debugger, $debuggerFile, $debugAction = 'Send validate api request', 'start', null, $debugData);

		$response = $this->sendPostRequest($url, $data);

		$debug::addDebug($debugger, $debuggerFile, $debugAction, 'success', null, [
			'response_data' => $response->toArray(),
		]);

		// Check payment
		$paymentOrderId       = $response->get('OrderId', '');
		$paymentParsedOrderId = (int) explode('_', $paymentOrderId)[0];
		$paymentStatus        = false;
		$paymentPaymentId     = false;
		foreach ($response->get('Payments', []) as $payment)
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