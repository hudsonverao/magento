<?php

class Allpago_Gwap_Model_Observer {

    /**
     * Set forced canCreditmemo flag
     *
     * @param Varien_Event_Observer $observer
     * @return Mage_Payment_Model_Observer
     */
    public function salesOrderSave($observer) {
        $orderId = current($observer->getOrderIds());
        $order = Mage::getModel('sales/order')->load($orderId);

        $payment = $order->getPayment();

        if (!Mage::getStoreConfig('payment/gwap_cc/active') 
                || !Mage::getStoreConfig('payment/gwap_cc/mc_active') 
                || !in_array($payment->getMethod(), array('gwap_cc', 'gwap_oneclick', 'gwap_boleto'))) {
            return $this;
        }

        $data = new Varien_Object();

        if ($payment->getCcType())
            $data->setCcType($payment->getCcType());
        if ($payment->getCcOwner())
            $data->setCcOwner($payment->getCcOwner());
        if ($payment->getCcLast4())
            $data->setCcLast4($payment->getCcLast4());
        if(Mage::getModel('core/session')->getGwapCcCcNumber())    
            $data->setCcNumber(Mage::getModel('core/session')->getGwapCcCcNumber());        
        if ($payment->getCcParcelas())
            $data->setCcParcelas($payment->getCcParcelas());
        if (Mage::getModel('core/session')->getGwapCcId())
            $data->setCcCid(Mage::getModel('core/session')->getGwapCcId());
        if ($payment->getCcExpMonth())
            $data->setCcExpMonth($payment->getCcExpMonth());
        if ($payment->getCcExpYear())
            $data->setCcExpYear($payment->getCcExpYear());
        if ($payment->getGwapBoletoType())
            $data->setGwapBoletoType($payment->getGwapBoletoType());
        
        Mage::getModel('core/session')->setGwapCcId();
        Mage::getModel('core/session')->setGwapCcCcNumber();
        
        $mGwap = Mage::getModel('gwap/order');
        $mGwap->setStatus(Allpago_Gwap_Model_Order::STATUS_CREATED);
        $mGwap->setCreatedAt(Mage::getModel('core/date')->date("Y-m-d H:i:s"));
        // Não salvar dados do CC
        if (!Mage::getStoreConfig('payment/gwap_cc/tipo_autorizacao') && $payment->getMethod() != "gwap_boleto") {
            $mGwap->setInfo(Mage::helper('core')->encrypt(serialize($data->toArray())));
        }
        $mGwap->setType(Mage::getStoreConfig('payment/' . $payment->getMethod() . '/mc_type'));
        $mGwap->setOrderId($order->getId());
        $mGwap->save();

        if (Mage::getStoreConfig('payment/gwap_cc/tipo_autorizacao')) {
            if ($payment->getMethod() != 'gwap_boleto') {
                $order->getPayment()->getMethodInstance()->authorizeNow($order,$data->toArray());
            }
        }

        if ($payment->getMethod() == "gwap_boleto") {
            $order->getPayment()->getMethodInstance()->authorize($order->getPayment(), $order->getGrandTotal());
        }
        return $this;
    }

    public function addBoletoLink($observer) {
        $orderId = current($observer->getOrderIds());
        $mGwap = Mage::getModel('gwap/order')->load($orderId, 'order_id');
        if ($mGwap->getType() != 'boleto') {
            return $this;
        }

        $order = Mage::getModel('sales/order')->load($orderId);
        //$customerId = $order->getCustomerId();

        $storage = Mage::getSingleton('checkout/session');

        if ($storage) {
            $block = Mage::app()->getLayout()->getMessagesBlock();

            $url = Mage::helper('gwap')->getBoletoUrl($order->getIncrementId());

            $linkMessage = Mage::helper('gwap')->__('Clique aqui');
            $block->addSuccess(
                    sprintf(Mage::helper('gwap')->__('%s para imprimir seu boleto.'), '<span class="retorno"><a href="' . $url . '" target="_blank" class="imprimir_boleto">' . $linkMessage . '</a></span>')
            );
        }
    }

    /**
     * cancela verificacoes de boletos e pedidos de acordo com o vencimento
     * 
     * @return Allpago_Gwap_Model_Observer 
     */
    public function cancelBoleto() {
        $cancelamento = Mage::getStoreConfig('payment/gwap_boleto/cancelamento');
        if (is_numeric($cancelamento) && $cancelamento > 0) {
            $cancelamento++;
            $due_date = Mage::getModel('core/date')->timestamp('-' . $cancelamento . ' days');
        } else {
            $due_date = Mage::getModel('core/date')->timestamp('-2 days');
        }

        $mGwap = Mage::getModel('gwap/order')->getCollection()
                ->addExpireFilter($due_date)
                ->addTypeFilter('boleto')
                ->addStatusFilterCustom(Allpago_Gwap_Model_Order::STATUS_CREATED, Allpago_Gwap_Model_Order::STATUS_CAPTUREPAYMENT);

        $log = Mage::getModel('allpago_mc/log');

        if ($mGwap->count()) {
            foreach ($mGwap as $mGwapitem) {

                $mGwapitem->setStatus('canceled');
                $mGwapitem->setUpdatedAt(Mage::getModel('core/date')->date("Y-m-d H:i:s"));
                $mGwapitem->save();

                $can_cancel = Mage::getStoreConfig('payment/gwap_boleto/cancelar_expirado');

                if ($can_cancel) {

                    $log->add($mGwapitem->getOrderId(), '+ Conversao', 'cancelBoleto()', 'error', 'Pedido expirado');

                    $order = Mage::getModel('sales/order')->load($mGwapitem->getOrderId());
                    /* var $order Mage_Sales_Model_Order */
                    $order->cancel();
                    $order->save();
                }
            }
        }
        return $this;
    }

