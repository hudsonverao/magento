
function paymentSelected( element, parent ){
    payment_elements = $$(parent);
    
    for(i=0; i<payment_elements.length; i++){
        payment_elements[i].removeClassName('selected');
    }
    element.addClassName('selected');
}
