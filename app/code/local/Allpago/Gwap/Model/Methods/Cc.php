<?php

require_once 'Zend/Log.php';

/**
 * Allpago - Gwap Payment Module
 *
 * @title      Magento -> Custom Payment Module for Gwap
 * @category   Payment Gateway
 * @package    Allpago_Gwap
 * @author     Allpago Development Team
 * @copyright  Copyright (c) 2013 Allpago
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Allpago_Gwap_Model_Methods_Cc extends Mage_Payment_Model_Method_Cc {

    const PAYMENT_TYPE_AUTH = 'AUTHORIZATION';
    const PAYMENT_TYPE_SALE = 'SALE';

    protected $_code = 'gwap_cc';
    protected $_formBlockType = 'gwap/form_cc';
    protected $_infoBlockType = 'payment/info_cc';
    protected $_allowCurrencyCode = array('BRL', 'USD');
    protected $_canSaveCc = false;
    protected $_resultCode = '';
    protected $_cc = '';    
    protected $_rg = ''; 
    private   $qtd_tentativas = 1;
    /**
     * Assign data to info model instance
     *
     * @param   mixed $data
     * @return  Mage_Payment_Model_Info
     */
    public function assignData($data) {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }

        $info = $this->getInfoInstance();
        
        $info->setCcType($data->getGwapCcCcType())
                ->setCcOwner($data->getGwapCcCcOwner())
                ->setCcLast4(substr($data->getGwapCcCcNumber(), -4))
                ->setCcNumber($data->getGwapCcCcNumber())
                ->setCcCid($data->getGwapCcCcCid())
                ->setCcExpMonth($data->getGwapCcCcExpMonth())
                ->setCcParcelas($data->getGwapCcCcParcelas())
                ->setCcExpYear($data->getGwapCcCcExpYear())
                ->setCcSsIssue($data->getGwapCcCcSsIssue())
                ->setCcSsStartMonth($data->getGwapCcCcSsStartMonth())
                ->setCcSsStartYear($data->getGwapCcCcSsStartYear());
        Mage::getModel('core/session')->setGwapCcId();
        Mage::getModel('core/session')->setGwapCcCcNumber();        
        Mage::getModel('core/session')->setGwapCcId($data->getGwapCcCcCid());
        Mage::getModel('core/session')->setGwapCcCcNumber($data->getGwapCcCcNumber());        
        return $this;
    }

    /**
     * Using internal pages for input payment data
     *
     * @return bool
     */
    public function canUseInternal() {
        return false;
    }

    /**
     *  get Gwap system configuration
     * 
     * @return Varien_Object 
     */
    public function getConfig() {
        return new Varien_Object(Mage::getStoreConfig('payment/gwap_cc'));
    }

    /**
     *  get Gwap auth system configuration
     * 
     * @return Varien_Object 
     */
    public function getAuthConfig() {
        return new Varien_Object(Mage::getStoreConfig('payment/gwap_auth'));
    }

    /**
     * Authorize
     *
     * @param   Varien_Object $orderPayment
     * @param float $amount
     * @return  Mage_Payment_Model_Abstract
     */
    public function authorize(Varien_Object $payment, $amount) {

        $order = $payment->getOrder();
        $orderId = $order->getId();
        $config = $this->getConfig();
        $auth = $this->getAuthConfig();
        $gwap = Mage::getModel('gwap/order')->load($orderId, 'order_id');
        if($this->_cc){
            $cc = new Varien_Object($this->_cc);
        }else{
            $cc = new Varien_Object(unserialize(Mage::helper('core')->decrypt($gwap->getInfo())));
        }

        $parameters = $this->prepareAuthenticationRequestParameters($order, $cc);
        
        //Zend_debug::dump($parameters);
        //die;
        
        $url = $this->getRequestURL();

        //Não efetuar PA
        if ($this->getAcao($order)) {
            //Não salvar dados em tabela
            if($this->_cc){
                if (Mage::getStoreConfig('payment/gwap_oneclick/active')) {
                    $this->_rg = $this->_cc;
                }
                $this->_cc = Mage::helper('core')->encrypt(serialize($parameters));                  
            }else{
                $gwap->clearInstance();
                $gwap = Mage::getModel('gwap/order')->load($orderId, 'order_id');                
                if (Mage::getStoreConfig('payment/gwap_oneclick/active')) {
                    $gwap->setRegistrationCc($gwap->getInfo());
                }
                $gwap->setInfo(Mage::helper('core')->encrypt(serialize($parameters)));
                $gwap->save();
            }
            return $this;
        }
        
        $postString = $this->buildPostString($parameters);
        $response = $this->makeCurlRequest($url, $postString);

        if ($this->_resultCode != '90') {
            Mage::throwException('Payment code: ' . $response['PAYMENT.CODE'] . ' (' . $response['PROCESSING.REASON'] . ' - ' . $response['PROCESSING.RETURN'] . ')');
        }

        // prepare parameters to capture after Pre Authorize success            
        $captureParams = $this->prepareCaptureRequestParameters($response);

        //Não salvar dados em tabela
        if($this->_cc){
            $this->_rg = $this->_cc;
            $this->_cc = Mage::helper('core')->encrypt(serialize($captureParams));             
        }else{
            $gwap->clearInstance();
            $gwap = Mage::getModel('gwap/order')->load($orderId, 'order_id');            
            if (Mage::getStoreConfig('payment/gwap_oneclick/active')) {
                $gwap->setRegistrationCc($gwap->getInfo());
            }   
            $gwap->setInfo(Mage::helper('core')->encrypt(serialize($captureParams)));
            $gwap->save();
        }

        return $this;
    }

    /**
     * Capture
     *
     * @param   Varien_Object $orderPayment
     * @param float $amount
     * @return  Mage_Payment_Model_Abstract
     */
    public function capture(Varien_Object $payment, $amount) {

        $log = Mage::getModel('allpago_mc/log');
        $gwap = Mage::getModel('gwap/order')->load($payment->getOrder()->getId(), 'order_id');

        // Processamento de pedidos não novos
        if ($gwap->getStatus() == Allpago_Gwap_Model_Order::STATUS_CREATED) {
            $this->authorize($payment, $amount);
            /**
             * reload item
             */
            $gwap->clearInstance();
            $gwap = Mage::getModel('gwap/order')->load($payment->getOrder()->getId(), 'order_id');

            $gwap->setStatus(Allpago_Gwap_Model_Order::STATUS_CAPTUREPAYMENT);
            $gwap->save();
        }

        $url = $this->getRequestURL();
        
        if($this->_cc){
            $cc = new Varien_Object(unserialize(Mage::helper('core')->decrypt($this->_cc)));
        }else{
            $cc = new Varien_Object(unserialize(Mage::helper('core')->decrypt($gwap->getInfo())));
        }
                
        $parameters = $cc->toArray();
        $postString = $this->buildPostString($parameters);

        $response = $this->makeCurlRequest($url, $postString);
        if ($this->_resultCode != '90') {
            Mage::throwException('Payment code: ' . $response['PAYMENT.CODE'] . ' (' . $response['PROCESSING.REASON'] . ' - ' . $response['PROCESSING.RETURN'] . ')');
        }
        $log->add($gwap->getOrderId(), 'Payment', 'capture()', Allpago_Mc_Model_Mc::STATUS_CAPTURED, 'Pagamento capturado');

        //Completar processo do pedido para o caso do RG gerar erro.
        $gwap->setStatus(Allpago_Gwap_Model_Order::STATUS_CAPTURED);
        $gwap->setErrorCode(null);
        $gwap->setErrorMessage(null);
        $gwap->setInfo(null);
        $gwap->setTries(0);
        $gwap->setAbandoned(0);
        $gwap->save();

        // Se oneclick ativado cria(RG)/atualiza(RR) no gateway
        if (Mage::getStoreConfig('payment/gwap_oneclick/active')) {
            $customerId = $payment->getOrder()->getCustomerId();
            $registrationId = $this->getRegistrationInfo($customerId);
            if ($registrationId) {
                //Update
                $response = $this->fetchCustomerRegistration($gwap, $registrationId);
                if ($this->_resultCode != '90') {
                    $gwap->setRegistrationCc(null);
                    $gwap->save();
                    Mage::throwException('Payment code: ' . $response['PAYMENT.CODE'] . ' (' . $response['PROCESSING.REASON'] . ' - ' . $response['PROCESSING.RETURN'] . ')');
                }
            } else {
                $this->newRegistrationInfo($customerId, $gwap);
            }
        }

        $gwap->setRegistrationCc(null);
        $gwap->save();

        return $this;
    }

    public function authorizeNow($order, $cc) {

        try {
            $this->_cc = '';
            $this->_cc = $cc;
            $log = Mage::getModel('allpago_mc/log');
            $this->authorize($order->getPayment(), $order->getGrandTotal());

            //Atualiza o objeto, para caso tenha sido atualizado no método Authorize
            $gatewayPayment = Mage::getModel('allpago_mc/payment')->load($order->getId(), 'order_id');
            //Salva log
            if (!Mage::getStoreConfig('payment/gwap_cc/acao')) {
                $log->add($order->getId(), 'Payment', 'authorize()', Allpago_Mc_Model_Mc::STATUS_AUTHORIZED, 'Pagamento autorizado');
            }
            //Altera os dados na tabela auxiliar
            $gatewayPayment->setStatus((($this->getAcao($order)) ? Allpago_Mc_Model_Mc::STATUS_AUTHORIZED : Allpago_Mc_Model_Mc::STATUS_CAPTUREPAYMENT));
            $gatewayPayment->setErrorCode(null);
            $gatewayPayment->setErrorMessage(null);
            $time = Mage::getStoreConfig('allpago/allpago_mc/tempo_espera');
            $gatewayPayment->setUpdatedAt(Mage::getModel('core/date')->date("Y-m-d H:i:s", strtotime("-{$time} hours")));
            $gatewayPayment->setTries(0);
            $gatewayPayment->setAbandoned(0);
            $gatewayPayment->save();

            //Capture
            if (Mage::getStoreConfig('payment/gwap_cc/acao')) {

                try {
                    $order->getPayment()->getMethodInstance()->capture($order->getPayment(), $order->getGrandTotal());

                    Mage::app()->getStore()->setConfig(Mage_Sales_Model_Order::XML_PATH_EMAIL_ENABLED, "1");
                    $order->sendNewOrderEmail();
                    Mage::app()->getStore()->setConfig(Mage_Sales_Model_Order::XML_PATH_EMAIL_ENABLED, "0");
                    //Atualiza o objeto, para caso tenha sido alterado no método Capture
                    $gatewayPayment = Mage::getModel('allpago_mc/payment')->load($order->getId(), 'order_id');
                    //Altera os dados na tabela auxiliar
                    $gatewayPayment->setStatus(Allpago_Mc_Model_Mc::STATUS_CAPTURED);
                    $gatewayPayment->setErrorCode(null);
                    $gatewayPayment->setErrorMessage(null);
                    $gatewayPayment->setInfo(null);
                    $gatewayPayment->setTries(0);
                    $gatewayPayment->setAbandoned(0);
                    //Gera invoice e manda e-mail
                    $invoice = $order->prepareInvoice()->register();
                    $invoice->setEmailSent(false);
                    $invoice->setState(Mage_Sales_Model_Order_Invoice::STATE_PAID);
                    $invoice->getOrder()->setTotalPaid($order->getGrandTotal());
                    $invoice->getOrder()->setBaseTotalPaid($order->getBaseGrandTotal());
                    $invoice->getOrder()->setCustomerNoteNotify(true);
                    $invoice->getOrder()->setIsInProcess(true);
                    Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder())->save();
                    $invoice->sendEmail(true, 'Pedido realizado com sucesso');

                    //Altera o status do pedido
                    $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true)->save();
                    $block = Mage::app()->getLayout()->getMessagesBlock();
                    $block->addSuccess('Transação autorizada com sucesso');
                } catch (Exception $e) {
                    $this->cancelOrder($order, $e->getMessage());
                    $this->failureRedirect($e->getMessage());
                    return false;
                }
            } else {
                $block = Mage::app()->getLayout()->getMessagesBlock();
                $block->addSuccess('Transação autorizada com sucesso');
                return true;
            }
        } catch (Exception $e) {
            $this->cancelOrder($order, $e->getMessage());
            $this->failureRedirect($e->getMessage());
            return false;
        }
    }

    public function cancelOrder($order, $errorMsg) {
        $log = Mage::getModel('allpago_mc/log');
        $gatewayPayment = Mage::getModel('allpago_mc/payment')->load($order->getId(), 'order_id');
        if(!$gatewayPayment->getTries()){
            $qtd_tentativas = $gatewayPayment->getTries()+1;
        }
        $this->qtd_tentativas = Mage::getStoreConfig('allpago/allpago_mc/qtd_tentativas') ? Mage::getStoreConfig('allpago/allpago_mc/qtd_tentativas') : $this->qtd_tentativas;
        if ($qtd_tentativas >= $this->qtd_tentativas) {
            $order->cancel()->save();
            $log->add($order->getId(), '+ Conversao', 'authorize()', 'error', 'Ocorreu um erro na autorização instantânea', $errorMsg);
            $gatewayPayment->setInfo(null);
            $gatewayPayment->setStatus(Mage_Sales_Model_Order::STATE_CANCELED);
            $gatewayPayment->setUpdatedAt(Mage::getModel('core/date')->date("Y-m-d H:i:s"));
        } else {
            $log->add($order->getId(), '+ Conversao', 'authorize()', 'error', 'Ocorreu um erro na autorização instantânea', $errorMsg);
            $gatewayPayment->setTries($gatewayPayment->getTries() + 1);
            $gatewayPayment->setUpdatedAt(Mage::getModel('core/date')->date("Y-m-d H:i:s"));
        }
        $gatewayPayment->save();
    }

    public function failureRedirect($errorMsg) {
        $block = Mage::app()->getLayout()->getMessagesBlock();
        $block->addError('Transação não autorizada  (' . $errorMsg . ')');
        Mage::app()
                ->getResponse()
                ->setRedirect(Mage::getUrl('allpago_gwap/checkout/failure'));
        Mage::app()
                ->getResponse()
                ->sendResponse();
        exit;
    }

    /**
     * Using for multiple shipping address
     *
     * @return bool
     */
    public function canUseForMultishipping() {
        return false;
    }

    /**
     *  check if capture is available
     * 
     * @return bool
     */
    public function canCapture() {
        return true;
    }

    /**
     * Using for multiple shipping address
     *
     */
    public function createFormBlock($name) {
        $block = $this->getLayout()->createBlock($this->_formBlockType, $name)
                ->setMethod('gwap_cc')
                ->setPayment($this->getPayment())
                ->setTemplate('gwap/cc/form.phtml');

        return $block;
    }

    /**
     * Get Frontend name by Code
     *
     * @return frontendNameString or $code if not found
     */
    public function getFrontendName($code) {
        foreach ($this->_isParcelamento["frontend"] as $arCode => $nFrontend)
            if ($code == $arCode)
                return $nFrontend;

        foreach ($this->_isBoleto["frontend"] as $arCode => $nFrontend)
            if ($code == $arCode)
                return $nFrontend;

        foreach ($this->_isDebito["frontend"] as $arCode => $nFrontend)
            if ($code == $arCode)
                return $nFrontend;

        return $code;
    }

    /**
     * Get gwap session namespace
     *
     * @return Allpago_Gwap_Model_Session
     */
    public function getSession() {
        return Mage::getSingleton('gwap/session');
    }

    /**
     * Get checkout session namespace
     *
     * @return Mage_Checkout_Model_Session
     */
    public function getCheckout() {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Get current quote
     *
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote() {
        return $this->getCheckout()->getQuote();
    }

    /**
     * Some process when invoice is created
     * 
     * @param Mage_Sales_Model_Invoice_Payment $payment
     * @return Allpago_Gwap_Model_Methods_Cc 
     */
    public function onInvoiceCreate(Mage_Sales_Model_Invoice_Payment $payment) {
        return $this;
    }

    /**
     * Some process when order is validated
     * 
     * @param Mage_Sales_Model_Invoice_Payment $payment
     * @return Allpago_Gwap_Model_Methods_Cc 
     */
    public function onOrderValidate(Mage_Sales_Model_Order_Payment $payment) {
        return $this;
    }

    /**
     *  Regex validations
     * 
     * @return string 
     */
    public function getVerificationRegEx() {
        $verificationExpList = array(
            'VISA' => '/^[0-9]{3}$/',
            'MASTER' => '/^[0-9]{3}$/',
            'ELO' => '/^[0-9]{3,4}$/',
            'AMEX' => '/^[0-9]{4}$/',
            'DISCOVER' => '/^[0-9]{3,4}$/',
            'DINERS' => '/^[0-9]{3,4}$/'
        );
        return $verificationExpList;
    }

    public function OtherCcType($type) {
        return $type == 'ELO';
    }

    /*
     * Validate
     */

    public function validate() {
        /*
         * calling parent validate function
         */
        $info = $this->getInfoInstance();
        
        if ($info instanceof Mage_Sales_Model_Order_Payment) {
            $billingCountry = $info->getOrder()->getBillingAddress()->getCountryId();
        } else {
            $billingCountry = $info->getQuote()->getBillingAddress()->getCountryId();
        }
        if (!$this->canUseForCountry($billingCountry)) {
            Mage::throwException($this->_getHelper()->__('Selected payment type is not allowed for billing country.'));
        }

        $errorMsg = false;
        $availableTypes = explode(',', $this->getConfigData('cctypes'));

        $ccNumber = $info->getCcNumber();

        // remove credit card number delimiters such as "-" and space
        $ccNumber = preg_replace('/[\-\s]+/', '', $ccNumber);
        $info->setCcNumber($ccNumber);

        $ccType = '';
        if (in_array($info->getCcType(), $availableTypes)) {
            if ($this->validateCcNum($ccNumber)
                    // Other credit card type number validation
                    || ($this->OtherCcType($info->getCcType()) && $this->validateCcNumOther($ccNumber))) {

                $ccType = 'ELO';
                $ccTypeRegExpList = array(
                    'VISA' => '/^4[0-9]{12}([0-9]{3})?$/',
                    'MASTER' => '/^5[1-5][0-9]{14}$/',
                    'DINERS' => '/^3[0,6,8][0-9]{12}/',
                    'AMEX' => '/^3[47][0-9]{13}$/',
                    'DISCOVER' => '/^6011[0-9]{4}[0-9]{4}[0-9]{4}$/'
                );

                foreach ($ccTypeRegExpList as $ccTypeMatch => $ccTypeRegExp) {
                    if (preg_match($ccTypeRegExp, $ccNumber)) {
                        $ccType = $ccTypeMatch;
                        break;
                    }
                }

                if (!$this->OtherCcType($info->getCcType()) && $ccType != $info->getCcType()) {
                    $errorMsg = $this->_getHelper()->__('Credit card number mismatch with credit card type.');
                }
            } else {
                $errorMsg = $this->_getHelper()->__('Invalid Credit Card Number');
            }
        } else {
            $errorMsg = $this->_getHelper()->__('Credit card type is not allowed for this payment method.');
        }

        //validate credit card verification number
        if ($errorMsg === false && $this->hasVerification()) {
            $verifcationRegEx = $this->getVerificationRegEx();
            $regExp = isset($verifcationRegEx[$info->getCcType()]) ? $verifcationRegEx[$info->getCcType()] : '';
            if (!$info->getCcCid() || !$regExp || !preg_match($regExp, $info->getCcCid())) {
                $errorMsg = $this->_getHelper()->__('Please enter a valid credit card verification number.');
            }
        }

        if ($ccType != 'SS' && !$this->_validateExpDate($info->getCcExpYear(), $info->getCcExpMonth())) {
            $errorMsg = $this->_getHelper()->__('Incorrect credit card expiration date.');
        }

        if ($errorMsg) {
            Mage::throwException($errorMsg);
            //throw Mage::exception('Mage_Payment', $errorMsg, $errorCode);
        }

        //This must be after all validation conditions
        if ($this->getIsCentinelValidationEnabled()) {
            $this->getCentinelValidator()->validate($this->getCentinelValidationData());
        }
    }

    private function makeCurlRequest($url, $postString) {
        $cpt = curl_init();
        curl_setopt($cpt, CURLOPT_URL, $url);
        curl_setopt($cpt, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($cpt, CURLOPT_USERAGENT, "php ctpepost");
        curl_setopt($cpt, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($cpt, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($cpt, CURLOPT_POST, 1);
        curl_setopt($cpt, CURLOPT_POSTFIELDS, $postString);
        curl_setopt($cpt, CURLOPT_HTTPHEADER, array("Content-Type: application/x-www-form-urlencoded;charset=UTF-8"));

        $curlresultURL = '';
        $curlresultURL = curl_exec($cpt);
        $curlerror = curl_error($cpt);
        $curlinfo = curl_getinfo($cpt);
        curl_close($cpt);

        $r_arr = explode("&", $curlresultURL);
        foreach ($r_arr as $buf) {
            $temp = urldecode($buf);
            $temp = explode("=", $temp, 2);
            if ($temp[0] && $temp[1]) {
                $postatt = $temp[0];
                $postvar = $temp[1];
                $returnvalue[$postatt] = $postvar;
            }
        }
        $this->_resultCode = '';
        $resultCode = explode('.', $returnvalue['PROCESSING.CODE']);
        $this->_resultCode = $resultCode[2];

        return $returnvalue;
    }

    private function buildPostString($parameters) {
        $result = '';
        foreach (array_keys($parameters) AS $key) {
            if (!isset($$key)) {
                $$key = '';
            }
            if (!isset($result)) {
                $result = '';
            }
            $$key .= $parameters[$key];
            $$key = urlencode($$key);
            $$key .= "&";
            if (!stristr($key, 'cpf') && !stristr($key, 'number_of_installments')) {
                $var = strtoupper($key);
            } else {
                $var = $key;
            }
            $value = $$key;
            $result .= "$var=$value";
        }
        return stripslashes($result);
    }

    private function getRequestURL() {
        $auth = $this->getAuthConfig();
        if ($auth->getAmbiente() == 'LIVE') {
            return 'https://ctpe.net/frontend/payment.prc';
        } else { //'CONNECTOR_TEST'
            return 'https://test.ctpe.net/frontend/payment.prc';
        }
    }

    private function getRegistrationInfo($customerId) {
        $oneclick = Mage::getModel('gwap/oneclick')->load($customerId, 'customer_id');
        return $oneclick->getRegistrationInfo();
    }

    private function newRegistrationInfo($customerId, $gwapOrder) {
        $response = $this->fetchCustomerRegistration($gwapOrder);

        if ($this->_resultCode != '90') {
            $gwapOrder->setRegistrationCc(null);
            $gwapOrder->save();
            Mage::throwException('Payment code: ' . $response['PAYMENT.CODE'] . ' (' . $response['PROCESSING.REASON'] . ' - ' . $response['PROCESSING.RETURN'] . ')');
        }

        //Cria registro na tabela oneclick
        $newRegistry = Mage::getModel('gwap/oneclick');
        $newRegistry->setCustomerId($customerId);
        $newRegistry->setRegistrationInfo($response['IDENTIFICATION.UNIQUEID']);
        $newRegistry->setCreatedAt(Mage::getModel('core/date')->date("Y-m-d H:i:s"));
        $newRegistry->save();

        return $newRegistry->getRegistrationInfo();
    }

    // Criar/atualizar o registro
    private function fetchCustomerRegistration($gwapOrder, $update = null) {
        $orderId = $gwapOrder->getOrderId();
        $order = Mage::getModel('sales/order')->load($orderId);
        $data = new Varien_Object(unserialize(Mage::helper('core')->decrypt($gwapOrder->getRegistrationCc())));
        $url = $this->getRequestURL();
        if ($update) {
            $parameters = $this->prepareUpdateRegistrationRequestParameters($order, $data, $update);
        } else {
            $parameters = $this->prepareRegistrationRequestParameters($order, $data);
        }

        $postString = $this->buildPostString($parameters);
        $response = $this->makeCurlRequest($url, $postString);
        return $response;
    }

    private function prepareRegistrationRequestParameters($order, $data) {
        $auth = $this->getAuthConfig();
        $config = $this->getConfig();
        $parameters = array();
        $parameters['IDENTIFICATION.TRANSACTIONID'] = 'Customer id ' . $order->getCustomerId();
        $parameters = array_merge($parameters, $this->prepareCommonParameters());
        //Dados da compra
        $parameters = array_merge($parameters, $this->prepareAccountParameters($data));
        $parameters = array_merge($parameters, $this->prepareAddressParameters($order));
        $parameters['PAYMENT.CODE'] = 'CC.RG';
        $parameters['CONTACT.EMAIL'] = trim($order->getShippingAddress()->getEmail()) ? trim(utf8_decode($order->getShippingAddress()->getEmail())) : trim(utf8_decode($order->getCustomerEmail()));
        $parameters['NAME.GIVEN'] = utf8_decode($order->getShippingAddress()->getFirstname());
        $parameters['NAME.FAMILY'] = utf8_decode($order->getShippingAddress()->getLastname());

        return $parameters;
    }

    private function prepareUpdateRegistrationRequestParameters($order, $data, $registrationId) {
        $auth = $this->getAuthConfig();
        $config = $this->getConfig();
        $parameters = array();

        $parameters['IDENTIFICATION.TRANSACTIONID'] = 'Customer id ' . $order->getCustomerId();
        $parameters['IDENTIFICATION.REFERENCEID'] = $registrationId;
        $parameters = array_merge($parameters, $this->prepareCommonParameters());
        //Dados da compra
        $parameters = array_merge($parameters, $this->prepareAccountParameters($data));
        $parameters = array_merge($parameters, $this->prepareAddressParameters($order));
        $parameters['PAYMENT.CODE'] = 'CC.RR';
        $parameters['CONTACT.EMAIL'] = trim($order->getShippingAddress()->getEmail()) ? trim(utf8_decode($order->getShippingAddress()->getEmail())) : trim(utf8_decode($order->getCustomerEmail()));
        $parameters['NAME.GIVEN'] = utf8_decode($order->getShippingAddress()->getFirstname());
        $parameters['NAME.FAMILY'] = utf8_decode($order->getShippingAddress()->getLastname());

        return $parameters;
    }

    private function prepareCommonParameters() {
        $auth = $this->getAuthConfig();
        $config = $this->getConfig();
        $parameters = array();
        $parameters['RESPONSE.VERSION'] = '1.0';
        $parameters['TRANSACTION.MODE'] = $auth->getAmbiente();
        $parameters['TRANSACTION.RESPONSE'] = 'SYNC';
        $parameters['SECURITY.SENDER'] = $this->getSecuritySender($auth);
        $parameters['TRANSACTION.CHANNEL'] = $this->getTransactionChannel($config);
        $parameters['USER.LOGIN'] = $this->getUserLogin($auth);
        $parameters['USER.PWD'] = $this->getUserPassword($auth);
        return $parameters;
    }

    private function prepareAccountParameters($data) {
        $parameters['ACCOUNT.HOLDER'] = $data->getCcOwner();
        $parameters['ACCOUNT.NUMBER'] = $data->getCcNumber();
        $parameters['ACCOUNT.BRAND'] = $data->getCcType();
        $parameters['ACCOUNT.EXPIRY_MONTH'] = $data->getCcExpMonth();
        $parameters['ACCOUNT.EXPIRY_YEAR'] = $data->getCcExpYear();
        $parameters['ACCOUNT.VERIFICATION'] = $data->getCcCid();
        return $parameters;
    }

    private function prepareAddressParameters($order) {
        $parameters = array();
        $street = utf8_decode($order->getShippingAddress()->getStreet(1));
        if (strlen($street) < 5) {
            $street = 'Rua ' . utf8_decode($order->getShippingAddress()->getStreet(1));
        }
        $parameters['ADDRESS.STREET'] = $street;
        $parameters['ADDRESS.ZIP'] = str_replace('-', '', utf8_decode($order->getShippingAddress()->getPostcode()));
        $parameters['ADDRESS.CITY'] = utf8_decode($order->getShippingAddress()->getCity());
        $parameters['ADDRESS.COUNTRY'] = utf8_decode($order->getShippingAddress()->getCountryId());
        $parameters['ADDRESS.STATE'] = $order->getShippingAddress()->getRegion() ? Mage::getModel('directory/region')->load($order->getShippingAddress()->getRegionId())->getCode() : $order->getShippingAddress()->getRegion();
        return $parameters;
    }

    private function prepareAuthenticationRequestParameters($order, $data) {
        $auth = $this->getAuthConfig();
        $config = $this->getConfig();
        $parameters = array();
        $parameters['IDENTIFICATION.TRANSACTIONID'] = $order->getIncrementId();
        $parameters = array_merge($parameters, $this->prepareCommonParameters());
        $parameters = array_merge($parameters, $this->prepareAccountParameters($data));
        $parameters = array_merge($parameters, $this->prepareAddressParameters($order));
        $parameters = array_merge($parameters, $this->preparePresentationParameters(number_format($order->getGrandTotal(), 2, '.', '')));
        if ($data->getCcParcelas() > 1) {
            $parameters['CRITERION.CUSTOM_number_of_installments'] = $data->getCcParcelas();
        }
        $parameters['PAYMENT.CODE'] = $this->getAcao($order) ? 'CC.DB' : 'CC.PA';
        $parameters['CONTACT.EMAIL'] = trim($order->getShippingAddress()->getEmail()) ? trim(utf8_decode($order->getShippingAddress()->getEmail())) : trim(utf8_decode($order->getCustomerEmail()));
        $parameters['NAME.GIVEN'] = utf8_decode($order->getShippingAddress()->getFirstname());
        $parameters['NAME.FAMILY'] = utf8_decode($order->getShippingAddress()->getLastname());
        return $parameters;
    }

    private function preparePresentationParameters($amount) {
        $parameters = array();
        $parameters['PRESENTATION.CURRENCY'] = "BRL";
        $parameters['PRESENTATION.AMOUNT'] = $amount;
        return $parameters;
    }

    /**
     * Não fazer pre-autorização quando:
     * Pré-autorização estiver desabilitada
     * Oneclick ativado
     * ou Fcontrol ativado e valor do pedido menor que a configuracao vlr_minimo no Fcontrol.
     */
    public function getAcao($order) {
        return $this->getConfig()->getAcao() || (Mage::getStoreConfig('allpago/fcontrol/active') && Mage::getStoreConfig('allpago/fcontrol/vlr_minimo') > $order->getGrandTotal());
    }

    private function prepareCaptureRequestParameters($authorizationResponse) {
        $r = $authorizationResponse;
        $parameters = array();
        $parameters = $this->prepareCommonParameters();
        $parameters = array_merge($parameters, $this->preparePresentationParameters($r['PRESENTATION.AMOUNT']));
        $parameters['IDENTIFICATION.REFERENCEID'] = $r['IDENTIFICATION.UNIQUEID'];
        $parameters['IDENTIFICATION.TRANSACTIONID'] = $r['IDENTIFICATION.TRANSACTIONID'];
        $parameters['PAYMENT.CODE'] = "CC.CP";
        return $parameters;
    }

    private function getSecuritySender($config) {
        return trim($config->getSecuritySender());
    }

    private function getTransactionChannel($config) {
        $channel = 'transaction_channel_' . $config->getChannel();
        return trim($config->getData($channel));
    }

    private function getUserLogin($config) {
        return trim($config->getUserLogin());
    }

    private function getUserPassword($config) {
        return strval(Mage::helper("core")->decrypt($config->getUserPwd()));
    }

}
