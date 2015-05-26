<?php

namespace SupraPaypalIPN;

abstract class IPNProcessor
{
    protected 
        $txnErr = array(),
        $customVars = array(),
        $isDebugEnabled = false;

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

    abstract protected function customValidateTransaction();

    public function setIsDebugEnabled($is)
    {
        $this->isDebugEnabled = $is;
    }

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

    protected function extractCustomVars()
    {
        $product_info = $this->transactionVars;

        $this->customVars = unserialize(base64_decode($product_info['custom']));
    }

    protected function validateTransaction() 
    {
        $product_info = $this->transactionVars;

        $this->product = $this->getProductByItemNumber($product_info['item_number']);

        $this->subscriber = $this->getSubscriber();
        
        if(!$this->product) 
        {
            $this->txnErr[] = "Product not found";
        }
        else if(!$this->subscriber)
        {
            $this->txnErr[] = "Subscriber not found";
        }
        else 
        {
            $product_price_method = $this->product->getPrice;

            //validate the payment price
            if($product_price_method() !== $product_info['mc_gross']) 
            {
                $this->txnErr[] = "Purchase price {$product_info['mc_gross']} does not match product price {$product_price_method()}";
            }

            //perform other user defined validation here
            $this->customValidateTransaction();
        }

        return $this->isValidTransaction();
    }

    public function isValidTransaction()
    {
        return count($this->txnErr) < 1;
    }

    public function identifyAndNotifySubscriber() 
    {
        $product_info = $this->transactionVars;

        if(!$this->subscriber) 
        {
            $this->notifyUnidentifiedSubscriber();
        }

        if(!$this->isValidTransaction()) { 

            if($this->isDebugEnabled)
            {
                Throw new \Exception("invalid transaction with errros: " . var_export($this->getTxnErr(), true));
            }

            $this->notifyAdminOfInvalidTransaction();

        } else {

            $this->notifyIdentifiedSubscriber();
            $this->updateSubscriberSubscription();
            $this->savePurchase();
        }
    }


    protected function notifyAdminOfInvalidTransaction()
    {
        $this->invalidTransactionMessage .= "\r\nFor the following reasons:\r\n\r\n";

        foreach($this->txnErr as $err)
        {
            $this->invalidTransactionMessage .= "\r\n$err";
        }

        $this->baseNotify($this->invalidTransactionMessage, true);
    }

    protected function notifyUnidentifiedSubscriber() 
    {
        $this->baseNotify($this->unidentifiedSubscriberMessage);
    }

    protected function notifyIdentifiedSubscriber() 
    {
        $this->baseNotify($this->identifiedSubscriberMessage);
    }

    protected function baseNotify($msg, $isAdmin = false) 
    {
        if(!isset($this->mailer) && !property_exists($this, 'mailer'))
        {
            Throw new \RuntimeException('Must set a mailer with a mail method');
        }

        $mail_function = $this->mailer->mail; 

        $mail_function($msg, $isAdmin);
    }
}
