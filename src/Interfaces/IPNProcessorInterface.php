<?php

namespace SupraPaypalIPN\Interfaces;

interface IPNProcessorInterface 
{
 
    /**
     * setMailer
     * 
     * Must pass a clousre with a mail method
     *
     * Mail method must accept product_info array
     *
     * @access public
     * @return void
     */
    public function setMailer();
    
    /**
     * getSubscriber
     * 
     * Mailer mail method dependent upon attributes of subscriber
     *
     * @access public
     * @return void
     */
    public function getSubscriber();

    /**
     * getProductByItemNumber
     *  
     *  Must return a object with a getPrice method
     *
     * @access public
     * @return void
     */
    public function getProductByItemNumber($item_number);
    
    public function savePurchase();
    
    public function updateSubscriberSubscription();
}
