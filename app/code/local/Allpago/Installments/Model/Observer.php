<?php

class Allpago_Installments_Model_Observer {
    const ALLPAGO_GWAP_CC_BLOCK = 'Allpago_Gwap_Block_Form_Cc';
    const ALLPAGO_GWAP_ONECLICK_BLOCK = 'Allpago_Gwap_Block_Form_Oneclick';
    const MAGE_PRODUCT_PRICE = 'Mage_Catalog_Block_Product_Price';
    const CATEGORY_CONTROLLER = 'category';
    const PRODUCT_CONTROLLER = 'product';
    const INSTALLMENT_CODE = 'cc_parcelas';

    private $_installmentModel;

    /**
     * add installments block on form
     */
    public function installmentsPaymentBlock($observer) {
        if (!$this->isActive()) {
            return false;
        }

        if (self::ALLPAGO_GWAP_ONECLICK_BLOCK != get_class($observer->getBlock())
              && self::ALLPAGO_GWAP_CC_BLOCK != get_class($observer->getBlock()) ){
            return $this;
        }        
        
        
        if( !Mage::getStoreConfig('allpago/installments/active_form') )  {
            return $this;
        }
        
        $block = $observer->getBlock()->getLayout()->createBlock('installments/form');

        $hasOnestep = Mage::getStoreConfig('onestepcheckout/general/rewrite_checkout_links');
        
        if ($hasOnestep) {            
            $checkout = Mage::getSingleton('checkout/session');           
            
            $checkout->getQuote()->collectTotals();
            $checkout->getQuote()->setTotalsCollectedFlag(false);
            
        }
        $html = str_replace('</ul>', '', $observer->getTransport()->getHtml());

        $observer->getTransport()->setHtml($html
                . $block
                        ->setPaymentFormBlock($observer->getBlock())
                        ->setPaymentInstallmentCode(self::INSTALLMENT_CODE)
                        ->setPaymentCode($observer->getBlock()->getMethodCode())
                        ->toHtml() . '</ul>'
        );

        return $this;
    }

    /**
     * add installments block on views
     */
    public function installmentsProductPrice($observer) {

        if (!$this->isActive()) {
            return false;
        }
        if (self::MAGE_PRODUCT_PRICE != get_class($observer->getBlock())) {
            return $this;
        }

        $controller_name = Mage::app()->getRequest()->getControllerName();
        if ($controller_name == 'result') {
            $controller_name = self::CATEGORY_CONTROLLER;
        }

        if (Mage::registry('has_' . $controller_name)) {
            return $this;
        }
        if (self::PRODUCT_CONTROLLER == $controller_name) {
            Mage::register('has_' . $controller_name, true);
        }

        $render_block = $this->viewRender($controller_name);
        $product_price = $observer->getBlock()->getProduct()->getFinalPrice();
        
        if (!$render_block || !$product_price) {
            return $this;
        }

        $html = $observer->getTransport()->getHtml();
 
        $installments = $observer->getBlock()->getLayout()->createBlock('installments/' . $render_block);
        $installments_html = $installments->setValue($product_price);

        //if ($parcelaMax = $observer->getBlock()->getProduct()->getParcelaMaxima()) {
        //    $installments->setMaxInstallment($parcelaMax);
        //    $installments->setHasInstallment(true);
        //    $installments->setTaxaJuros(0);
        //}

        $installments_html = $installments->toHtml();
            
        $observer->getTransport()->setHtml($html . $installments_html);

        return $this;
    }

    /**
     * clear installments values from session after save shipping over onepage checkout
     */
    public function installmentsCheckoutSaveShipping($observer) {
        if (!$this->isActive()) {
            return $this;
        }
        $this->clearInfo();
    }

    public function installmentsCheckoutClearPayment($observer){
        if(!in_array(Mage::getSingleton('checkout/session')->getQuote()->getPayment()->getMethod(),array('gwap_cc','gwap_oneclick'))){
            return $this;
        }
        Mage::getSingleton('checkout/session')->getQuote()->getPayment()->setCcParcelas('');        
        return $this;
    }

    /**
     * calculate installments for gwap_cc or gwap_oneclick method over onestepcheckout
     */
    public function installmentsCheckout($observer) {
                      
        if (!$this->isActive()) {
            return $this;
        }
        
        $controller = $observer->getControllerAction();
        $checkout = Mage::getSingleton('checkout/session');
          
        $params = $controller->getRequest()->getParams();
      
        if ( !isset( $params['payment'] ) || ( $params['payment'] && ( !isset($params['payment']['gwap_cc_cc_parcelas']) || $params['payment']['method'] != 'gwap_cc' ) )
                && ( $params['payment'] && ( !isset($params['payment']['gwap_oneclick_cc_parcelas']) || $params['payment']['method'] != 'gwap_oneclick' ) )) {
            if(Mage::helper('gwap')->isOnepageActive()){
                $this->clearInfo();           
            }
            return $this;
        } 

        $paymentMethod = $checkout->getQuote()->getPayment()->getMethod();
        
        if(Mage::helper('gwap')->isOnepageActive()){ 
            Mage::log('metodo: '.$paymentMethod);
            if( ($paymentMethod == 'gwap_cc' 
                    || $paymentMethod == 'gwap_oneclick') && Mage::getModel('core/session')->getGwapAnterior() == 'gwap_boleto' ){
                $this->clearInfo();
                $checkout->getQuote()->setTotalsCollectedFlag(false);            
                $checkout->getQuote()->collectTotals();          
            }
        } else {
            if( $paymentMethod == 'gwap_cc' 
                    || $paymentMethod == 'gwap_oneclick' ){
                $this->clearInfo();
                $checkout->getQuote()->collectTotals();
                $checkout->getQuote()->setTotalsCollectedFlag(false);                            
            }            
        }
            
        Mage::getModel('core/session')->setGwapAnterior($paymentMethod);
        
        if($paymentMethod == 'gwap_cc'){
            $installment = $this->getInstallmentsModel()->getInstallmentByItem($params['payment']['gwap_cc_cc_parcelas']);
        } elseif ($paymentMethod == 'gwap_oneclick') {
            $installment = $this->getInstallmentsModel()->getInstallmentByItem($params['payment']['gwap_oneclick_cc_parcelas']);        
        }

        // Não calcular juros se existir parcela máxima por produto
        //if (!$this->getParcelaMaxima()) {
        if(isset($installment)){
            $checkout->setJuros(( $installment->getInstallmentValue() * $installment->getValue() ) - $checkout->getBaseTotal());
        }

    }

    
    /**
     * return installment model instance
     * 
     * @return type 
     */
    public function getInstallmentsModel() {
        if (!$this->_installmentModel) {
            $this->_installmentModel = Mage::helper('installments')->getInstallmentModel();
        }
        return $this->_installmentModel;
    }

    /**
     * check if module is active on admin
     * @return int
     */
    public function isActive() {
        return Mage::getStoreConfig('allpago/installments/active');
    }

    /**
     * returns a controller to render
     * 
     * @param string $controller_name
     * @return string 
     */
    public function viewRender($controller_name) {
        return Mage::getStoreConfig('allpago/installments/active_' . $controller_name);
    }

    /**
     * clear installment information from session
     * 
     */
    public function clearInfo() {
        $checkout = Mage::getSingleton('checkout/session');

        $checkout->setJuros(0);
        $checkout->setBaseTotal(0);
    }
 
    /**
     * Get custom installments from session
     * 
     */    
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