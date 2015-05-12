<?php

namespace SupraPaypalIPN;

abstract class IPNProcessor
{
    protected 
        $txnErr = array(),
        $customVars = array();

    public function __construct($transactionVars = null) 
    {
        if(!is_null($transactionVars ))
        {
            $this->setTransactionVars($transactionVars);
        }
    }
    
    abstract public function setMailer();

    abstract public function getSubscriber();

    abstract public function getProductByItemNumber($item_number);

    abstract public function savePurchase();

    abstract public function updateSubscriberSubscription();

    public function setTransactionVars($transactionVars)
    {
        $this->transactionVars = $transactionVars;
        
        $this->extractCustomVars();
    }

    protected function setUnidentifiedSubscriberMessage($msg)
    {
        $this->unidentifiedSubscriberMessage = $msg;
    }
 
    protected function setIdentifiedSubscriberMessage($msg)
    {
        $this->identifiedSubscriberMessage = $msg;
    }
    
    protected function setInvalidTransactionMessage($msg)
    {
        $this->invalidTransactionMessage = $msg;
    }

    public function getTxnErr() 
    {
        return $this->txnErr; 
    }

    public function identifyAndNotifySubscriber() 
    {
        $product_info = $this->transactionVars;

        $this->subscriber = $this->getSubscriber();

        $this->product = $this->getProductByItemNumber($product_info['item_number']);
        
        if(!$this->subscriber) {

            var_dump($this->subscriber);
            $this->notifyUnidentifiedSubscriber();

        } else if(!$this->isValidTransaction()) { 

            $this->notifySubscriberOfInvalidTransaction();

        } else {

            $this->notifyIdentifiedSubscriber();
            $this->updateSubscriberSubscription();
            $this->savePurchase();
        }
    }

    protected function extractCustomVars()
    {
        $product_info = $this->transactionVars;

        $this->customVars = unserialize(base64_decode($product_info['custom']));
    }

    protected function isValidTransaction() 
    {
        $product_info = $this->transactionVars;

        $product = $this->getProductByItemNumber($product_info['item_number']);

        if(!$product) {

            $this->txnErr[] = "Product not found";

        } else {

            $product_price_method = $product->getPrice;

            //validate the payment price
            if($product_price_method() != $product_info['mc_gross']) {
   
                $this->txnErr[] = "Purchase price does not match product price";
            }

            if($product_info['payment_type'] != 'instant') {

                $this->txnErr[] = "Payment type must be instant";
            }

            if($product_info['payment_status'] !== "Completed" && 
                $product_info['payment_status'] !== "Processed") {

                $this->txnErr[] = "The payment status was neither completed nor processed but instead: " . $product_info['payment_status'];
            }
        }

        return count($this->txnErr) < 1;
    }

    protected function notifySubscriberOfInvalidTransaction()
    {
        $this->invalidTransactionMessage .= "\r\nFor the following reasons:\r\n\r\n";

        foreach($this->txnErr as $err)
        {
            $this->invalidTransactionMessage .= "\r\n$err";
        }

        $this->baseNotify($this->invalidTransactionMessage);
    }

    protected function notifyUnidentifiedSubscriber() 
    {
        $this->baseNotify($this->unidentifiedSubscriberMessage);
    }

    protected function notifyIdentifiedSubscriber() 
    {
        $this->baseNotify($this->identifiedSubscriberMessage);
    }

    protected function baseNotify($msg) 
    {
        if(!isset($this->mailer) && !property_exists($this->mailer))
        {
            Throw new RuntimeException('Must set a mailer with a mail method');
        }

        $mail_function = $this->mailer->mail; 

        $mail_function($msg);
    }
}
