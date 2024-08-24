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
		
		$handlerObject = load_class('Handler', 'controllers');
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

	// set the combined request payload
	$combinedRequestPayload = [
		"inventoryManagement", "userManagement", "customerManagement", "branchManagment", 
		"categoryManagement", "expensesManagement", "pointOfSaleProcessor", "returnOrderProcessor",
		"notificationHandler", "deleteData", "fetchCustomersOptionsList", "fetchPOSProductsList"
	];

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

		//: Quotes / Requests
		if(isset($_POST["listRequests"], $_POST["requestType"]) && confirm_url_id(1, 'listRequests')) {
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

		//: inventory management
		elseif(in_array(confirm_url_id(1), $combinedRequestPayload)) {

			$classObject = [
				"pointOfSaleProcessor" =>  "Salespoint",
				"fetchPOSProductsList" => "Salespoint",
				"inventoryManagement" => "Inventory",
				"categoryManagement" => "Inventory",
				"customerManagement" => "Customers",
				"fetchCustomersOptionsList" => "Customers",
				"expensesManagement" => "Expenses",
				"branchManagment" => "Branches",
				"userManagement" => "Users",
				"returnOrderProcessor" => "Handler",
				"notificationHandler" => "Handler",
				"deleteData" => "Handler"
			];

			// create object for report
			$requestObject = load_class($classObject[confirm_url_id(1)], 'controllers');

			// set some additional variables
			$requestObject->accessObject = $accessObject;
			$requestObject->insightRequest = $insightRequest;
			$requestObject->apiAccessValues = $apiAccessValues;
			
			// process the request
			$requestData = $requestObject->{confirm_url_id(1)}($clientData, confirm_url_id(2), $setupInfo);

			if(is_array($requestData)) {
				//: set the response to return
				$response = [
					"message"	=> $requestData['message'] ?? $requestData,
					"status"	=> $requestData['status'] ?? true
				];
			}
			else {
				$response = $requestData;
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