<?php

/**
 * Allpago - Gwap Payment Module
 *
 * @title      Magento -> Custom Payment Module for Gwap
 * @category   Payment Gateway
 * @package    Allpago_Gwap
 * @author     Allpago Development Team
 * @copyright  Copyright (c) 2012 Allpago
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Allpago_Gwap_Model_Source_Mask {

    public function toOptionArray() {
        return array(
            array('value' => '0', 'label' => Mage::helper('gwap')->__('Não')),
            array('value' => '1', 'label' => Mage::helper('gwap')->__('Sim'))
        );
    }

}