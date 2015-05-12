<?php

use WordpressIPNProcessor;

use DentistProductDBAL;

/**
 * WordpressIPNProcessorFactory 
 * 
 * Depends on wordpress being bootstrapped
 *
 * @package 
 * @version $id$
 * @copyright 
 * @author Joseph Persie <joseph@supraliminalsolutions.com> 
 * @license 
 */
class WordpressIPNProcessorFactory 
{
    public static function create($post_data)
    {
        $wip = new WordpressIPNProcessor();

        $wip->setProductDbal(new DentistProductDbal());

        $wip->setTransactionVars($post_data);

        $wip->setMailer();

        $wip->setMessages();
        
        return $wip;
    }
}


