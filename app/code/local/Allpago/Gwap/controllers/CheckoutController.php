<?php

class Allpago_Gwap_CheckoutController extends Mage_Core_Controller_Front_Action
{
    public function failureAction(){
                
        $this->loadLayout();
        $this->renderLayout();
    }    
     
  
}
 