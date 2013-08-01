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
class Allpago_Gwap_Model_Methods_Oneclick extends Mage_Payment_Model_Method_Abstract {

    const PAYMENT_TYPE_AUTH = 'AUTHORIZATION';
    const PAYMENT_TYPE_SALE = 'SALE';

    protected $_code = 'gwap_oneclick';
    protected $_formBlockType = 'gwap/form_oneclick';
    protected $_infoBlockType = 'gwap/info_oneclick';
    protected $_allowCurrencyCode = array('BRL', 'USD');
    protected $_canSaveCc = false;
    protected $_resultCode = '';
    protected $_cc = '';    
    protected $_rg = '';     
    private $qtd_tentativas = 1;

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
        $customerId = $order->getCustomerId();
        $orderId = $order->getId();
        $gwap = Mage::getModel('gwap/order')->load($orderId, 'order_id');
        if($this->_cc){
            $cc = new Varien_Object($this->_cc);
        }else{
            $cc = new Varien_Object(unserialize(Mage::helper('core')->decrypt($gwap->getInfo())));
        }
        
        $registrationInfo = null;
        $registrationInfo = $this->getRegistrationInfo($customerId);
        
        $parameters = $this->prepareAuthenticationRequestParameters($order, $cc, $registrationInfo);

        $url = $this->getRequestURL();

        $postString = $this->buildPostString($parameters);
        $response = $this->makeCurlRequest($url, $postString);

        // Se diferente de 90(success code) e for Autorização instantânea
        if ($this->_resultCode != '90') {
            Mage::throwException('Payment code: ' . $response['PAYMENT.CODE'] . ' (' . $response['PROCESSING.REASON'] . ' - ' . $response['PROCESSING.RETURN'] . ')');
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
        $orderId = $payment->getOrder()->getId();
        $gwap = Mage::getModel('gwap/order')->load($orderId, 'order_id');

        if ($gwap->getStatus() == Allpago_Mc_Model_Mc::STATUS_CAPTUREPAYMENT) {
            $gwap->setStatus(Allpago_Mc_Model_Mc::STATUS_CAPTURED);
            $gwap->setErrorCode(null);
            $gwap->setErrorMessage(null);
            $gwap->setInfo(null);
            $gwap->setTries(0);
            $gwap->setAbandoned(0);
            $gwap->save();
            $log = Mage::getModel('allpago_mc/log');
            $log->add($gwap->getOrderId(), 'Payment', 'capture()', Allpago_Mc_Model_Mc::STATUS_CAPTURED, 'Pagamento capturado');
        }
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
            $gatewayPayment->setStatus(Allpago_Mc_Model_Mc::STATUS_CAPTUREPAYMENT);
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
                }
            } else {
                $block = Mage::app()->getLayout()->getMessagesBlock();
                $block->addSuccess('Transação autorizada com sucesso');
            }
        } catch (Exception $e) {
            $this->cancelOrder($order, $e->getMessage());
            $this->failureRedirect($e->getMessage());
        }
    }

    public function cancelOrder($order, $errorMsg) {
        $log = Mage::getModel('allpago_mc/log');
        $gatewayPayment = Mage::getModel('allpago_mc/payment')->load($order->getId(), 'order_id');
        if (!$gatewayPayment->getTries()) {
            $qtd_tentativas = $gatewayPayment->getTries() + 1;
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

        $this->_resultCode = null;
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
        if ($oneclick->getRegistrationInfo()) {
            return $oneclick->getRegistrationInfo();
        }
        $message = "Erro oneclick: registro do cliente não encontrado.";
        Mage::throwException(Mage::helper('gwap')->__($message));
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

    private function prepareAccountParameters($registrationInfo) {
        $parameters = array();
        $parameters['ACCOUNT.REGISTRATION'] = $registrationInfo;
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

    private function prepareAuthenticationRequestParameters($order, $parcelas, $registrationInfo) {
        $auth = $this->getAuthConfig();
        $config = $this->getConfig();
        $parameters = array();
        $parameters['IDENTIFICATION.TRANSACTIONID'] = $order->getIncrementId();
        $parameters = array_merge($parameters, $this->prepareCommonParameters());
        $parameters = array_merge($parameters, $this->prepareAccountParameters($registrationInfo));
        $parameters = array_merge($parameters, $this->prepareAddressParameters($order));
        $parameters = array_merge($parameters, $this->preparePresentationParameters(number_format($order->getGrandTotal(), 2, '.', '')));
        if ($parcelas->getCcParcelas() > 1) {
            $parameters['CRITERION.CUSTOM_number_of_installments'] = $parcelas->getCcParcelas();
        }
        $parameters['PAYMENT.CODE'] = 'CC.DB';
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
     * nao enfileirar na Fcontrol quando valor minimo menor que a configuracao vlr_minimo
     */
    private function getAcao($order) {
        return $this->getConfig()->getAcao() || Mage::getStoreConfig('allpago/fcontrol/vlr_minimo') > $order->getGrandTotal();
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
        $info->setCcParcelas($data->getGwapOneclickCcParcelas());

        return $this;
    }

    public function isAvailable($quote = null) {
        if (is_null($quote)) {
            return false;
        }
        if (!Mage::getStoreConfig('payment/gwap_oneclick/active')) {
            return false;
        }


        $customerId = $quote->getCustomerId();
        $oneclick = Mage::getModel('gwap/oneclick')->load($customerId, 'customer_id');
        if ($oneclick->getRegistrationInfo()) {
            return true;
        }

        return false;
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
     * Using internal pages for input payment data
     *
     * @return bool
     */
    public function canUseInternal() {
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
                ->setMethod('gwap_oneclick')
                ->setPayment($this->getPayment())
                ->setTemplate('gwap/oneclick/form.phtml');

        return $block;
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
     * @return Allpago_Gwap_Model_Methods_boleto
     */
    public function onInvoiceCreate(Mage_Sales_Model_Invoice_Payment $payment) {
        return $this;
    }

    /**
     * Some process when order is validated
     * 
     * @param Mage_Sales_Model_Invoice_Payment $payment
     * @return Allpago_Gwap_Model_Methods_boleto
     */
    public function onOrderValidate(Mage_Sales_Model_Order_Payment $payment) {
        return $this;
    }

    /*
     * Validate
     */

    public function validate() {
        parent::validate();
    }

}
