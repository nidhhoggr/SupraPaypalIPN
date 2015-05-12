<?php
use SupraPaypalIPN\IPNProcessor;

class WordpressIPNProcessor extends IPNProcessor
{
    public function __construct()
    {
        WC()->api->includes();

        WC()->api->register_resources( new WC_API_Server( '/' ) );
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
Hello %s,

We are notifying you because the transaction for %s has been unsuccesful.

Please notify the site adminsistrators with the transaction id.
EOF;

        $invalid_transaction_msg = sprintf(
            $invalid_transaction_msg,
            $user->data->display_name,
            $product->title
        );
    
        $this->setUnidentifiedSubscriberMessage($unidentified_subscriber_msg);

        $this->setIdentifiedSubscriberMessage($identified_subscriber_msg);
        
        $this->setInvalidTransactionMessage($invalid_transaction_msg);
    }

    public function setProductDbal($productDbal)
    {
        $this->productDbal = $productDbal;
    }

    public function getSubscriber()
    {
        return $this->getWordpressUser();
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
            
        return $created;
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

        $product = new stdClass;

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

        $subject = "Your purchase of {$product->title}";

        $mailer = new stdClass();

        //account for the transaction payer_email as well

        $mailer->mail = function($message) use ($wp_user, $subject) {

            var_dump($wp_user->data->user_email, $subject, $message);

            return wp_mail(
                $wp_user->data->user_email, 
                $subject,
                $message
            );
        };

        $this->mailer = $mailer;
    }
}

