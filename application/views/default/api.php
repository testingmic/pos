<?php
//: call global variables
global $session, $pos, $admin_user, $accessObject, $posClass;

//: set the page header type
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: OPTIONS,GET,POST,PUT,DELETE");
header("Access-Control-Max-Age: 3600");

//: initializing
$response = (object) [
	"status" => "error", 
	"message" => "Error Processing The Request"
];

//: create a new object
$apiValidate = load_class('Api', 'models');

$apiAccessValues = $apiValidate->validateApiKey();
$expiredAccount = true;

//: confirm that the user is logged in
if($admin_user->logged_InControlled() || isset($apiAccessValues->clientId)) {

	// available insight metricts that can be queried
	$availableQueryMetrics = $posClass->availableQueryMetrics;

	//: if the user accessed the file using an access token
	if(isset($apiAccessValues->clientId)) {
		
		$handlerObject = load_class('Handler', 'models');
		$handler = $handlerObject->apiProcess($posClass, $apiAccessValues);

		if(!is_bool($handler)) {
			echo json_encode($handler);
			exit;
		}
	}

	//: initializing
	$loggedUserBranchId = (isset($apiAccessValues->branchId)) ? xss_clean($apiAccessValues->branchId) : $session->branchId;
	$loggedUserBranchAccess = (isset($apiAccessValues->branchAccess)) ? xss_clean($apiAccessValues->branchAccess) : $session->branchAccess;
	$loggedUserClientId = (isset($apiAccessValues->clientId)) ? xss_clean($apiAccessValues->clientId) : $session->clientId;
	$loggedUserId = (isset($apiAccessValues->userId)) ? xss_clean($apiAccessValues->userId) : $session->userId;
	$insightRequest = (isset($_POST["insightRequest"])) ? $_POST["insightRequest"] : $session->insightRequest;
	$limit = (isset($_POST["limit"])) ? (int) $_POST["limit"] : 100000;

	// set the general id
	$session->userId = $loggedUserId;
	$session->clientId = $loggedUserClientId;
	$session->branchId = $loggedUserBranchId;

	//: if the user requested the data from the browser
	$expiredAccount = $session->accountExpired;

	//: create a new object
	$dateClass = load_class('Dates', 'models');
	
	//: client data
	$clientData = $posClass->getAllRows("settings", "*", "clientId='{$loggedUserClientId}'");
	$branchData = $posClass->getAllRows("branches", "*", "id='{$loggedUserBranchId}'");
	
	//: set the data set
	$clientData = $clientData[0];
	$branchData = $branchData[0];

	//: get the client information
	$setupInfo = (Object) json_decode($clientData->setup_info);

	//: create new objects
	$accessObject->userId = $loggedUserId;
	
	//: where clause for the user role
	$branchAccess = !$accessObject->hasAccess('monitoring', 'branches') ? " AND a.branchId = '{$loggedUserBranchId}'" : null;

	//: dashboard inights
	if(confirm_url_id(1, 'dashboardAnalytics') || confirm_url_id(1, 'reportsAnalytics')) {
		
		// create object for report
		$handlerObject = load_class('Reports', 'controllers');

		// set some additional variables
		$handlerObject->dateClass = $dateClass;
		$handlerObject->accessObject = $accessObject;
		$handlerObject->insightRequest = $insightRequest;
		$handlerObject->apiAccessValues = $apiAccessValues;
		
		// process the request
		$response = $handlerObject->{confirm_url_id(1)}($clientData, $setupInfo, $expiredAccount, confirm_url_id(2));

	}

	//: MOVE INTO THIS ENTIRE SECTION IF THE ACCOUNT HAS NOT YET EXPIRED
	elseif(!$expiredAccount) {
	
		//: fetch customers list for json
		if(isset($_POST["fetchCustomersOptionsList"]) && confirm_url_id (1, "fetchCustomersOptionsList")) {

			// fetch the data
			$customersClass = load_class("Customers", "controllers");
			$customers = $customersClass->fetch("id, customer_id, firstname, lastname, CONCAT(firstname, ' ', lastname) AS fullname, preferred_payment_type, date_log, clientId, branchId, phone_1, state, phone_2, email, residence", "AND customer_id != 'WalkIn'");

			// fetch the data
			$customers_list = [];

			$response = [
				"status" => 200,
				"result" => $customers
			];
		}

		//: fetch customers list for json
		elseif(isset($_POST["fetchPOSProductsList"]) && confirm_url_id (1, "fetchPOSProductsList")) {
			// query the database
			$result = $posClass->getAllRows("products", 
				"id, product_image, category_id, product_title, source, quantity, category_id, product_image AS image, product_id, product_price, date_added, product_description, product_price, cost_price, threshold", "status = '1' AND branchId = '{$loggedUserBranchId}' AND clientId = '{$loggedUserClientId}'");

			// data
			$productsList = [];
			$ii = 0;

			// set the payment made session as false
			$session->_oid_LastPaymentMade = false;

			// initializing
			if(count($result) > 0) {
				
				// loop through the list of products
				foreach($result as $results) {
					// increment
					$ii++;

					// add to the list to return
					$productsList[] = [
						'row_id' => $ii,
						'product_id' => $results->id,
						'product_code' => $results->product_id,
						'product_title' => $results->product_title,
						'price' => $results->product_price,
						'threshold' => $results->threshold,
						'source' => $results->source,
						'product_description' => $results->product_description,
						'date_added' => $results->date_added,
						'cost_price' => $results->cost_price,
						'category_id' => $results->category_id,
						'image' => (($results->source == 'Vend') ? $results->product_image : ((empty($results->product_image)) ? $config->base_url("assets/images/products/default.png") : ((!empty($results->product_image) && file_exists($results->product_image)) ? $config->base_url($results->product_image) : $config->base_url("assets/images/products/$results->product_image")))),
						'product_quantity' => $results->quantity,
						'product_price' => "<input class='form-control input_ctrl' style='width:100px' data-row-value=\"{$results->id}\" data-product-id='{$results->id}' name=\"product_price\" value=\"".$results->product_price."\" id=\"product_price_{$results->id}\" type='number' min='1'>",
						'quantity' => "<input data-row-value=\"{$results->id}\" class='form-control input_ctrl' style='width:100px' data-product-id='{$results->id}' value='1' name=\"product_quantity\" id=\"product_quantity_{$results->id}\" type='number' min='1'>",
						'overall' => "<span data-row-value=\"{$results->id}\" id=\"product_overall_price\">".number_format($results->product_price, 0)."</span>",
		                "action" => "<button data-image=\"{$results->product_image}\" type=\"button\" class=\"btn btn-success atc-btn\" data-row-value=\"{$results->id}\" data-name=\"{$results->product_title}\"><i class=\"ion-ios-cart\"></i> Add</button>"

					];
				}
			}

			$response = [
				"status" => 200,
				"result" => $productsList
			];

		}

		//: point of sale processing
		elseif(confirm_url_id(1, 'pointOfSaleProcessor')) {

			// adding a new customer
			if(isset($_POST["nc_firstname"]) and confirm_url_id(2, 'quick-add-customer')) {
				
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
			elseif(isset($_POST["customer"]) and confirm_url_id(2, 'saveRegister')) {
				
				// create a new orders object
				$ordersObj = load_class("Orders", "controllers");
				$register = (object) $_POST;

				// this is the second stage and also returns true
				if(isset($register->amount_paying, 
					$register->customer, $register->amount_to_pay,
					$register->total_to_pay, $register->discountType, $register->discountAmount) && !isset($register->payment_type)) {

					// confirm that the previous payment has been made
					if($session->_oid_LastPaymentMade) {
						// set the response 
						$response->status = "success";
						$response->message = "Payment successfully made.";
						$response->data = true;
					}
				}

				// ensure all required parameters was parsed
				elseif(isset($register->payment_type, $register->amount_paying, $register->customer, $register->amount_to_pay,$register->total_to_pay, $register->discountType, $register->discountAmount)) {

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
			elseif(isset($_POST["sendMail"], $_POST["thisEmail"]) and confirm_url_id(2, 'sendMail')) {

				// clean the submitted post data
				$email = (object) array_map("xss_clean", $_POST);

				// validate the data submitted by the user
				if(!filter_var($email->thisEmail, FILTER_VALIDATE_EMAIL)) {
					$response->message = "Please enter a valid email address";
				}
				else {

					// receipt id
					$email->receiptId = isset($email->receiptId) ? $email->receiptId : (!empty($session->_oid_Generated) ? $session->_oid_Generated : null);

					if(strlen($email->receiptId) < 12) {
						$response->message = "An invalid Order ID was submitted";
					} else {
						// additional data
						$email->branchId = (isset($email->branchId)) ? $email->branchId : (!empty($session->_oid_BranchId) ? $session->_oid_BranchId : $loggedUserBranchId);

						$email->template_type = (isset($email->thisRequest)) ? $email->thisRequest : "invoice";
						$email->itemId = $email->receiptId;
						
						// check the fullname
						$email->fullname = (isset($email->fullname)) ? str_replace(["\t", "\n"], "", trim($email->fullname)) : null;
						$email->customerId = (isset($email->customerId)) ? $email->customerId : (!empty($session->_uid_Generated) ? $session->_uid_Generated : null);

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
			elseif(isset($_POST["processMyPayment"], $_POST["orderId"], $_POST["orderTotal"]) && confirm_url_id(2, 'processMyPayment')) {
				//: initializing
				$postData = (Object) array_map("xss_clean", $_POST);
				$theTeller = load_class('Payswitch', 'controllers');
				$status = false;

				// Check If Order Is Saved In Database
				$check = $posClass->getAllRows(
					"sales",
					"COUNT(*) as orderExists",
					"transaction_id = '{$postData->orderId}'"
				);

				if ($check != false && $check[0]->orderExists == 1) {

					// Call Teller Processor
					$process = $theTeller->initiatePayment($postData->orderTotal, $postData->userEmail, $postData->orderId);

		            // Check Response Code & Execute
		            if (isset($process['code']) && ($process['code'] == '200')) {

		                $session->set_userdata("tellerPaymentTransID", $postData->orderId);

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
		                	$session->set_userdata("tellerUrl", $process['checkout_url']);

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
			elseif (isset($_POST['checkPaymentStatus']) && confirm_url_id(2, "checkPaymentStatus")) {
				// create new object
				$theTeller = load_class('Payswitch', 'controllers');
				$status = false;
				// check the session has been set
				if ($session->tellerPaymentStatus == true) {
					$transaction_id = xss_clean($session->tellerPaymentTransID);

					$checkStatus = $posClass->getAllRows(
						"sales",
						"order_status",
						"payment_date IS NOT NULL && transaction_id = '{$transaction_id}'"
					);

					if ($checkStatus != false && $checkStatus[0]->order_status == "confirmed") {
						$status = true;
						// Unset Session
						$session->unset_userdata("tellerPaymentStatus");
						$session->unset_userdata("tellerPaymentTransID");
						$session->unset_userdata("tellerUrl");
					} else if ($checkStatus != false && $checkStatus[0]->order_status == "cancelled") {
						$status = "cancelled";
						// Unset Session
						$session->unset_userdata("tellerPaymentStatus");
						$session->unset_userdata("tellerPaymentTransID");
						$session->unset_userdata("tellerUrl");
					}
				}

				$response = [
					"status" => $status,
					"result" => ""
				];
				

			}

			//: cancel payment
			elseif(isset($_POST['cancelPayment']) && confirm_url_id(2, "cancelPayment")) {

				// assign variable to the transaction id
				$transactionId = xss_clean($session->tellerPaymentTransID);

				// run if the transaction id not empty
				if(!empty($transactionId)) {
					
					$status = false;
					// update the sales table
					$query = $posClass->updateData(
						"sales",
						"order_status = 'cancelled', deleted = '1'",
						"transaction_id = '{$transactionId}'"
					);

					// return true
					if ($query == true) {
						$status = 200;
						// Unset Session
						$session->unset_userdata("tellerPaymentStatus");
						$session->unset_userdata("tellerPaymentTransID");
						$session->unset_userdata("tellerUrl");
					}

					$response = [
						"status" => $status,
						"result" => ""
					];
				}

			}

		}

		//: Quotes / Requests
		elseif(isset($_POST["listRequests"], $_POST["requestType"]) && confirm_url_id(1, 'listRequests')) {
			// assign variable to remove
			$postData = (Object) array_map('xss_clean', $_POST);

			// check the access levels
			if($accessObject->hasAccess('view', 'branches')) {
				// list all quotes
				$accessLevelClause = "AND rq.branchId = '{$loggedUserBranchId}'"; 
			}

			// query the database
			$result = $posClass->getAllRows(
				"requests rq 
					LEFT JOIN customers ct ON ct.customer_id = rq.customer_id
					LEFT JOIN users us ON us.user_id = rq.recorded_by
					LEFT JOIN branches bc ON bc.id = rq.branchId
				", 
				"rq.id, rq.branchId, rq.request_id, rq.customer_id, rq.currency, 
					CASE WHEN rq.request_discount IS NULL THEN rq.request_total ELSE (rq.request_total-rq.request_discount) END AS request_sum, rq.request_date, bc.branch_name, rq.request_type,
					rq.recorded_by, CONCAT(ct.firstname, ' ', ct.lastname) AS customer_name,
					us.name AS recorded_by
				", 
				"rq.deleted = '0' {$accessLevelClause} AND 
					rq.clientId = '{$loggedUserClientId}' AND rq.request_type = '{$postData->requestType}' ORDER BY rq.id DESC LIMIT {$limit}"
			);

			// initializing
			$row = 0;
			$results = [];

			$ordersObj = load_class('Orders', 'controllers');

			// loop through the list of items
			foreach($result as $eachRequest) {
				// configure the
				$row++;

				if($rawJSON) {
					$eachRequest->itemLines = $ordersObj->requestDetails($eachRequest->request_id, $loggedUserClientId, $loggedUserBranchId, $loggedUserId);
					unset($eachRequest->id);
					$results[] = $eachRequest;
				} else {

					$eachRequest->action = "<div align=\"center\">";
		        
			        $eachRequest->action .= "<a class=\"btn btn-sm btn-outline-primary\" title=\"Export the {$eachRequest->request_type} to PDF\" data-value=\"{$eachRequest->request_id}\" href=\"".$config->base_url('export/'.$eachRequest->request_id)."\" target=\"_blank\"><i class=\"fa fa-file-pdf\"></i> </a> &nbsp;";

			        // check if the user has access to delete this item
			        if($accessObject->hasAccess('delete', strtolower($eachRequest->request_type.'s'))) {
			        	// print the delete button
			        	$eachRequest->action .= "<a class=\"btn btn-sm delete-item btn-outline-danger\" data-msg=\"Are you sure you want to delete this {$eachRequest->request_type}\" data-request=\"{$eachRequest->request_type}\" data-url=\"{$config->base_url('api/deleteData')}\" data-id=\"{$eachRequest->request_id}\" href=\"javascript:void(0)\"><i class=\"fa fa-trash\"></i> </a>";
			        }

			        $eachRequest->action .= "</div>";

					// append to the list of items
					$results[] = [
						'row_id' => $row,
						'request_type' => $eachRequest->request_type,
						'request_id' => "<a target=\"_blank\" class=\"text-success\" title=\"Click to view full details\" href=\"{$config->base_url('export/'.$eachRequest->request_id)}\">{$eachRequest->request_id}</a>",
						'branch_name' => $eachRequest->branch_name,
						'customer_name' => $eachRequest->customer_name,
						'quote_value' => "{$clientData->default_currency} ". number_format($eachRequest->request_sum, 2),
						'recorded_by' => $eachRequest->recorded_by,
						'request_date' => $eachRequest->request_date,
						'action' => $eachRequest->action
					];
				}
			}

			$response = [
				'status' => 200,
				'result' => $results,
				'rows_count' => count($result)
			];

		}

		//: Process requests
		//: Submit to cart for processing
		elseif(isset($_POST['selectedProducts'], $_POST["request"], $_POST["customerId"], $_POST["discountType"], $_POST["discountAmt"]) && confirm_url_id(1, 'pushRequest')) {
			// #initializing
			$data = "";
			$status = 500;
			$responseData = array();
			$currentRequest = $session->thisRequest;
			
			// check all the values parsed
			if($currentRequest != $_POST["request"]) {
				$data = "Sorry! Another tab is opened, refresh this page to continue.";
			} elseif($_POST["customerId"] == "null") {
				$data = "Sorry! Customer Name cannot be empty.";
			} elseif(!in_array($_POST["request"], ["QuotesList", "OrdersList"])) {
				$data = "Sorry! An invalid request was made.";
			} elseif(!in_array($_POST["discountType"], ["percentage", "cash"])) {
				$data = "Sorry! An invalid discount type selected.";
			} else {
				# check the user input
				$discountAmt = (isset($_POST["discountAmt"])) ? xss_clean($_POST["discountAmt"]) : 0;
				$discountType = (isset($_POST["discountType"])) ? xss_clean($_POST["discountType"]) : 0;
				$customerId = xss_clean($_POST["customerId"]);

				# products list
				$productsList = $_POST['selectedProducts'];

				# call the orders controller
				$requestClass = load_class('Requests', 'controllers');

				# get the request type
				$request = ($_POST["request"] == 'QuotesList') ? 'Quote' : 'Order';

				// process the order list
				if($requestClass->addRequest($request, $customerId, $discountAmt, $discountType, $productsList)) {
					$status = 200;
					$data = [
						"message" => "Record was successfully inserted!",
						"invoiceNumber" => $posClass->lastColumnValue('request_id', 'requests'),
						"requestType" => strtolower($request)
					];
				} else {
					$data = "Sorry! There was an error while processing the request.";
				}
			}

			$response = array(
				"status" => $status,
				"result" => $data
			);

		}

		//: branch management
		elseif(confirm_url_id(1, "branchManagment")) {

			// initializing
			$message = "Error processing request!";
			$status = false;

			// fetch the list of all branches
			if (isset($_POST['request'], $_POST['request']) and confirm_url_id(2, "fetchBranchesLists")) {

				$condition = "AND deleted=?";
				$message = [];

				// set the branch id to use
				$branchData = (Object) array_map("xss_clean", $_POST);

				// confirm the user permission to perform this action
				if($accessObject->hasAccess('view', 'branches')) {

					// append the request
					$response->request = "branchesList";

					// fetch the branch information
					$query = $pos->prepare("
						SELECT * 
						  FROM branches 
						WHERE clientId = ? {$condition} ORDER BY id");

					if ($query->execute([$loggedUserClientId, 0])) {
						$i = 0;
						while ($data = $query->fetch(PDO::FETCH_OBJ)) {
							$i++;

							if($rawJSON) {
								$data->branch_id = $data->id;
								unset($data->id);
								$message[] = $data;
							} else {
								$branch_type = "<br><span class='badge text-white ".(($data->branch_type == 'Store') ? "badge-dark" : "badge-primary")."'>{$data->branch_type}</span>";

								$action = '<div width="100%" align="center">';
								if($accessObject->hasAccess('update', 'branches')) {
									$action .=  "<button class=\"btn btn-sm btn-outline-success edit-branch\" data-branch-id=\"{$data->branch_id}\">
										<i class=\"fa fa-edit\"></i>
									</button> ";
								}

								if($accessObject->hasAccess('delete', 'branches')) {
									$action .= "<button class=\"btn btn-sm ".(($data->status == 1) ? "btn-outline-danger" : "btn-outline-primary")." delete-item\" data-url=\"".$config->base_url('api/branchManagment/updateStatus')."\" data-state=\"{$data->status}\" data-msg=\"".(($data->status == 1) ? "Are you sure you want to set the {$data->branch_name} as inactive?" : "Do you want to proceed and set the {$data->branch_name} as active?")."\" data-request=\"branch-status\" data-id=\"{$data->id}\">
										<i class=\"fa ".(($data->status == 1) ? "fa-stop" : "fa-play")."\"></i>
									</button> ";
								}

								$action .= "</div>";

								$message[] = [
									'row_id' => $i,
									'branch_id' => $data->branch_id,
									'branch_type' => $data->branch_type,
									'branch_name_text' => $data->branch_name,
									'branch_name' => $data->branch_name.$branch_type,
									'location' => $data->location,
									'email' => $data->branch_email,
									'contact' => $data->branch_contact,
									'status' => "<div align='center'>".(($data->status == 1) ? "<span class='badge badge-success'>Active</span>" : "<span class='badge badge-danger'>Inactive</span>")."</div>",
									'action' => $action
								];
							}
						}
						$status = true;
					}
				} else {
					$message = [];
				}

			} elseif(isset($_POST['branchId'], $_POST['getBranchDetails']) && confirm_url_id(2, "getBranchDetails")) {

				$branchId = xss_clean($_POST['branchId']);

				// set the branch id to use
				$branchData = (Object) array_map("xss_clean", $_POST);

				// check if the user has permissions to perform this action
				if($accessObject->hasAccess('view', 'branches')) {

					// Check If Branch Exists
					$query = $pos->prepare("SELECT * FROM branches WHERE branch_id = ? && deleted = ? && clientId = ?");

					if ($query->execute([$branchId, '0', $loggedUserClientId])) {

						$data = $query->fetch(PDO::FETCH_OBJ);

						$session->curBranchId = $branchId;

						$message = [
							"branch_id"	=> $data->branch_id,
							"branch_name"	=> $data->branch_name,
							"branch_name_text" => $data->branch_name,
							"contact" => $data->branch_contact,
							"email"	=> $data->branch_email,
							"location"	=> $data->location,
							"status"	=> (($data->status == 1) ? "Active" : "Inactive"),
							"branch_type" => $data->branch_type
						];
						$status = true;
					} else {
						$message = "Sorry! Branch Cannot Be Found.";
					}
				} else {
					$message = "Sorry! You do not have the required permissions to perform this action.";
				}
				
			} elseif (isset($_POST['branchName'], $_POST['branchType']) && confirm_url_id(2, "addBranchRecord")) {

				// set the branch id to use
				$branchData = (Object) array_map("xss_clean", $_POST);

				// Check If Fields Are Not Empty
				if (!empty($_POST['branchName']) && !empty($_POST['branchType'])) {

					// validate the records
					if(!empty($branchData->email) && !filter_var($branchData->email, FILTER_VALIDATE_EMAIL)) {
						$message = "Please enter a valid email address";
					} elseif(!empty($branchData->phone) && !preg_match('/^[0-9+]+$/', $branchData->phone)) {
						$message = "Please enter a valid contact number";
					} elseif(!in_array($branchData->branchType, ['Store', 'Warehouse'])) {
						$message = "Invalid Store type was submitted";
					} else {
						
						// if the user wants to add a new record
						if ($branchData->record_type == "new-record") {

							// Check Email Exists
							$checkData = $posClass->getAllRows("branches", "COUNT(*) AS proceed", "branch_name='{$branchData->branchName}' && branch_type = '{$branchData->branchType}' && deleted = '0' && clientId = '{$loggedUserClientId}'");

							if ($checkData != false && $checkData[0]->proceed == '0') {

								// check if the user has permissions to perform this action
								if($accessObject->hasAccess('add', 'branches')) {
									//: Generate a new branch id
									$branch_id = random_string('alnum', 12);

									// Add Record To Database
									$query = $posClass->addData(
										"branches" ,
										"clientId='{$loggedUserClientId}', branch_type='{$branchData->branchType}', branch_name='{$branchData->branchName}', location='{$branchData->location}', branch_email='{$branchData->email}', branch_contact='{$branchData->phone}', branch_logo='{$clientData->client_logo}', branch_id = '{$branch_id}'"
									);

									// Record user activity
									$posClass->userLogs('branches', $branch_id, 'Added a new Store Outlet into the System.');

									if ($query == true) {
										// Show Success Message
										$message = "Store Outlet Have Been Successfully Registered.";
										$status = true;
									} else {
										$message = "Error encountered while processing request.";
									}
								} else {
									$message = "Sorry! You do not have the required permissions to perform this action.";
								}
							} else {
								$message = "Sorry! This Store Outlet already exist.";
							}
						} else if ($branchData->record_type == "update-record") {
							// CHeck If User ID Exists
							$checkData = $posClass->getAllRows("branches", "COUNT(*) AS branchTotal", "branch_id='{$branchData->branchId}'");

							if ($checkData != false && $checkData[0]->branchTotal == '1') {

								// call the branch id from the session
								$branchData->branchId = $session->curBranchId;

								// check if the user has permissions to perform this action
								if($accessObject->hasAccess('update', 'branches')) {
									// save the previous data set
									$prevData = $posClass->getAllRows("branches", "*", "clientId='{$loggedUserClientId}' AND branch_id='{$branchData->branchId}'")[0];

									/* Record the initial data before updating the record */
									$posClass->dataMonitoring('branches', $branchData->branchId, json_encode($prevData));

									// update user data
									$query = $posClass->updateData(
										"branches",
										"branch_name='{$branchData->branchName}', location='{$branchData->location}', branch_type='{$branchData->branchType}', branch_email='{$branchData->email}', branch_contact='{$branchData->phone}'",
										"branch_id='{$branchData->branchId}' && clientId='{$loggedUserClientId}'"
									);

									// Record user activity
									$posClass->userLogs('branches', $branchData->branchId, 'Updated the details of the Store Outlet.');

									if ($query == true) {

										$message = "Store Outlet Details Have Been Successfully Updated.";
										$status = true;
									} else {
										$message = "Sorry! Store Outlet Records Failed To Update.";
									}
								} else {
									$message = "Sorry! You do not have the required permissions to perform this action.";
								}

							} else {
								$message = "Sorry! Store Outlet Does Not Exist.";
							}
							// Update Record
						} else {
							$message = "Your Request Is Not Recognized";
						}
					}

				} else {
					$message = "Please Check All Required Fields.";
				}

			}

			// update the branch state
			elseif(isset($_POST['itemToDelete'], $_POST['itemId']) && confirm_url_id(2, "updateStatus")) {
				// set the branch id to use
				$branchData = (Object) array_map("xss_clean", $_POST);

				// confirm the user permission to perform this action
				if($accessObject->hasAccess('update', 'branches')) {

					// get the branch status using the id parsed
					$query = $pos->prepare("SELECT status FROM branches WHERE id = ? && deleted = ? && clientId = ?");

					// execute and fetch the record
					if ($query->execute([$branchData->itemId, '0', $loggedUserClientId])) {
						// get the data
						$data = $query->fetch(PDO::FETCH_OBJ);
						
						// branch status
						$state = ($data->status == 1) ? 0 : 1;
						
						// update the information
						$posClass->updateData(
							"branches",
							"status='{$state}'",
							"clientId='{$loggedUserClientId}' AND id='{$branchData->itemId}'"
						);

						// Record user activity
						$posClass->userLogs('branches', $branchData->itemId, 'Updated the status of the branch and set it as '.(($state) ? "Active" : "Inactive"));

						$status = true;
						$message = "Branch status was Successfully updated";

					}
				} else {
					$message = "Sorry! You do not have the required permissions to perform this action.";
				}
			}

			// update the store settings
			elseif(confirm_url_id(2, 'settingsManager')) {

				// save the previous data set
				$prevData = $posClass->getAllRows("settings", "*", "clientId='{$loggedUserClientId}'")[0];

				/* Record the initial data before updating the record */
				$posClass->dataMonitoring('settings', $loggedUserClientId, json_encode($prevData));

				// update the store settings
				if(isset($_POST['updateCompanyDetail']) && confirm_url_id(3, "updateCompanyDetail")) {
					// assign variables to the data that have been parsed
					$postData = (Object) array_map('xss_clean', $_POST);

					$status = 500;

					// start configuring the dataset
					if(!isset($postData->company_name) || (empty($postData->company_name))) {
						// print the error message
						$message = '<div class="alert alert-danger">Sorry! Company name cannot be empty.</div>';
					} elseif(!empty($postData->email) && !filter_var($postData->email, FILTER_VALIDATE_EMAIL)) {
						$message = '<div class="alert alert-danger">Please enter a valid email address</div>';
					} elseif(!empty($postData->primary_contact) && !preg_match('/^[0-9+]+$/', $postData->primary_contact)) {
						$message = '<div class="alert alert-danger">Please enter a valid primary contact</div>';
					} elseif(!empty($postData->secondary_contact) && !preg_match('/^[0-9+]+$/', $postData->secondary_contact)) {
						$message = '<div class="alert alert-danger">Please enter a valid secondary contact</div>';
					} else {
						
						$uploadDir = 'assets/images/company/';

						// check if the user has permissions to perform this action
						if($accessObject->hasAccess('update', 'settings')) {
							
							// File path config 
				            $fileName = basename($_FILES["company_logo"]["name"]); 
				            $targetFilePath = $uploadDir . $fileName; 
				            $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));

				            // Allow certain file formats 
				            $allowTypes = array('jpg', 'png', 'jpeg'); 
				            
				            // check if its a valid image
				            if(!empty($fileName) && in_array($fileType, $allowTypes)){
				            	
				               	// set a new filename
				               	$fileName = $uploadDir . random_string('alnum', 25).'.'.$fileType;

				                // Upload file to the server 
				                if(move_uploaded_file($_FILES["company_logo"]["tmp_name"], $fileName)){ 
				                    $uploadedFile = $fileName;
				                    $uploadStatus = 1; 
				                } else { 
				                    $uploadStatus = 0; 
				                    $message = '<div class="alert alert-danger">Sorry, only PDF, DOC, JPG, JPEG, & PNG files are allowed to upload.</div>';
				                }

				            } else { 
				                $uploadStatus = 0;
				            }

				            // clock display
				            $display_clock = isset($postData->display_clock) ? (int)$postData->display_clock : 0;
				            
				            // available colors
				            $themeColors = [
				            	"danger" => ["bg_colors"=>"bg-danger text-white no-border","bg_color_code"=>"#f5365c", "bg_color_light"=>"#f3adbb",
				            		"btn_outline" => "btn-outline-danger"
				            	],
				            	"indigo" => ["bg_colors"=>"bg-indigo text-white no-border","bg_color_code"=>"#5603ad", "bg_color_light"=>"#5e72e4",
				            		"btn_outline" => "btn-outline-primary"
				            	],
								"orange" => ["bg_colors"=>"bg-orange text-white no-border","bg_color_code"=>"#fb6340", "bg_color_light"=>"#e6987a",
									"btn_outline" => "btn-outline-warning"
								],
								"blue" => ["bg_colors"=>"bg-blue text-white no-border","bg_color_code"=>"#324cdd", "bg_color_light"=>"#97a5f1",
									"btn_outline" => "btn-outline-info"
								],
								"purple" => ["bg_colors"=>"bg-purple text-white no-border","bg_color_code"=>"#8965e0", "bg_color_light"=>"#97a5f1",
									"btn_outline" => "btn-outline-primary"
								],
								"green" => ["bg_colors"=>"bg-green text-white no-border","bg_color_code"=>"#24a46d", "bg_color_light"=>"#2dce89",
									"btn_outline" => "btn-outline-success"
								],
								"teal" => ["bg_colors"=>"bg-teal text-white no-border","bg_color_code"=>"#0da5c0", "bg_color_light"=>"#11cdef",
									"btn_outline" => "btn-outline-secondary"
								],
								"darker" => ["bg_colors"=>"bg-gradient-darker text-white no-border","bg_color_code"=>"#000", "bg_color_light"=>"#3c3939",
									"btn_outline" => "btn-outline-dark"
								]
							];
				            
				            // theme color can only be set for alpha accounts
				            if($setupInfo->type == "alpha") {
				           		$postData->theme_color = isset($postData->theme_color) ? $postData->theme_color : 'purple';
				           	} else {
				           		$postData->theme_color = 'purple';
				           	}

				            $theme_color = (in_array($postData->theme_color, array_keys($themeColors))) ? $themeColors[$postData->theme_color] : $themeColors[$postData->theme_color];

				            // update user data
							$query = $posClass->updateData(
								"settings",
								"client_name='{$postData->company_name}', client_email='{$postData->email}', client_website='{$postData->website}', primary_contact='{$postData->primary_contact}', secondary_contact='{$postData->secondary_contact}', address_1='{$postData->address}', display_clock='{$display_clock}', theme_color_code='{$postData->theme_color}', theme_color='".json_encode($theme_color)."'
								",
								"clientId='{$loggedUserClientId}'"
							);

							// Record user activity
							$posClass->userLogs('settings', $loggedUserClientId, 'Updated the general settings tab of the Company.');

							// continue
				            $status = 200;

				            $message = "Settings updated";

							// update the client logo
							if($uploadStatus == 1) {
								$posClass->updateData(
									"settings",
									"client_logo='{$uploadedFile}'",
									"clientId='{$loggedUserClientId}'"
								);

								$status = 201;
								$message = $config->base_url($uploadedFile);
							}

						} else {
							$message = '<div class="alert alert-danger">Sorry! You do not have the required permissions to perform this action.</div>';
						}

					}

				}

				// update the sales section
				elseif(isset($_POST["updateSalesDetails"], $_POST["receipt_message"]) && confirm_url_id(3, "updateSalesDetails")) {
					// assign variables to the data that have been parsed
					$postData = (Object) $_POST;

					// preset the state
					$status = 500;
					$uploadStatus = 0;
					$workingDays = null;

					// working days processing
					if(isset($postData->opening_days) && !empty($postData->opening_days)) {
						$workingDays = implode(",", $postData->opening_days);
					}
					$print_receipt = isset($postData->print_receipt) ? xss_clean($postData->print_receipt) : null;

					// update user data
					$query = $posClass->updateData(
						"settings",
						"print_receipt='{$print_receipt}',
						expiry_notification_days='".xss_clean($postData->exp_notifi_days)."', 
						allow_product_return='".xss_clean($postData->allow_product_return)."',
						fiscal_year_start='".xss_clean($postData->fiscal_year_start)."',
						shop_opening_days='".xss_clean($workingDays)."',
						default_currency='".xss_clean($postData->default_currency)."',
						receipt_message='".xss_clean($postData->receipt_message)."',
						terms_and_conditions='".htmlentities($postData->terms_and_conditions)."'
						",
						"clientId='{$loggedUserClientId}'"
					);

					// Record user activity
					$posClass->userLogs('settings', $loggedUserClientId, 'Updated the sales details tab of the Company.');

					$uploadDir = 'assets/images/company/';

					// File path config 
			        $fileName = basename($_FILES["company_logo"]["name"]); 
			        $targetFilePath = $uploadDir . $fileName; 
			        $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));

			        // Allow certain file formats 
			        $allowTypes = array('jpg', 'png', 'jpeg'); 
			        
			        // check if its a valid image
			        if(!empty($fileName) && in_array($fileType, $allowTypes)){
			        	
			           	// set a new filename
			           	$fileName = $uploadDir . random_string('alnum', 25).'.'.$fileType;

			            // Upload file to the server 
			            if(move_uploaded_file($_FILES["company_logo"]["tmp_name"], $fileName)){ 
			                $uploadedFile = $fileName;
			                $uploadStatus = 1; 
			            }
			        }

			        $status = 201;
			        $message = "Settings updated";
			        
			        if($uploadStatus) {
				        $posClass->updateData(
							"settings",
							"manager_signature='{$uploadedFile}'",
							"clientId='{$loggedUserClientId}'"
						);
						$message = $config->base_url($uploadedFile);
				    }		
				}

				// save reports information
				elseif(isset($_POST["saveReportsRecord"]) && confirm_url_id(3, "saveReportsRecord")) {
					// assign variables to the data that have been parsed
					$postData = (Object) $_POST;

					// saving the information
					if(isset($postData->attendantPerformance)) {
						$posClass->updateData(
							"settings",
							"reports_sales_attendant='".xss_clean($postData->attendantPerformance)."'",
							"clientId='{$loggedUserClientId}'"
						);
					}
				}

			}

			// load payment options of this client
			elseif(isset($_POST['loadPaymentOptions']) && confirm_url_id(2, "loadPaymentOptions")) {
				// fetch the payment options of this client
				$paymentOptions = $clientData->payment_options;
				$message = [];
				// confirm that the record is not empty
				if(!empty($paymentOptions)) {

					// explode the content with the comma
					$opts = explode(',', $paymentOptions);
					
					// using the foreach loop to fetch the records
					foreach ($opts as $eachOption) {
						// append to the list of payment options
						$message[] = $eachOption;
					}

					$status = 200;
				}
			}

			// update the payment options of the client
			elseif(isset($_POST['updatePaymentOptions'], $_POST['Option'], $_POST['Value']) && confirm_url_id(2, 'updatePaymentOptions')) {
				
				// check if the user has permissions to perform this action
				if($accessObject->hasAccess('update', 'settings')) {
					
					// assign variables to the data that have been parsed
					$branchData = (Object) $_POST;

					// fetch the payment options of this client
					$paymentOptions = $clientData->payment_options;
					$branchData->Value = $branchData->Value;

					// confirm that the record is not empty
					if(!empty($paymentOptions)) {

						// begin the options
						$options = [];

						// explode the content with the comma
						$opts = explode(',', $paymentOptions);
						
						// using the foreach loop to fetch the records
						foreach ($opts as $eachOption) {
							// append to the list of payment options
							$options[] = $eachOption;
						}

						// if the module parsed is in the array and the current value is unchecked
						if(in_array($branchData->Option, $options) && ($branchData->Value == 'unchecked')) {
							// search item in the array
							if (($key = array_search($branchData->Option, $options)) !== false) {
							    unset($options[$key]);
							}
						} elseif(!in_array($branchData->Option, $options) && ($branchData->Value == 'checked')) {
							// if not then push the new module into the list
							array_push($options, $branchData->Option);
						}

					} else {
						// if it is empty then start a new module list
						$options = [$branchData->Option];
					}

					// update the information
					$posClass->updateData(
						"settings",
						"payment_options='".implode(",", $options)."'",
						"clientId='{$loggedUserClientId}'"
					);

					// Record user activity
					$posClass->userLogs('settings', $loggedUserClientId, 'Updated the payment options of the Company.');

					$message = $options;

				}

			}

			$response->message = $message;
			$response->status = $status;
		}

		//: inventory management
		elseif(confirm_url_id(1, "inventoryManagement")) {

			// create object for report
			$inventoryObject = load_class('Inventory', 'controllers');

			// set some additional variables
			$inventoryObject->accessObject = $accessObject;
			$inventoryObject->insightRequest = $insightRequest;
			$inventoryObject->apiAccessValues = $apiAccessValues;
			
			// process the request
			$response = $inventoryObject->{confirm_url_id(1)}($clientData, confirm_url_id(2));
			
		}

		//: user management
		elseif(confirm_url_id(1, "userManagement")) {

			//: initializing
			$message = "Error Processing Request";
			$status = false;

			// create object for report
			$usersObject = load_class('Users', 'controllers');

			// set some additional variables
			$usersObject->accessObject = $accessObject;
			$usersObject->apiAccessValues = $apiAccessValues;
			
			// process the request
			$data = $usersObject->{confirm_url_id(1)}($clientData, confirm_url_id(2));

			//: set the response to return
			$response = [
				"message"	=> $data['message'],
				"status"	=> $data['status']
			];
		}

		//: customers management
		elseif (confirm_url_id(1, "customerManagement")) {
			
			

		}

		//: delete data
		elseif(isset($_POST['itemToDelete'], $_POST['itemId']) and confirm_url_id(1, 'deleteData')) {
			// confirm if an id was parsed
			$itemId = (isset($_POST['itemId'])) ? xss_clean($_POST["itemId"]) : null;
			
			// Record user activity
			$posClass->userLogs('requests', $itemId, 'Deleted the user request from the System.');

			$process = $pos->prepare("UPDATE requests SET deleted = ? WHERE request_id = ?");
			$process->execute([1, $itemId]);

			if($process) {
				$status = true;
				$message = 'Record was successfully deleted.';
			}

			$response = array(
				"status" => $status,
				"message" => $message,
				"request" => 'deleteItem',
				"itemId" => $itemId,
				"thisRequest" => xss_clean($_POST['itemToDelete']),
				"tableName" => xss_clean($_POST['itemToDelete']).'sList'
			);
			
		}

		//: Products category management
		elseif(confirm_url_id(1, "categoryManagement")) {

			//: list categories
			if(isset($_POST["listProductCategories"]) && confirm_url_id(2, "listProductCategories")) {
				//: run the query
				$i = 0;
	            # list categories
	            $categoryList = $posClass->getAllRows("products_categories a", "a.*, (SELECT COUNT(*) FROM products b WHERE a.category_id = b.category_id) AS products_count", "a.clientId='{$loggedUserClientId}' LIMIT {$limit}");

	            $categories = [];
	            // loop through the branches list
	            foreach($categoryList as $eachCategory) {
	            	$i++;
	            	
	            	if($rawJSON) {
	            		unset($eachCategory->id);
	            		$categories[] = $eachCategory;
	            	} else {
		            	$eachCategory->row = $i;
		            	$eachCategory->action = "";

		            	if($accessObject->hasAccess('category_update', 'products')) {

		            		$eachCategory->action .= "<a data-content='".json_encode($eachCategory)."' href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-primary edit-category\" data-id=\"{$eachCategory->id}\"><i class=\"fa fa-edit\"></i></a>";
		            	}
		            	
		            	if($accessObject->hasAccess('category_delete', 'products')) {
		            		$eachCategory->action .= "<a href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-danger delete-item\" data-msg=\"Are you sure you want to delete this Product Category?\" data-request=\"category\" data-url=\"{$config->base_url('api/categoryManagement/deleteCategory')}\" data-id=\"{$eachCategory->id}\"><i class=\"fa fa-trash\"></i></a>";
		            	}

		            	if(empty($eachCategory->action)) {
		            		$eachCategory->action = "---";
		            	}
		            	$categories[] = $eachCategory;
		            }	                
	           	}

	           	$response = [
	           		'status' => 200,
	           		'result' => $categories
	           	];
			}

			elseif(isset($_POST["name"], $_POST["dataset"]) && confirm_url_id(2, 'saveCategory')) {
				$postData = (Object) array_map("xss_clean", $_POST);

				if(empty($postData->name)) {
					$response->message = "Category name cannot be empty";
				} else {
					if($postData->dataset == "update") {
						$query = $pos->prepare("UPDATE products_categories SET category = '{$postData->name}' WHERE id='{$postData->id}' AND clientId='{$loggedUserClientId}'");

						if($query->execute()) {
							$response->status = 200;
							$response->message = "Product category was updated";
							$response->href = $config->base_url('settings/prd');
						}
					}
					elseif($postData->dataset == "add") {
						
						$itemId = "PC".$posClass->orderIdFormat($clientData->id.$posClass->lastRowId('products_categories'));

						// execute the statement
						$query = $pos->prepare("
							INSERT INTO products_categories 
							SET category = '{$postData->name}', 
							category_id='{$itemId}', clientId='{$loggedUserClientId}'
						");

						// if it was successfully executed
						if($query->execute()) {
							$response->status = 200;
							$response->message = "Product category was inserted";
							$response->href = $config->base_url('settings/prd');

							// Record user activity
							$posClass->userLogs('product-type', $itemId, 'Added a new product category into the system.');
						}
					}
				}

			}

			elseif(isset($_POST["itemId"], $_POST["itemToDelete"]) && confirm_url_id(2, 'deleteCategory')) {
				$postData = (Object) array_map("xss_clean", $_POST);

				if(empty($postData->itemId)) {
					$response->message = "Error processing request";
				} else {

					// delete the product type from the system
					$query = $pos->prepare("DELETE FROM products_categories WHERE id='{$postData->itemId}' AND clientId='{$loggedUserClientId}'");
					
					// if it was successfully executed
					if($query->execute()) {
						// set the response data
						$response->reload = true;
						$response->status = true;
						$response->href = $config->base_url('settings/prd');
						$response->message = "Product category successfully deleted";
						
						// Record user activity
						$posClass->userLogs('product-type', $postData->itemId, 'Deleted the Product Type from the system.');
					}
				}
			}

		}

		//: Import manager
		elseif(confirm_url_id(1, "importManager")) {

			// create a new object for the access level
			if($accessObject->hasAccess('view', 'settings')) {
				
				// set the valid requests that can be made
				$validRequests = [
				    "customer" => [
				        "fa fa-users",
				        "Import Customers List in Bulk"
				    ],
				    "product" => [
				        "fa fa-shopping-cart",
				        "Import Products List in Bulk"
				    ],
				    "user" => [
				        "fa fa-user",
				        "Import Admin Users into the Database"
				    ]
				];
					
				// assign the variable
			    $currentData = (isset($SITEURL[3])) ? strtolower($SITEURL[3]) : strtolower(xss_clean($SITEURL[2]));
			    $branchId = $session->useBranchId;

			    // columns to use for the query
		        if($currentData == "customer") {
		            // accepted column names
		            $acpCols = [
		                "customer_id"=>"Customer Code", "title"=>"Title", "firstname"=>"Firstname", "gender"=>"Gender", "lastname"=>"Lastname", 
		                "phone_1"=>"Contact Number", "email"=>"Email Address", 
		                "date_of_birth"=>"Date of Birth", "residence"=>"Residence", "city"=>"City"
		            ];
		        } elseif($currentData == "product") {
		            // accepted column names for the products
		            $acpCols = [
		            	"product_id" => "Product Code", 
		                "category_id" => "Product Category", "product_title" => "Product Title", 
		                "product_description" => "Product Description",
		                "product_price" => "Retail Price", "cost_price" => "Product Cost Price",
		                "threshold" => "Product Threshold", "quantity" => "Product Quantity"
		            ];
		        } elseif($currentData == "user") {
		            // accepted column names for the products
		            $acpCols = [
		                "user_id" => "User ID", "name" => "Fullname", 
		                "gender" => "Gender", "email" => "Email Address",
		                "phone" => "Contact Number", "username" => "username",
		                "password" => "Password"
		            ];
		        }

		        // set the branch id in session
		        if(isset($_POST["setBranchId"], $_POST["curBranchId"]) && confirm_url_id(2, 'setBranchId')) {
		            // set the branch id in session
		            $session->curBranchId = (int) $_POST["curBranchId"];

		            // parse success response
		            $response->status = 200;
		            $response->result = "Branch successfully set";
		        }

		        // if there is any file uploaded
		        elseif(isset($_FILES['csv_file']) && !empty($_FILES['csv_file']) && confirm_url_id(2, 'loadCSV')) {

		            // reading tmp_file name
		            $fileData = fopen($_FILES['csv_file']['tmp_name'], 'r');

		            // get the content of the file
		            $column = fgetcsv($fileData);
		            $csvData = array();
		            $csvSessionData = array();
		            $error = false;
		            $i = 0;

		            //using while loop to get the information
		            while($row = fgetcsv($fileData)) {
		            	// session data
		            	$csvSessionData[] = $row;

		                // push the data parsed by the user to the page
		                if($i < 10)  {
		                	$csvData[] = $row;
		                }
		                // increment
		                $i++;
		            }
		            // set the content in a session
		            $session->set_userdata('csvSessionData', $csvSessionData);

		            // set the data to send finally
		            $response = array(
		                'column'	=> $column,
		                'csvData'	=>  $csvData,
		                'data_count' => count($csvSessionData)
		            );
		        }

		        // form content has been submitted
		        elseif(isset($_POST["csvKey"], $_POST["csvValues"], $_POST["uploadCSVData"]) && confirm_url_id(2, "uploadCSVData")) {

		            // initializing
					$response = (Object) [
						'status' => "error",
						'result' => "Unknown request parsed"
					];

		            // begin processing
		            $response->result = "Error processing request.";
		            
		            // confirm that the keys are not empty
		            if(!empty($_POST["csvKey"]) and is_array($_POST["csvKey"])) {
		                // not found
		                $notFound = 0;

		                // check if the keys are all valid
		                foreach($_POST["csvKey"] as $thisKey) {
		                    if(!in_array($thisKey, array_values($acpCols))) {
		                        $notFound++;
		                    }
		                }

		                // check if the branch id session is not empty
		                if(empty($session->curBranchId)) {
		                	// break the code if an error was found
		                    $response->result = 'Please select a Branch to continue.';
		                    $response->trigger= 'importModal';
		                }
		                // count the number of columns parsed to the accepted 
		                elseif(count($_POST["csvKey"]) > count(array_keys($acpCols))) {
		                    // break the code if an error was found
		                    $response->result = 'Required columns exceeded. Please confirm and try.';
		                } elseif($notFound) {
		                    // break the code if an error was found
		                    $response->result = 'Invalid column parsed. Please confirm all columns match.';
		                } else {
		                    // start at zero
		                    $i = 0;
		                    $unqKey = '';
		                	$unqData = '';

		                    // other configuration for missing unique ids
			                //: search if customer code was not parsed then set it
			            	if(($currentData == "customer") && (!in_array("Customer Code", $_POST["csvKey"]))) {
			            		// append the customer_id column and value
			            		$unqKey = "`customer_id`,";
			            	} elseif(($currentData == "product") && (!in_array("Product Code", $_POST["csvKey"]))) {
			            		// append the product_id column and value
			            		$unqKey = "`product_id`,";
			            	} elseif(($currentData == "user") && (!in_array("User ID", $_POST["csvKey"]))) {
			            		// append the user_id column and value
			            		$unqKey = "`user_id`,";
			            	}

			                // begin the processing of the array data
			            	$sqlQuery = "INSERT INTO {$currentData}s (`clientId`,`branchId`, {$unqKey}";
		                    
		                    // continue processing the request
		                    foreach($_POST["csvKey"] as $thisKey) {
		                        // increment
		                        $i++;
		                        // append to the sql query
		                        $sqlQuery .= "`".array_search(xss_clean($thisKey), $acpCols)."`";
		                        // append a comma if the loop hasn't ended yet
		                        if($i < count($_POST["csvKey"])) $sqlQuery .= ",";
		                    }
		                    // append the last bracket
		                    $sqlQuery .= ") VALUES";

		                    $newCSVArray = [];
		                    // set the values
		                    if(!empty($_POST["csvValues"]) and is_array($_POST["csvValues"])) {
		                        // begin
		                        $iv = 0;

		                        // loop through the values list
		                        foreach($_POST["csvValues"] as $key => $eachCsvValue) {
		                            // print each csv value
		                            foreach($eachCsvValue as $eKey => $eValue) {
		                                $newCSVArray[$eKey][] = $eachCsvValue[$eKey];
		                            }
		                        }

		                        $newCSVArray = [];
		                        foreach($session->csvSessionData as $key => $eachCsvValue) {
		                            $newCSVArray[$key] = $eachCsvValue;
		                        }
		                    }

		                    // run this section if the new array is not empty
		                    if(!empty($newCSVArray)) {

		                        // loop through each array dataset
		                        foreach($newCSVArray as $eachData) {

		                        	//: search if customer code was not parsed then set it
		                            if(($currentData == "customer") && (!in_array("Customer Code", $_POST["csvKey"]))) {
		                                // append the customer_id column and value
		                                $unqData = "'".random_string('nozero', 12)."',";
		                            } elseif(($currentData == "product") && (!in_array("Product Code", $_POST["csvKey"]))) {
		                            	// generate the product code
		                            	$productId = $posClass->orderIdFormat($clientData->id.random_string('nozero', 4), 8);
		                                // append the product_id column and value
		                                $unqData = "'".$productId."',";
		                            } elseif(($currentData == "user") && (!in_array("User ID", $_POST["csvKey"]))) {
		                                // append the user_id column and value
		                                $unqData = "'".random_string('alnum', 15)."',";
		                            }

		                            // initializing
		                            $sqlQuery .= "('{$loggedUserClientId}','{$session->curBranchId}',{$unqData}";
		                            $ik = 0;
		                            // loop through each data
		                            foreach($eachData as $eachKey => $eachValue) {
		                                $ik++;
		                                // create sql string for the values
		                                $sqlQuery .= "'".xss_clean($eachValue)."'";

		                                if($ik < count($_POST["csvKey"])) $sqlQuery .= ",";
		                            }
		                            // end
		                            $sqlQuery .= "),";
		                        }

		                        $sqlQuery = substr($sqlQuery, 0, -1) . ';';

		                        // execute the sql statement
		                        $query = $pos->prepare($sqlQuery);

		                        // confirm that the query was successful
		                        if($query->execute()) {
		                            // set the status to true
		                            $session->csvSessionData = null;
		                            $session->curBranchId = null;
		                            $response->result = $currentData;
		                            $response->status = "success";
		                            $response->message = ucfirst($currentData)."s data was successfully imported.";
		                        }
		                    }
		                }
		            }
		        }

			}

		}

		//: Return product
		elseif(confirm_url_id(1, "returnOrderProcessor")) {
			
			//: search for product
			if(confirm_url_id(2, 'searchOrder')) {
				
				//: order id
				$orderId = xss_clean($_POST["orderId"]);

				//: create a new object
				$orderObj = load_class('Orders', 'controllers');

				//: load the data
				$data = $orderObj->saleDetails($orderId);

				$response = [
					'orderId' => $orderId,
					'orderDetails' => $data,
					'count' => count($data)
				];

			}

		}

		//: Notification handler
		elseif(confirm_url_id(1, "notificationHandler")) {

			//: enter this yard
			if(isset($_POST["unqID"], $_POST["noteType"]) && confirm_url_id(2, "activeNotice")) {

				//: unique id variable
				$uniqueId = xss_clean($_POST["unqID"]);
				$noteType = xss_clean($_POST["noteType"]);

				//: validate the notification id
				if($session->notificationId == $uniqueId) {
			        
			        //: notification loaders
					$notify = load_class('Notifications', 'controllers');
					$request = $notify->setUserSeen($clientData->id, $uniqueId, $noteType);

			        //: return a status of success
			        if($request) {
				        $response->status = "success";
				        $response->message = "Initializing Notification Seen.";
				   	}
				}
			}

		}

		//: Expenses Management
		elseif(confirm_url_id(1, "expensesManagement")) {

			//: list categories
			if(isset($_POST["listExpenseCategories"]) && confirm_url_id(2, "listExpenseCategories")) {
				//: run the query
				$i = 0;
	            # list categories
	            $categoryList = $posClass->getAllRows("expenses_category a", "a.*", "a.clientId='{$loggedUserClientId}' AND status='1' LIMIT {$limit}");

	            $categories = [];
	            // loop through the branches list
	            foreach($categoryList as $eachCategory) {
	            	$i++;

	            	if($rawJSON) {
	            		unset($eachCategory->id);
	            		$categories[] = $eachCategory;
	            	} else {	            	
		            	$eachCategory->row = $i;
		            	$eachCategory->action = "";

		            	if($accessObject->hasAccess('category_update', 'expenses')) {

		            		$eachCategory->action .= "<a data-content='".json_encode($eachCategory)."' href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-primary edit-category\" data-id=\"{$eachCategory->id}\"><i class=\"fa fa-edit\"></i></a>";
		            	}
		            	
		            	if($accessObject->hasAccess('category_delete', 'expenses')) {
		            		$eachCategory->action .= "<a href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-danger delete-item\" data-msg=\"Are you sure you want to delete this Expense Category?\" data-request=\"category\" data-url=\"{$config->base_url('api/expensesManagement/deleteCategory')}\" data-id=\"{$eachCategory->id}\"><i class=\"fa fa-trash\"></i></a>";
		            	}

		            	if(empty($eachCategory->action)) {
		            		$eachCategory->action = "---";
		            	}

		            	$eachCategory->description = limit_words($eachCategory->description, 10)."...";

		                $categories[] = $eachCategory;
		           	}

		           	$response = [
		           		'status' => 200,
		           		'result' => $categories
		           	];
		        }
			}

			elseif(isset($_POST["name"], $_POST["dataset"], $_POST["description"]) && confirm_url_id(2, 'saveCategory')) {
				$postData = (Object) array_map("xss_clean", $_POST);

				if(empty($postData->name)) {
					$response->message = "Category name cannot be empty";
				} else {
					// update the category
					if($postData->dataset == "update") {

						// save the previous data set
						$prevData = $posClass->getAllRows("expenses_category", "*", "clientId='{$loggedUserClientId}' AND id='{$postData->id}'")[0];

						/* Record the initial data before updating the record */
						$posClass->dataMonitoring('expenses-category', $postData->id, json_encode($prevData));

						// update the expense category details
						$query = $pos->prepare("
							UPDATE expenses_category 
							SET name = '{$postData->name}', 
								description='".nl2br($postData->description)."' 
								WHERE id='{$postData->id}' AND clientId='{$loggedUserClientId}'
						");

						// execute the statement
						if($query->execute()) {
							// parse the reponse
							$response->status = 200;
							$response->message = "Expense category was updated";

							// Record user activity
							$posClass->userLogs('expenses-category', $postData->id, 'Updated the expense category into the system.');
						}
					}
					elseif($postData->dataset == "add") {
						
						// execute the statement
						$query = $pos->prepare("
							INSERT INTO expenses_category 
							SET name = '{$postData->name}', 
								clientId='{$loggedUserClientId}', 
								description='".nl2br($postData->description)."'
						");

						// if it was successfully executed
						if($query->execute()) {
							$response->status = 200;
							$response->message = "Product category was inserted";

							$itemId = $posClass->lastRowId('expenses_category');

							// Record user activity
							$posClass->userLogs('expenses-category', $itemId, 'Added a new expense category into the system.');
						}
					}
				}

			}

			elseif(isset($_POST["itemId"], $_POST["itemToDelete"]) && confirm_url_id(2, 'deleteCategory')) {
				$postData = (Object) array_map("xss_clean", $_POST);

				if(empty($postData->itemId)) {
					$response->message = "Error processing request";
				} else {

					// delete the product type from the system
					$query = $pos->prepare("UPDATE expenses_category SET status='0' WHERE id='{$postData->itemId}' AND clientId='{$loggedUserClientId}'");
					
					// if it was successfully executed
					if($query->execute()) {
						// set the response data
						$response->reload = true;
						$response->status = true;
						$response->message = "Expenses Category successfully deleted";
						
						// Record user activity
						$posClass->userLogs('expenses-category', $postData->itemId, 'Deleted the Expenses Category from the system.');
					}
				}
			}

			//: main expenses data
			elseif(isset($_POST["listExpenses"]) && confirm_url_id(2, "listExpenses")) {
				//: run the query
				$i = 0;

	            # list expenses
	            $expensesList = $posClass->getAllRows("
	            	expenses a 
	            	LEFT JOIN expenses_category b ON b.id = a.category_id 
	            	LEFT JOIN branches c ON c.id = a.branchId
	            	LEFT JOIN users d ON d.user_id = a.created_by
	            	", 
	            	"a.*, c.branch_name, c.branch_contact, b.name AS category, d.name AS created_by", 
	            	"a.clientId='{$loggedUserClientId}' AND a.status='1' {$branchAccess}  LIMIT {$limit}"
	        	);

	            $expenses = [];
	            $totals = 0;
	            $tax = 0;
	            // loop through the branches list
	            foreach($expensesList as $eachExpense) {
					
					$totals += $eachExpense->amount;
		            $tax += $eachExpense->tax;
		            
		            if($rawJSON) {
		            	unset($eachExpense->id);
		            	$expenses[] = $eachExpense;
		            } else {
		            	$i++;

		            	$eachExpense->row = $i;
		            	$eachExpense->action = "";

		            	if($accessObject->hasAccess('expenses_update', 'expenses')) {

		            		$eachExpense->action .= "<a data-content='".json_encode($eachExpense)."' href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-primary edit-expense\" data-id=\"{$eachExpense->id}\"><i class=\"fa fa-edit\"></i></a>";
		            	}
		            	
		            	if($accessObject->hasAccess('expenses_delete', 'expenses')) {
		            		$eachExpense->action .= "<a href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-danger delete-item\" data-msg=\"Are you sure you want to delete this Expense?\" data-request=\"expense\" data-url=\"{$config->base_url('api/expensesManagement/deleteExpense')}\" data-id=\"{$eachExpense->id}\"><i class=\"fa fa-trash\"></i></a>";
		            	}

		            	if(empty($eachExpense->action)) {
		            		$eachExpense->action = "---";
		            	}

		            	$eachExpense->description = limit_words($eachExpense->description, 10)."...";

		            	$expenses[] = $eachExpense;
		            }
	           	}

	           	$response = [
	           		'status' => 200,
	           		'result' => [
	           			'list' => $expenses,
	           			'summary' => [
	           				'tax' => $tax,
	           				'total' => $totals
	           			]
	           		]
	           	];
			}

			//: process the expense
			elseif(isset($_POST["expenseId"], $_POST["date"], $_POST["category"], $_POST["amount"], $_POST["tax"]) && confirm_url_id(2, 'manageExpenses')) {

				//: assign and clean the variables parsed
				$postData = (Object) array_map('xss_clean', $_POST);

				//: run some validations
				if(isset($postData->outletId) && ($postData->outletId == 'null')){
					$response->result = "Please select an outlet for this expense.";
				} elseif(strlen($postData->date) != 10) {
					$response->result = "Select a correct date.";
				} elseif($postData->category == 'null') {
					$response->result = "Category cannot be empty.";
				} elseif(!preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $postData->amount)) {
					$response->result = "Please enter a valid Amount. (Should contain at most 2 decimal places)";
				} elseif(!empty($postData->tax) && !preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $postData->tax)) {
					$response->result = "Please enter a valid Tax Value. (Should contain at most 2 decimal places)";
				} else {

					//: continue processing
					$postData->outletId = (isset($postData->outletId)) ? $postData->outletId : $loggedUserBranchId;

					//: predefined user request
					$postData->userRequest = 'addExpense';
					$postData->payment_type = 'cash';

					//: confirm if a valid expense id was parsed
					if(!empty($postData->expenseId)) {
						// run the query
						$validExpense = $posClass->getAllRows("expenses", "COUNT(*) AS num_rows", "clientId='{$loggedUserClientId}' AND id='{$postData->expenseId}'")[0];
						// check if it was found
						if(!empty($validExpense) && ($validExpense->num_rows == 1)) {
							// set the request to be to update the record
							$postData->userRequest = 'updateExpense';
						}
					}

					//: confirm that the user has the required permissions
					if(($accessObject->hasAccess('expenses_add', 'expenses') && $postData->userRequest == 'addExpense') || ($accessObject->hasAccess('expenses_update', 'expenses') && $postData->userRequest == 'updateExpense')) {
						
						//: create a new object of the expenses class
						$expensesObject = load_class('Expenses', 'controllers');
						$request = $expensesObject->pushExpense($postData);

						// if the request was successful
						if($request) {
							
							//: add the activity log
							if($postData->userRequest == 'addExpense') {
								//: get the expense id
								$itemId = $posClass->lastRowId('expenses');
								//: log the activity
								$posClass->userLogs('expenses', $itemId, 'Added a new Expense from the system.');

								$response->clearform = true;
							} else {

								//: log the user activity
								$posClass->userLogs('expenses', $postData->expenseId, 'Updated the expense details already recorded.');

								$response->clearform = false;
							}

							// parse the success response
							$response->status = 'success';
							$response->result = 'Expense was successfully recorded';
						}

					}

				}

			}

			elseif(isset($_POST["itemId"], $_POST["itemToDelete"]) && confirm_url_id(2, 'deleteExpense')) {
				$postData = (Object) array_map("xss_clean", $_POST);

				if(empty($postData->itemId)) {
					$response->message = "Error processing request";
				} else {

					// delete the product type from the system
					$query = $pos->prepare("UPDATE expenses SET status='0' WHERE id='{$postData->itemId}' AND clientId='{$loggedUserClientId}'");
					
					// if it was successfully executed
					if($query->execute()) {
						// set the response data
						$response->reload = true;
						$response->status = true;
						$response->message = "Expenses successfully deleted";
						
						// Record user activity
						$posClass->userLogs('expenses', $postData->itemId, 'Deleted the Expenses Category from the system.');
					}
				}
			}

		}

	} else {
		$response->message = "Sorry! Your Account has expired, hence cannot perform the requested action";
	}

	// convert the response to an object to allow extension
	$response = !empty($response) ? (object) $response : (object)[];

	if(!empty($insightRequest)) {
		$response->metrics = $insightRequest;
	}

	// unset unwanted items from the response
	if(empty($response->message)) {
		unset($response->message);
	}

	if($expiredAccount) {
		$response->state = 'Account Expired: Showing Limited Data.';
	}

	$response->requestUri = $_SERVER["REQUEST_URI"];
	
}

echo json_encode($response);