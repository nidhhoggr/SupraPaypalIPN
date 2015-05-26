<?php

namespace Supra\Merchant\Infrastructure\Service;

use SupraPaypalIPN\IPNProcessor;

class WordpressIPNService extends IPNProcessor
{
    protected 
        $wp_users
        , $customVars
        , $products
        , $wasTransactionValidated = false
        , $isTransactionValid = false
        , $admin_email
    ;

    public function __construct()
    {
        WC()->api->includes();

        WC()->api->register_resources( new \WC_API_Server( '/' ) );
    
        $this->setMailer();

        $this->setMessages();
    }

    public function setProductDbal($productDbal)
    {
        $this->productDbal = $productDbal;
    }

    public function setAdminEmail($admin_email)
    {
        $this->admin_email = $admin_email;
    }

    public function getAdminEmail()
    {
        return $this->admin_email;
    }

    public function getSubscriber()
    {
        return $this->getWordpressUser();
    }

    public function getWordpressUser()
    {
        $customer_id = $this->customVars['customer_id'];

        if(!isset($customer_id))
        {
            return false;
        }
        else if(isset($this->wp_users[$customer_id]))
        {
            return $this->wp_users[$customer_id];
        }

        $wp_user = get_user_by( 'id', $customer_id);

        $this->wp_users[$customer_id] = $wp_user;

        return $wp_user;
    }

    public function getWooCommerceProduct()
    {
        $product_id = $this->customVars['product_id'];

        if(!isset($product_id))
        {
            return false;
        }
        else if(isset($this->products[$product_id]))
        {
            return $this->products[$product_id];
        }

        $product_arr = WC()->api->WC_API_Products->get_product( $product_id );

        if($product_arr instanceof \WP_Error)
        {
            Throw new \Exception($product_arr->get_error_message());
        }

        $product = new \stdClass;

        foreach($product_arr['product'] as $key => $val)
        {
            $product->$key = $val;
        }

        $product->getPrice = function() use ($product) {

            return $product->price;
        };

        $this->products[$product_id] = $product;

        return $product;
    }

    public function setMessages()
    {
        $user = $this->getWordpressUser();

        $product = $this->getWooCommerceProduct();

        $unidentified_subscriber_msg = <<<EOF
Hello %s,

We are notifying you because your payment for %s has been successful.

However, we were unable to identify your account in our system.

Please notify the site adminsistrators with the transaction id.


EOF;

        $unidentified_subscriber_msg = sprintf(
            $unidentified_subscriber_msg,
            $user->data->display_name,
            $product->title
        );

        $identified_subscriber_msg = <<<EOF
Hello,

We are notifying you because your payment for %s has been successful.

You will recieve an email shortly on your membership details.
EOF;

        $identified_subscriber_msg = sprintf(
            $identified_subscriber_msg,
            $product->title
        );

        $invalid_transaction_msg = <<<EOF
Hello Admin,

We are notifying you because the transaction for %s of %s has been unsuccesful.

Please notify the site adminsistrators with the transaction id and following errors:

%s
EOF;

        $invalid_transaction_msg = sprintf(
            $invalid_transaction_msg,
            $user->data->display_name,
            $product->title,
            var_export($this->getTxnErr(), true)
        );

        $this->setUnidentifiedSubscriberMessage($unidentified_subscriber_msg);

        $this->setIdentifiedSubscriberMessage($identified_subscriber_msg);

        $this->setInvalidTransactionMessage($invalid_transaction_msg);
    }

    public function getProductByItemNumber($item_number)
    {
        return $this->getWooCommerceProduct();
    }

    public function savePurchase()
    {
        $wp_user = $this->getWordpressUser();

        $product = $this->getWooCommerceProduct();

        $order = array(
            'customer_id' => $wp_user->data->ID,
            'status'=>'completed',
            'line_items'=>array(
                array(
                    'product_id'=>$product->id,
                    'id'=>$product->id,
                    'quantity' => 1
                )
            )
        );

        $created = WC()->api->WC_API_Orders->create_order(compact('order'));

        if($created instanceof \WP_Error)
        {
            Throw new \Exception("Issue creating order with the error: " . $created->get_error_message()); 
        }

        return $created;
    }

