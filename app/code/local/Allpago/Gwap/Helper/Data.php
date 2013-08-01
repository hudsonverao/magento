<?php
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
class Allpago_Gwap_Helper_Data extends Mage_Core_Helper_Abstract
{
    private $_order;
    private $_config;
    private $_auth;
    
    public function isOnepageActive() {
        return Mage::getStoreConfig('onepagecheckout/general/enabled');
    }
    
    public  function setOrder($order){
        $this->_order = $order;
        return $this;
    } 
    
    public function getOrder(){
        return $this->_order;
    }
    
    public function getConfig() {
        if( $this->_config )
               return $this->_config;
          
        $this->_config = new Varien_Object(Mage::getStoreConfig('payment/gwap_boleto' ));
        return $this->_config;
    }
    public function getAuthConfig() {
        if( $this->_auth )
               return $this->_auth;
       
        $this->_auth = new Varien_Object(Mage::getStoreConfig('payment/gwap_auth'));
        return $this->_auth;
    }
    
    public function prepareData( $type ){
        
        $order = $this->getOrder();
        $config = $this->getConfig();
        $auth = $this->getAuthConfig();
        
        $parameters = array();
        
         //prepare parameters
        $parameters['RESPONSE.VERSION'] = '1.0';
        $parameters['TRANSACTION.MODE'] = $auth->getAmbiente(); #####PEGAR AMBIENTE######
        $parameters['TRANSACTION.RESPONSE'] = 'SYNC';
        $parameters['SECURITY.SENDER'] = trim($auth->getSecuritySender());
       
        $transaction_type = 'transaction_channel_'.strtolower($type);

        $parameters['TRANSACTION.CHANNEL'] = trim($config->getData($transaction_type)); #####PEGAR CANAL######
        $parameters['USER.LOGIN'] = trim($auth->getUserLogin());
        $parameters['USER.PWD'] = strval(Mage::helper("core")->decrypt($auth->getUserPwd()));
        
        $parameters['IDENTIFICATION.TRANSACTIONID'] = $order->getIncrementId();
         
        $parameters['PAYMENT.CODE'] = 'PP.PA';
        $parameters['PRESENTATION.AMOUNT'] = number_format($order->getGrandTotal(), 2, '.', '');
        $parameters['PRESENTATION.CURRENCY'] = "BRL";
        
        $street = utf8_decode($order->getShippingAddress()->getStreet(1));
        if (strlen($street) < 5) {
            $street = 'Rua ' . utf8_decode($order->getShippingAddress()->getStreet(1));
        }

        $parameters['ADDRESS.STREET'] = $street;
        $parameters['ADDRESS.ZIP'] = str_replace('-', '', utf8_decode($order->getShippingAddress()->getPostcode()));
        
        $city = $order->getShippingAddress()->getCity();    
        
        $parameters['ADDRESS.CITY'] = utf8_decode($city);
        $parameters['ADDRESS.COUNTRY'] = utf8_decode($order->getShippingAddress()->getCountryId());
        $parameters['ADDRESS.STATE'] = $order->getShippingAddress()->getRegionId()  
                                            ? Mage::getModel('directory/region')->load( $order->getShippingAddress()->getRegionId() )->getCode()
                                            :  $order->getShippingAddress()->getRegion();
                                     
        $parameters['CONTACT.EMAIL'] = trim($order->getShippingAddress()->getEmail())
                                       ? trim(utf8_decode($order->getShippingAddress()->getEmail())) : trim(utf8_decode($order->getCustomerEmail()));

        $parameters['NAME.GIVEN'] = utf8_decode($order->getShippingAddress()->getFirstname());
        $parameters['NAME.FAMILY'] = utf8_decode($order->getShippingAddress()->getLastname());
        
        $vencimento = $config->getVencimento();
        if( is_numeric($vencimento) && $vencimento > 0 ){
            $due_date = Mage::getModel('core/date')->timestamp( '+'.$vencimento.' days' );
        }else{
            $due_date = Mage::getModel('core/date')->timestamp( '+1 day' );
        }
        
        switch ($type){
            
            case 'BRADESCO':
                 
                if( is_numeric($vencimento) && $vencimento > 3 ){
                    $due_date = Mage::getModel('core/date')->timestamp( '+3 days' );
                }
                if( $config->getInstrucoes() ){
                    
                    $instrucoes = explode( PHP_EOL, $config->getInstrucoes() ); 
                    foreach ( $instrucoes as $key => $inst){
                        $parameters['CRITERION.BRADESCO_instrucao'.($key+1)]  = $inst;
                    }
                }

                $parameters['CRITERION.BRADESCO_numeropedido']  = $order->getIncrementId();
                $parameters['CRITERION.BRADESCO_datavencimento']  = date( 'd/m/Y', $due_date );
                $parameters['CRITERION.BRADESCO_cpfsacado']  = (string) str_replace(array('.','-',' '), array('', '', ''), $order->getCustomerTaxvat());
                
                break;
            
            case 'ITAU':

                $parameters['CRITERION.BOLETO_Due_date']  =  date( 'dmY', $due_date );
                $parameters['CRITERION.BOLETO_Codeenrollment']  = '01'; 
                $parameters['CRITERION.BOLETO_Numberenrollment']  = (string) str_pad( str_replace(array('.','-',' '), array('', '', ''), $order->getCustomerTaxvat()), 14, "0", STR_PAD_LEFT);
                $parameters['CRITERION.BOLETO_BairroSacado']  = $order->getShippingAddress()->getStreet(4);
                
                break;
        }

        return $parameters;        
    }
    
    public function getBoletoUrl( $orderId ){
        $order =  Mage::getModel('sales/order')->loadByIncrementId($orderId);
        
        $store = Mage::getModel('core/store')->load($order->getStoreId());
        return $store->getUrl('allpago_gwap/imprimir/boleto', array('id'=>$order->getId(), 'ci'=>$order->getCustomerId()));
    }
}