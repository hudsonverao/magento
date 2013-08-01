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
class Allpago_Gwap_Block_Form_Cc extends Mage_Payment_Block_Form_Cc {

    protected function _construct() {
        parent::_construct();
        $this->setTemplate('allpago_gwap/form/cc.phtml');
    }
    
    /**
     * Retrieve availables credit card types
     *
     * @return array
     */
    public function getCcAvailableTypes()
    {
        $_types = Mage::getModel('gwap/source_methods')->toOptionArray();
        
        $types = array();
        foreach ($_types as $data) {
            if (isset($data['label']) && isset($data['value'])) {
                $types[$data['value']] = $data['label'];
            }
        }
        
        if ($method = $this->getMethod()) {
            $availableTypes = $method->getConfigData('cctypes');
            
            if ($availableTypes) {
                $availableTypes = explode(',', $availableTypes);
                foreach ($types as $code=>$name) {
                    if (!in_array($code, $availableTypes)) {
                        unset($types[$code]);
                    }
                }
            }
        }
        return $types;
    }

    public function getParcelaMaxima(){
        $_product = null;
        $parcela_maxima = 0;
        $items = Mage::getSingleton('checkout/session')->getQuote()->getAllItems();
        foreach($items as $item){

            $_product = Mage::getModel('catalog/product')->load($item->getProductId());                
            
            if ($parcela_maxima < $_product->getParcelaMaxima()){
                $parcela_maxima = $_product->getParcelaMaxima();
            }
          
        }
        return $parcela_maxima;
    }  
    
}