    /**
     * updateSubscriberSubscription
     * 
     * This should toggle the user roles from user to dentist
     *
     * @access public
     * @return void
     */
    public function updateSubscriberSubscription($is_active = true)
    {
        $wp_user = $this->getWordpressUser();

        if($is_active)
        {
            update_user_meta($wp_user->ID, 'wp_capabilities', array('employer'=>true));
        }
        else
        {
            update_user_meta($wp_user->ID, 'wp_capabilities', array('subscriber'=>true));
        }
    }

    public function setMailer()
    {
        $wp_user = $this->getWordpressUser();

        $product = $this->getWooCommerceProduct();

        $subject = "Purchase of {$product->title}";

        $mailer = new \stdClass();

        //account for the transaction payer_email as well

        $mailer->mail = function($message, $isAdmin) use ($wp_user, $subject) {
            
            if(!$isAdmin)
            {
                $email = $wp_user->data->user_email;
            }
            else
            {
                $email = $this->admin_email;
            }

            return wp_mail(
                $email,
                $subject,
                $message
            );
        };

        $this->mailer = $mailer;
    }

    protected function customValidateTransaction()
    {
        $this->wasTransactionValidated = true;
            
        $product_info = $this->transactionVars;

        $this->subscriber = $this->getSubscriber();

        if($product_info['payment_type'] !== 'instant') 
        { 
            $this->txnErr[] = "Payment type must be instant"; 
        } 

        if(!in_array($product_info['payment_status'], array("Completed","Processed"))) 
        { 
            $this->txnErr[] = "The payment status was neither completed nor processed but instead: " . $product_info['payment_status']; 
        } 
    }

    /**
     * baseAction
     * 
     * This is called by any actions executed in the ipn.
     * This allows multiple actions to be called without having 
     * to check for a valid transaction each times
     *
     * @access protected
     * @return void
     */
    protected function baseAction($cb)
    {
        if(!$this->wasTransactionValidated)
        {
            $this->validateTransaction();

            $this->isTransactionValid = $this->isValidTransaction();

            $this->wasTransactionValidated = true;

            //notify admin here is transaction is not valid
            if(!$this->isTransactionValid)
            {
                if($this->isDebugEnabled)
                {
                    Throw new \Exception("invalid transaction with errros: " . var_export($this->getTxnErr(), true));
                }

                $this->notifyAdminOfInvalidTransaction();
            }
        }

        if($this->isTransactionValid)
        {
            return $cb();
        }
    }

    /**
     * registerSubscriber
     * 
     * Update the user role here and notify them
     *
     * @access public
     * @return void
     */
    public function registerSubscriber()
    {
        return $this->baseAction(function() {

            $this->notifyIdentifiedSubscriber();
            
            $this->updateSubscriberSubscription();
        });
    }

    /**
     * makePayment
     * 
     * Save an order in woocomerrce and notify them about payment received
     *
     * @access public
     * @return void
     */
    public function makePayment()
    {
        return $this->baseAction(function() {

            $this->savePurchase();

            wp_mail(
                $this->getWordpressUser->data->user_email,
                "Your Payment",
                "Your payment has been recieved."
            );
  
        });
    }

    /**
     * deactivateSubscriber
     * 
     *  Deactive the subscriber and notify them
     *
     * @access public
     * @return void
     */
    public function deactivateSubscriber()
    {
        return $this->baseAction(function() {

            $this->updateSubscriberSubscription(false);
            
            wp_mail(
                $this->getWordpressUser->data->user_email,
                "Your dentist role",
                "Your dentist role has been deactivated."
            );
        });
    }
}
