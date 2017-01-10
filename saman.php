<?php
/**
 * @package     Joomla - > Site and Administrator payment info
 * @subpackage  com_virtuemart
 * @subpackage 	Trangell_saman
 * @copyright   trangell team => https://trangell.com
 * @copyright   Copyright (C) 20016 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined ('_JEXEC') or die('Restricted access');

if (!class_exists ('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . '/vmpsplugin.php');
}

if (!class_exists ('checkHack')) {
	require_once( VMPATH_ROOT . '/plugins/vmpayment/saman/helper/inputcheck.php');
}


class plgVmPaymentSaman extends vmPSPlugin {

	function __construct (& $subject, $config) {

		parent::__construct ($subject, $config);
		$this->_loggable = TRUE;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = array(
			'samanmerchantId' => array('', 'varchar')
		);
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

	public function getVmPluginCreateTableSQL () {
		return $this->createTableSQL ('Payment Saman Table');
	}

	function getTableSQLFields () {

		$SQLfields = array(
			'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'         => 'int(1) UNSIGNED',
			'order_number'                => 'char(64)',
			'order_pass'                  => 'varchar(50)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
			'crypt_virtuemart_pid' 	      => 'varchar(255)',
			'salt'                        => 'varchar(255)',
			'payment_name'                => 'varchar(5000)',
			'amount'                      => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency'            => 'char(3)',
			'email_currency'              => 'char(3)',
			'mobile'                      => 'varchar(12)',
			'tracking_code'               => 'varchar(50)',
			'cardnumber'                  => 'varchar(50)'
		);

		return $SQLfields;
	}


	function plgVmConfirmedOrder ($cart, $order) {
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return null; 
		}
		
		if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL; 
		}
		

		$session = JFactory::getSession();
		$salt = JUserHelper::genRandomPassword(32);
		$crypt_virtuemartPID = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id, $salt);
		if ($session->isActive('uniq')) {
			$session->clear('uniq');
		}
		$session->set('uniq', $crypt_virtuemartPID);
		$payment_currency = $this->getPaymentCurrency($method,$order['details']['BT']->payment_currency_id);
		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total,$payment_currency);
		$currency_code_3 = shopFunctions::getCurrencyByID($payment_currency, 'currency_code_3');
		$email_currency = $this->getEmailCurrency($method);
		$dbValues['payment_name'] = $this->renderPluginName ($method) . '<br />';
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['order_pass'] = $order['details']['BT']->order_pass;
		$dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['crypt_virtuemart_pid'] = $crypt_virtuemartPID;
		$dbValues['salt'] = $salt;
		$dbValues['payment_currency'] = $order['details']['BT']->order_currency;
		$dbValues['email_currency'] = $email_currency;
		$dbValues['amount'] = $totalInPaymentCurrency['value'];
		$dbValues['mobile'] = $order['details']['BT']->phone_2;
		$this->storePSPluginInternalData ($dbValues);
		$id = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id);
		$app	= JFactory::getApplication();
		$dateTime = JFactory::getDate();
			
		$merchantId = $method->samanmerchantId;
		$reservationNumber = time();
		$totalAmount =  $totalInPaymentCurrency['value'];
		$callBackUrl  = JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived';
		$sendUrl = "https\://sep.shaparak.ir/Payment.aspx";
		
		echo '
			<script>
				var form = document.createElement("form");
				form.setAttribute("method", "POST");
				form.setAttribute("action", "'.$sendUrl.'");
				form.setAttribute("target", "_self");

				var hiddenField1 = document.createElement("input");
				hiddenField1.setAttribute("name", "Amount");
				hiddenField1.setAttribute("value", "'.$totalAmount.'");
				form.appendChild(hiddenField1);
				
				var hiddenField2 = document.createElement("input");
				hiddenField2.setAttribute("name", "MID");
				hiddenField2.setAttribute("value", "'.$merchantId.'");
				form.appendChild(hiddenField2);
				
				var hiddenField3 = document.createElement("input");
				hiddenField3.setAttribute("name", "ResNum");
				hiddenField3.setAttribute("value", "'.$reservationNumber.'");
				form.appendChild(hiddenField3);
				
				var hiddenField4 = document.createElement("input");
				hiddenField4.setAttribute("name", "RedirectURL");
				hiddenField4.setAttribute("value", "'.$callBackUrl.'");
				form.appendChild(hiddenField4);
				

				document.body.appendChild(form);
				form.submit();
				document.body.removeChild(form);
			</script>'
		;
	}

public function plgVmOnPaymentResponseReceived(&$html) {
		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
		
		$app	= JFactory::getApplication();		
		$jinput = $app->input;
		$session = JFactory::getSession();

		if ($session->isActive('uniq') && $session->get('uniq') != null) {
			$cryptID = $session->get('uniq'); 
		}
		else {
			$msg= $this->getGateMsg('notff'); 
			$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
			$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
		}
		$orderInfo = $this->getOrderInfo ($cryptID);
		if ($orderInfo != null){
			if (!($currentMethod = $this->getVmPluginMethod($orderInfo->virtuemart_paymentmethod_id))) {
				return NULL; 
			}			
		}
		else {
			return NULL;  
		}
		
		$resNum = $jinput->post->get('ResNum', '0', 'INT');
		$trackingCode = $jinput->post->get('TRACENO', '0', 'INT');
		$stateCode = $jinput->post->get('stateCode', '1', 'INT');
		
		$refNum = $jinput->post->get('RefNum', 'empty', 'STRING');
		if (checkHack::strip($refNum) != $refNum )
			$refNum = "illegal";
		$state = $jinput->post->get('State', 'empty', 'STRING');
		if (checkHack::strip($state) != $state )
			$state = "illegal";
		$cardNumber = $jinput->post->get('SecurePan', 'empty', 'STRING'); 
		if (checkHack::strip($cardNumber) != $cardNumber )
			$cardNumber = "illegal";
	
		$salt = $orderInfo->salt;
		$id = $orderInfo->virtuemart_order_id;
		$uId = $cryptID.':'.$salt;
		
		$order_id = $orderInfo->order_number; 
		//$mobile = $orderInfo->mobile; 
		$payment_id = $orderInfo->virtuemart_paymentmethod_id; 
		$pass_id = $orderInfo->order_pass;
		$method = $this->getVmPluginMethod ($payment_id);
		$price = round($orderInfo->amount,5);
		
		if (JUserHelper::verifyPassword($id , $uId)) {
			if (
				checkHack::checkNum($resNum) &&
				checkHack::checkNum($stateCode) 
			){
				if (isset($state) && ($state == 'OK' || $stateCode == 0)) {
					try {
						$out    = new SoapClient('https://sep.shaparak.ir/payments/referencepayment.asmx?WSDL');
						$resultCode    = $out->VerifyTransaction($refNum, $method->samanmerchantId);
					
						if ($resultCode == $price) {
							$msg= $this->getGateMsg(1); 
							$html = $this->renderByLayout('saman_payment', array(
								'order_number' =>$order_id,
								'order_pass' =>$pass_id,
								'tracking_code' => $trackingCode,
								'status' => $msg
							));
							$this->updateStatus ('C',1,$msg,$id);
							$this->updateOrderInfo ($id,$trackingCode,$cardNumber);
							vRequest::setVar ('html', $html);
							$session->clear('uniq'); 
							$cart = VirtueMartCart::getCart();
							$cart->emptyCart();
						}
						else {
							$msg= $this->getGateMsg($state); 
							if ($state == 'Canceled By User')
								$this->updateStatus ('X',0,$msg,$id);
							$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
							$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
							
						}
					}
					catch(\SoapFault $e)  {
						$msg= $this->getGateMsg('error');
						if ($state == 'Canceled By User')
							$this->updateStatus ('X',0,$msg,$id);
						$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
						$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
						
					}
				}
				else {
					$msg= $this->getGateMsg($state); 
					if ($state == 'Canceled By User')
						$this->updateStatus ('X',0,$msg,$id);
					$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
					$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
				}
			}
			else {
				$msg= $this->getGateMsg('hck2'); 
				$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
				$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
			}
		}
		else {	
			$msg= $this->getGateMsg('notff');
			$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
			$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
		}
		
	}


	protected function getOrderInfo ($id){
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('*')
			->from($db->qn('#__virtuemart_payment_plg_saman'));
		$query->where($db->qn('crypt_virtuemart_pid') . ' = ' . $db->q($id));
		$db->setQuery((string)$query); 
		$result = $db->loadObject();
		return $result;
	}

	protected function updateOrderInfo ($id,$trackingCode,$cardNumber){
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$fields = array($db->qn('tracking_code') . ' = ' . $db->q($trackingCode) , $db->qn('cardnumber') . ' = ' . $db->q($cardNumber));
		$conditions = array($db->qn('virtuemart_order_id') . ' = ' . $db->q($id));
		$query->update($db->qn('#__virtuemart_payment_plg_saman'));
		$query->set($fields);
		$query->where($conditions);
		
		$db->setQuery($query);
		$result = $db->execute();
	}

	
	protected function checkConditions ($cart, $method, $cart_prices) {
		$amount = $this->getCartAmount($cart_prices);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		if($this->_toConvert){
			$this->convertToVendorCurrency($method);
		}
		
		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array ($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}

		if (!is_array ($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (count ($countries) == 0 || in_array ($address['virtuemart_country_id'], $countries) ) {
			return TRUE;
		}

		return FALSE;
	}
	
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {

		if ($this->getPluginMethods($cart->vendorId) === 0) {
			if (empty($this->_name)) {
				$app = JFactory::getApplication();
				$app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
				return false;
			} else {
				return false;
			}
		}
		$method_name = $this->_psType . '_name';

		$htmla = array();
		foreach ($this->methods as $this->_currentMethod) {
			if ($this->checkConditions($cart, $this->_currentMethod, $cart->cartPrices)) {

				$html = '';
				$cartPrices=$cart->cartPrices;
				if (isset($this->_currentMethod->cost_method)) {
					$cost_method=$this->_currentMethod->cost_method;
				} else {
					$cost_method=true;
				}
				$methodSalesPrice = $this->setCartPrices($cart, $cartPrices, $this->_currentMethod, $cost_method);

				$this->_currentMethod->payment_currency = $this->getPaymentCurrency($this->_currentMethod);
				$this->_currentMethod->$method_name = $this->renderPluginName($this->_currentMethod);
				$html .= $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);
				$htmla[] = $html;
			}
		}
		$htmlIn[] = $htmla;
		return true;

	}
	
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return NULL; 
		}
		
		return $this->OnSelectCheck ($cart);
	}
 
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) { 
		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) { 
		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	public function plgVmOnCheckoutCheckDataPayment(  VirtueMartCart $cart) { 
		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return NULL; 
		}
		
		return true;
	}

	function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable ($jplugin_id);
	}
	 
	
	function plgVmonShowOrderPrintPayment ($order_number, $method_id) {
		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}
	function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

		return $this->setOnTablePluginParams ($name, $id, $table);
	}

	static function getPaymentCurrency (&$method, $selectedUserCurrency = false) {

		if (empty($method->payment_currency)) {
			$vendor_model = VmModel::getModel('vendor');
			$vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
			$method->payment_currency = $vendor->vendor_currency;
			return $method->payment_currency;
		} else {

			$vendor_model = VmModel::getModel( 'vendor' );
			$vendor_currencies = $vendor_model->getVendorAndAcceptedCurrencies( $method->virtuemart_vendor_id );

			if(!$selectedUserCurrency) {
				if($method->payment_currency == -1) {
					$mainframe = JFactory::getApplication();
					$selectedUserCurrency = $mainframe->getUserStateFromRequest( "virtuemart_currency_id", 'virtuemart_currency_id', vRequest::getInt( 'virtuemart_currency_id', $vendor_currencies['vendor_currency'] ) );
				} else {
					$selectedUserCurrency = $method->payment_currency;
				}
			}

			$vendor_currencies['all_currencies'] = explode(',', $vendor_currencies['all_currencies']);
			if(in_array($selectedUserCurrency,$vendor_currencies['all_currencies'])){
				$method->payment_currency = $selectedUserCurrency;
			} else {
				$method->payment_currency = $vendor_currencies['vendor_currency'];
			}

			return $method->payment_currency;
		}

	}

	public function getGateMsg ($msgId) {
		switch($msgId){
			case '-1': $out=  'خطای داخل شبکه مالی'; break;
			case '-2': $out=  'سپردها برابر نیستند'; break;
			case '-3': $out=  'ورودی های حاوی کاراکترهای غیر مجاز می باشد'; break;
			case '-4': $out=  'کلمه عبور یا کد فروشنده اشتباه است'; break;
			case '-5': $out=  'Database excetion'; break;
			case '-6': $out=  'سند قبلا برگشت کامل یافته است'; break;
			case '-7': $out=  'رسید دیجیتالی تهی است'; break;
			case '-8': $out=  'طول ورودی های بیش از حد مجاز است'; break;
			case '-9': $out=  'وجود کاراکترهای غیر مجاز در مبلغ برگشتی'; break;
			case '-10': $out=  'رسید دیجیتالی حاوی کاراکترهای غیر مجاز است'; break;
			case '-11': $out=  'طول ورودی های کمتر از حد مجاز است'; break;
			case '-12': $out=  'مبلغ برگشت منفی است'; break;
			case '-13': $out=  'مبلغ برگشتی برای برگشت جزیی بیش از مبلغ برگشت نخورده رسید دیجیتالی است'; break;
			case '-14': $out=  'چنین تراکنشی تعریف نشده است'; break;
			case '-15': $out=  'مبلغ برگشتی به صورت اعشاری داده شده است'; break;
			case '-16': $out=  'خطای داخلی سیستم'; break;
			case '-17': $out=  'برگشت زدن جزیی تراکنشی که با کارت بانکی غیر از بانک سامان انجام پذیرفته است'; break;
			case '-18': $out=  'IP Adderess‌ فروشنده نامعتبر'; break;
			case 'Canceled By User': $out=  'تراکنش توسط خریدار کنسل شده است'; break;
			case 'Invalid Amount': $out=  'مبلغ سند برگشتی از مبلغ تراکنش اصلی بیشتر است'; break;
			case 'Invalid Transaction': $out=  'درخواست برگشت یک تراکنش رسیده است . در حالی که تراکنش اصلی پیدا نمی شود.'; break;
			case 'Invalid Card Number': $out=  'شماره کارت اشتباه است'; break;
			case 'No Such Issuer': $out=  'چنین صادر کننده کارتی وجود ندارد'; break;
			case 'Expired Card Pick Up': $out=  'از تاریخ انقضا کارت گذشته است و کارت دیگر معتبر نیست'; break;
			case 'Allowable PIN Tries Exceeded Pick Up': $out=  'رمز (PIN) کارت ۳ بار اشتباه وارد شده است در نتیجه کارت غیر فعال خواهد شد.'; break;
			case 'Incorrect PIN': $out=  'خریدار رمز کارت (PIN) را اشتباه وارده کرده است'; break;
			case 'Exceeds Withdrawal Amount Limit': $out=  'مبلغ بیش از سقف برداشت می باشد'; break;
			case 'Transaction Cannot Be Completed': $out=  'تراکنش تایید شده است ولی امکان سند خوردن وجود ندارد'; break;
			case 'Response Received Too Late': $out=  'تراکنش در شبکه بانکی  timeout خورده است'; break;
			case 'Suspected Fraud Pick Up': $out=  'خریدار فیلد CVV2 یا تاریخ انقضا را اشتباه وارد کرده و یا اصلا وارد نکرده است.'; break;
			case 'No Sufficient Funds': $out=  'موجودی به اندازه کافی در حساب وجود ندارد'; break;
			case 'Issuer Down Slm': $out=  'سیستم کارت بانک صادر کننده در وضعیت عملیاتی نیست'; break;
			case 'TME Error': $out=  'کلیه خطاهای دیگر بانکی که باعث ایجاد چنین خطایی می گردد'; break;
			case '1': $out=  'تراکنش با موفقیت انجام شده است'; break;
			case 'error': $out ='خطا غیر منتظره رخ داده است';break;
			case 'hck2': $out = 'لطفا از کاراکترهای مجاز استفاده کنید';break;
			case 'notff': $out = 'سفارش پیدا نشد';break;
		}
		return $out;
	}

	protected function updateStatus ($status,$notified,$comments='',$id) {
		$modelOrder = VmModel::getModel ('orders');	
		$order['order_status'] = $status;
		$order['customer_notified'] = $notified;
		$order['comments'] = $comments;
		$modelOrder->updateStatusForOneOrder ($id, $order, TRUE);
	}

}
