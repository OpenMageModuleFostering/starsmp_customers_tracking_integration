<?php

class KremsaDigital_StarSocial_Model_Observer {
    public function optinAfterRegister($observer) {
        $customer = $observer->getCustomer();
        $track = Mage::getModel('starsocial/track');
        $data = array(
            'email'=>$customer->getData('email'),
            "first_name" => $customer->getData('firstname'),
            "last_name" => $customer->getData('lastname'),
        );
        $track->optin($data);
    }

    public function optinAfterLogin($observer) {
        $customer = $observer->getCustomer();
        $track = Mage::getModel('starsocial/track');
        $data = array(
            'email'=>$customer->getData('email'),
            "first_name" => $customer->getData('firstname'),
            "last_name" => $customer->getData('lastname'),
        );
        $track->optin($data);
    }

    public function optinAfterLogout($observer) {
        $track = Mage::getModel('starsocial/track');
        $track->logout();
    }

    public function trackAddToCart($observer) {
        $track = Mage::getModel('starsocial/track');
        $product = Mage::getModel('catalog/product')
            ->load(Mage::app()->getRequest()->getParam('product', 0));
        $product_qty = Mage::app()->getRequest()->getParam('qty', 0);
        $sum = $product->getFinalPrice()*$product_qty;
        $track->track('add_to_cart', $sum);
    }

    public function trackCheckoutComplete($observer) {
        $order_ids = $observer->getData('order_ids');
        $sum = 0;
        foreach ($order_ids as $order_id) {
            $order   = Mage::getModel('sales/order')->load($order_id);
            $sum += $order->getSubtotalInclTax();
        }

        $track = Mage::getModel('starsocial/track');
        $track->track('checkout_complete', $sum);
    }
}