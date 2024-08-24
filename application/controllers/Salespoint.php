<?php

class Salespoint extends Pos {

    public $accessObject;
	public $apiAccessValues;
	public $insightRequest;

    /**
     * Handle the point of sales request data
     * 
     * @return array|object
     */
    public function pointOfSaleProcessor($clientData, $requestInfo, $setupInfo = []) {

		//: initializing
        $response = (object) ["status" => "error", "message" => "Error Processing The Request"];

		//: if the request is from an api request then push only json raw data
        $loggedUserBranchId = (isset($apiAccessValues->branchId)) ? xss_clean($apiAccessValues->branchId) : $this->session->branchId;

        // adding a new customer
        if(isset($_POST["nc_firstname"]) and $requestInfo === 'quick-add-customer') {
            
            // create a new object
            $customersObj = load_class("Customers", "controllers");

            // clean the submitted post data
            $customer = (object) array_map("xss_clean", $_POST);
            
            // validate the user data
            if(empty(trim($customer->nc_firstname))){
                $response->message = "Please enter customer's name";
            } elseif(!empty($customer->nc_email) && !filter_var($customer->nc_email, FILTER_VALIDATE_EMAIL)) {
                $response->message = "Please enter a valid email address";
            }
            else{
                $newCustomer = $customersObj->quickAdd($customer);
                if(!empty($newCustomer)){
                    $response->status = "success";
                    $response->message = "Customer Added";
                    $response->data = $newCustomer;
                }
            }
        }

        // saving the point of sale register
        elseif(isset($_POST["customer"]) and $requestInfo === 'saveRegister') {
            
            // create a new orders object
            $ordersObj = load_class("Orders", "controllers");
            $register = (object) $_POST;

            // this is the second stage and also returns true
            if(isset($register->amount_paying, 
                $register->customer, $register->amount_to_pay,
                $register->total_to_pay, $register->discountType, $register->discountAmount) && !isset($register->payment_type)) {

                // confirm that the previous payment has been made
                if($this->session->_oid_LastPaymentMade) {
                    // set the response 
                    $response->status = "success";
                    $response->message = "Payment successfully made.";
                    $response->data = true;
                }
            }

            // ensure all required parameters was parsed
            elseif(isset($register->payment_type, $register->amount_paying, $register->customer, $register->amount_to_pay, $register->discountAmount)) {

                // submit the data for processing
                $result = $ordersObj->saveRegister($register);

                // get the response from the call
                if(!empty($result)){
                    $response->status = "success";
                    $response->message = "Register Saved";
                    $response->data = $result;
                }
            }
        }

        // initiate an email to be sent to the user
        elseif(isset($_POST["sendMail"], $_POST["thisEmail"]) and $requestInfo === 'sendMail') {

            // clean the submitted post data
            $email = (object) array_map("xss_clean", $_POST);

            // validate the data submitted by the user
            if(!filter_var($email->thisEmail, FILTER_VALIDATE_EMAIL)) {
                $response->message = "Please enter a valid email address";
            }
            else {

                // receipt id
                $email->receiptId = isset($email->receiptId) ? $email->receiptId : (!empty($this->session->_oid_Generated) ? $this->session->_oid_Generated : null);

                if(strlen($email->receiptId) < 12) {
                    $response->message = "An invalid Order ID was submitted";
                } else {
                    // additional data
                    $email->branchId = (isset($email->branchId)) ? $email->branchId : (!empty($this->session->_oid_BranchId) ? $this->session->_oid_BranchId : $loggedUserBranchId);

                    $email->template_type = (isset($email->thisRequest)) ? $email->thisRequest : "invoice";
                    $email->itemId = $email->receiptId;
                    
                    // check the fullname
                    $email->fullname = (isset($email->fullname)) ? str_replace(["\t", "\n"], "", trim($email->fullname)) : null;
                    $email->customerId = (isset($email->customerId)) ? $email->customerId : (!empty($this->session->_uid_Generated) ? $this->session->_uid_Generated : null);

                    // generate the recipient list
                    $email->recipients_list = [
                        "recipients_list" => [
                            [
                                "fullname" => $email->fullname,
                                "email" => $email->thisEmail,
                                "customer_id" => $email->customerId,
                                "branchId" => $loggedUserBranchId
                            ]
                        ]
                    ];

                    // submit the data for insertion into the mail sending list
                    $ordersObj = load_class("Orders", "controllers");
                    $newMail = $ordersObj->quickMail($email);

                    // get the response
                    if(!empty($newMail)){
                        $response->status = "success";
                        $response->message = "Invoice successfully sent via Email";
                        $response->data = $newMail;
                    }
                }
            }
        }

        //: payment processor
        elseif(isset($_POST["processMyPayment"], $_POST["orderId"], $_POST["orderTotal"]) && $requestInfo === 'processMyPayment') {
            //: initializing
            $postData = (Object) array_map("xss_clean", $_POST);
            $theTeller = load_class('Payswitch', 'controllers');
            $status = false;

            // Check If Order Is Saved In Database
            $check = $this->getAllRows(
                "sales",
                "COUNT(*) as orderExists",
                "transaction_id = '{$postData->orderId}'"
            );

            if ($check != false && $check[0]->orderExists == 1) {

                // Call Teller Processor
                $process = $theTeller->initiatePayment($postData->orderTotal, $postData->userEmail, $postData->orderId);

                // Check Response Code & Execute
                if (isset($process['code']) && ($process['code'] == '200')) {

                    $this->session->set_userdata("tellerPaymentTransID", $postData->orderId);

                    if ($process['status'] == 'vbv required') {
                        // VBV Required
                        $message = [
                            "msg" => '
                            <p class="alert alert-warning">Your Card Needs Authorization.</p>
                            <a href="'.($process['reason']).'" target="_blank" class="btn btn-success">Click To Verify Card</a>',
                            "action" => false
                        ];
                    } else {
                        // No VBV Required
                        $this->session->set_userdata("tellerUrl", $process['checkout_url']);

                        $message = [
                            "msg" => $process['checkout_url'],
                            "action" => true
                        ];
                    }
                    $status = true;
                } else {
                    $message = '<p class="text-danger">Failed To Process Payment!</p>';
                }

            }

            $response = [
                "status" => $status,
                "message" => $message
            ];
        
        }

        //: check payment status
        elseif (isset($_POST['checkPaymentStatus']) && $requestInfo === "checkPaymentStatus") {
            // create new object
            $theTeller = load_class('Payswitch', 'controllers');
            $status = false;
            // check the session has been set
            if ($this->session->tellerPaymentStatus == true) {
                $transaction_id = xss_clean($this->session->tellerPaymentTransID);

                $checkStatus = $this->getAllRows(
                    "sales",
                    "order_status",
                    "payment_date IS NOT NULL && transaction_id = '{$transaction_id}'"
                );

                if ($checkStatus != false && $checkStatus[0]->order_status == "confirmed") {
                    $status = true;
                    // Unset Session
                    $this->session->unset_userdata("tellerPaymentStatus");
                    $this->session->unset_userdata("tellerPaymentTransID");
                    $this->session->unset_userdata("tellerUrl");
                } else if ($checkStatus != false && $checkStatus[0]->order_status == "cancelled") {
                    $status = "cancelled";
                    // Unset Session
                    $this->session->unset_userdata("tellerPaymentStatus");
                    $this->session->unset_userdata("tellerPaymentTransID");
                    $this->session->unset_userdata("tellerUrl");
                }
            }

            $response = [
                "status" => $status,
                "result" => ""
            ];
            

        }

        //: cancel payment
        elseif(isset($_POST['cancelPayment']) && $requestInfo === "cancelPayment") {

            // assign variable to the transaction id
            $transactionId = xss_clean($this->session->tellerPaymentTransID);

            // run if the transaction id not empty
            if(!empty($transactionId)) {
                
                $status = false;
                // update the sales table
                $query = $this->updateData(
                    "sales",
                    "order_status = 'cancelled', deleted = '1'",
                    "transaction_id = '{$transactionId}'"
                );

                // return true
                if ($query == true) {
                    $status = 200;
                    // Unset Session
                    $this->session->unset_userdata("tellerPaymentStatus");
                    $this->session->unset_userdata("tellerPaymentTransID");
                    $this->session->unset_userdata("tellerUrl");
                }

                $response = [
                    "status" => $status,
                    "result" => ""
                ];
            }

        }

        return json_decode(json_encode($response), true);

    }

}
?>