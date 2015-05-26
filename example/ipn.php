<?php

require_once(dirname(__FILE__) . '/../../wp-load.php'); 
require 'bootload.php';

error_reporting(0);
ini_set('log_errors', true);
ini_set('error_log', dirname(__FILE__).'/logs/ipn_errors.log');


/*
Since this script is executed on the back end between the PayPal server and this
script, you will want to log errors to a file or email. Do not try to use echo
or print--it will not work! 

Here I am turning on PHP error logging to a file called "ipn_errors.log". Make
sure your web server has permissions to write to that file. In a production 
environment it is better to have that log file outside of the web root.
*/

use SupraPaypalIPN\IPNListener;
use Supra\Merchant\Infrastructure\Service\WordpressIPNService;

$listener = new IPNListener();

/*
When you are testing your IPN script you should be using a PayPal "Sandbox"
account: https://developer.paypal.com
When you are ready to go live change use_sandbox to false.
*/
$listener->use_sandbox = true;

/*
By default the IpnListener object is going  going to post the data back to PayPal
using cURL over a secure SSL connection. This is the recommended way to post
the data back, however, some people may have connections problems using this
method. 

To post over standard HTTP connection, use:
$listener->use_ssl = false;

To post using the fsockopen() function rather than cURL, use:
$listener->use_curl = false;
*/

/*
The processIpn() method will encode the POST variables sent by PayPal and then
POST them back to the PayPal server. An exception will be thrown if there is 
a fatal error (cannot connect, your server is not configured properly, etc.).
Use a try/catch block to catch these fatal errors and log to the ipn_errors.log
file we setup at the top of this file.

The processIpn() method will send the raw data on 'php://input' to PayPal. You
can optionally pass the data to processIpn() yourself:
$verified = $listener->processIpn($my_post_data);
*/

try {

    $listener->requirePostMethod();

    $verified = $listener->processIpn($_POST);

    if ($verified) {

        /**
         * We've verified this is a valid request so we'll 
         * authenticate as a paypal user now.
         */
        $user = get_user_by( 'slug', 'paypal-api-user'); 
 
        if( $user ) {
            wp_set_current_user( $user->ID, $user->user_login );
            wp_set_auth_cookie( $user->ID );
            do_action( 'wp_login', $user->user_login );
        }

        $ipnService = new WordpressIPNService();

        $ipnService->setAdminEmail('persie.joseph@gmail.com');

        /** 
         * settig this to true will cause the application to 
         * throw exceptions instead of sending notification emails
         */
        $ipnService->setIsDebugEnabled(true); 

        $ipnService->setTransactionVars($_POST);
 
        //mail('YOUR EMAIL ADDRESS', 'Verified IPN', $listener->getTextReport());

        switch ($_POST['txn_type']) {
            case 'web_accept':
                //you received a payment such as a buy now button
                Throw new \Exception('web accept method is not supported');
                break;
            case 'subscr_signup':
                //This shows that someone has subscribed using a subscribe button
                $ipnService->registerSubscriber(); 
                
                break;
            case 'subscr_payment':
                //payment for a subscribed user has just been made
                //do something such as send them a conformation email
                
                $ipnService->makePayment();
                break;
            case 'subscr_failed':
                //This Instant Payment Notification is for a subscription payment failure.
            case 'subscr_eot':
                //this is a End Of Terms meaning that the subscriber either canceled or
                //paypal could not process the payment due to the user not having the funds
                //lets remove them from our database and send them an email
            case 'subscr_cancel':
                //Here the user canceled their account so lets remove them and send an email
                $ipnService->deactivateSubscriber();
                break;
        }
    } else {
        /*
        An Invalid IPN *may* be caused by a fraudulent transaction attempt. It's
        a good idea to have a developer or sys admin manually investigate any 
        invalid IPN.
        */
        wp_mail($ipnService->getAdminEmail(), 'Invalid IPN', $listener->getTextReport());
    }

} catch (Exception $e) {

    error_log($e->getMessage() . ' sending email to ' . $ipnService->getAdminEmail());

    wp_mail($ipnService->getAdminEmail(), 'IPN ERROR', $listener->getTextReport());
}