    public function descontoBoleto($observer) {
        $shoppingCartPriceRule = Mage::getModel('salesrule/rule')->getCollection();
        if (Mage::getStoreConfig('payment/gwap_boleto/active') && Mage::getStoreConfig('payment/gwap_boleto/desconto') > 0) {
            $flag = false;
            $discount = Mage::getStoreConfig('payment/gwap_boleto/desconto');
            $labels[0] = Mage::getStoreConfig('payment/gwap_boleto/texto_desconto');
            foreach ($shoppingCartPriceRule as $rule) {
                if ($rule->getData('name') == 'Boleto Allpago') {
                    $flag = true;

                    $shoppingCartPriceRule = Mage::getModel('salesrule/rule');
                    $shoppingCartPriceRule
                            ->setRuleId($rule->getId())
                            ->setName($rule->getData('name'))
                            ->setDescription('')
                            ->setIsActive(1)
                            ->setWebsiteIds(array(1))
                            ->setCustomerGroupIds(array(0, 1, 2, 3))
                            ->setFromDate('')
                            ->setToDate('')
                            ->setSortOrder('')
                            ->setSimpleAction(Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION)
                            ->setDiscountAmount($discount)
                            ->setStopRulesProcessing(0)
                            ->setStoreLabels($labels);

                    $conditions = array(
                        "1" => array(
                            "type" => "salesrule/rule_condition_combine",
                            "aggregator" => "all",
                            "value" => "1",
                            "new_child" => null
                        ),
                        "1--1" => array(
                            "type" => "salesrule/rule_condition_address",
                            "attribute" => "payment_method",
                            "operator" => "==",
                            "value" => "gwap_boleto"
                        )
                    );

                    try {
                        $shoppingCartPriceRule->setData("conditions", $conditions);
                        $shoppingCartPriceRule->loadPost($shoppingCartPriceRule->getData());
                        $shoppingCartPriceRule->save();
                    } catch (Exception $e) {
                        Mage::log($e->getMessage(), null, 'erro_gwap_boleto_desconto.log');
                        Mage::getSingleton('core/session')->addError(Mage::helper('catalog')->__($e->getMessage()));
                        return;
                    }
                }
            }
            if (!$flag) {
                $name = "Boleto Allpago";

                $shoppingCartPriceRule = Mage::getModel('salesrule/rule');
                $shoppingCartPriceRule
                        ->setName($name)
                        ->setDescription('')
                        ->setIsActive(1)
                        ->setWebsiteIds(array(1))
                        ->setCustomerGroupIds(array(0, 1, 2, 3))
                        ->setFromDate('')
                        ->setToDate('')
                        ->setSortOrder('')
                        ->setSimpleAction(Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION)
                        ->setDiscountAmount($discount)
                        ->setStopRulesProcessing(0)
                        ->setStoreLabels($labels);

                $conditions = array(
                    "1" => array(
                        "type" => "salesrule/rule_condition_combine",
                        "aggregator" => "all",
                        "value" => "1",
                        "new_child" => null
                    ),
                    "1--1" => array(
                        "type" => "salesrule/rule_condition_address",
                        "attribute" => "payment_method",
                        "operator" => "==",
                        "value" => "gwap_boleto"
                    )
                );

                try {
                    $shoppingCartPriceRule->setData("conditions", $conditions);
                    $shoppingCartPriceRule->loadPost($shoppingCartPriceRule->getData());
                    $shoppingCartPriceRule->save();
                } catch (Exception $e) {
                    Mage::log($e->getMessage(), null, 'erro_gwap_boleto_desconto.log');
                    Mage::getSingleton('core/session')->addError(Mage::helper('catalog')->__($e->getMessage()));
                    return;
                }
            }
        } else {
            foreach ($shoppingCartPriceRule as $rule) {
                if ($rule->getData('name') == 'Boleto Allpago') {
                    Mage::getModel('salesrule/rule')->load($rule->getId())->delete();
                }
            }
        }
    }

    public function clearInfoInstallments($observer){
        $checkout = Mage::getSingleton('checkout/session');
        $checkout->setJuros(0);
        $checkout->setBaseTotal(0);
    }
    
    

}
