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

	// set the user data
	$userData = $posClass->userData;

	// set the combined request payload
	$combinedRequestPayload = [
		"inventoryManagement", "userManagement", "customerManagement", "branchManagment", "manageQuotes",
		"categoryManagement", "expensesManagement", "pointOfSaleProcessor", "returnOrderProcessor",
		"notificationHandler", "deleteData", "fetchCustomersOptionsList", "fetchPOSProductsList", "importManager"
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
	$clientData = $clientData[0] ?? [];
	$branchData = $branchData[0] ?? [];

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

		//: Process requests
		//: Submit to cart for processing
		if(isset($_POST['selectedProducts'], $_POST["request"], $_POST["customerId"], $_POST["discountType"], $_POST["discountAmt"]) && confirm_url_id(1, 'pushRequest')) {
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
				"manageQuotes" => "Quotes",
				"returnOrderProcessor" => "Handler",
				"notificationHandler" => "Handler",
				"deleteData" => "Handler",
				"importManager" => "Imports"
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

			if(is_array($requestData) && !empty($requestData['data'])) {
				$response['data'] = $requestData['data'];
			}

			if(!is_array($requestData) && isset($requestData->data)) {
				$response->data = $requestData->data;
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