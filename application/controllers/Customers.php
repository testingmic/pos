<?php
// ensure this file is being included by a parent file
if( !defined( 'SITE_URL' ) && !defined( 'SITE_DATE_FORMAT' ) ) die( 'Restricted access' );

class Customers extends Pos {

	public $apiAccessValues;
	public $accessObject;
	public $insightRequest;
	private $loggedUserBranchId;

	# Main PDO Connection Instance
	public function __construct($clientId = null){
		parent::__construct();

		$this->clientId = (!empty($clientId)) ? $clientId : $this->clientId;
	}

	public function fetch($columns = "*", $whereClause = null, $leftJoin = null, $limit = 100000){
		$sql = "SELECT $columns FROM customers a {$leftJoin} WHERE a.clientId = ? AND a.status = ? {$whereClause} ORDER BY a.id DESC LIMIT {$limit}";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([$this->clientId, 1]);
		return $stmt->fetchAll(PDO::FETCH_OBJ);	
	}

	public function quickAdd(stdClass $postData) {

		try {

			if(!empty($postData)) {

				// insert user organization details first
				$customer_id = (empty($postData->customer_id)) ? "POS".random_string('nozero', 12) : $postData->customer_id;
								
				$stmt = $this->db->prepare("INSERT INTO customers SET customer_id=?, firstname=?, lastname=?, phone_1=?, title=?, owner_id=?, email=?, branchId = ?, clientId = ?, phone_2 = ?, residence = ?, postal_address =?");
				
				if($stmt->execute([
					$customer_id, 
					$postData->nc_firstname, 
					$postData->nc_lastname, 
					$postData->nc_contact,
					$postData->nc_title, 
					$postData->userId,
					$postData->nc_email,
					$postData->branchId,
					$postData->clientId,
					(isset($postData->n_contact2)) ? $postData->n_contact2 : null,
					(isset($postData->residence)) ? $postData->residence : null,
					(isset($postData->postal_address)) ? $postData->postal_address : null
				])) {
					$this->userLogs('customer', $customer_id, 'Added a new Customer');
					return (object)[
						$customer_id, 
						$postData->nc_firstname, 
						$postData->nc_lastname,
						$postData->nc_email,
						$postData->nc_contact,
					];
				}
				return false;
			}

		} catch(PDOException $e) { return false; }
	}

	public function quickUpdate(stdClass $postData) {

		try {

			if(!empty($postData)) {

				// insert user organization details first				
				$stmt = $this->db->prepare("
					UPDATE customers SET residence = ?, firstname=?, lastname=?, phone_1=?, title=?, email=?, postal_address = ?, phone_2 = ?
					WHERE clientId = ? AND customer_id = ?
				");
				
				if($stmt->execute([
					$postData->residence, 
					$postData->nc_firstname,
					$postData->nc_lastname,
					$postData->nc_contact,
					$postData->nc_title, 
					$postData->nc_email,
					(isset($postData->postal_address)) ? $postData->postal_address : null,
					(isset($postData->n_contact2)) ? $postData->n_contact2 : null,
					$postData->clientId,
					$postData->customer_id
				])) {
					$this->userLogs('customer', $postData->customer_id, 'Updated the customer details');
					return true;
				}
				return false;
			}

		} catch(PDOException $e) { return false; }
	}

	public function customerManagement($clientData, $requestInfo, $setupInfo = null) {
		
		//: initializing
		$response = (object) [
			"status" => "error", 
			"message" => "Error Processing The Request"
		];

		// global config
		global $config;

		// handle the logged in branch id
        $this->loggedUserBranchId = (isset($this->apiAccessValues->branchId)) ? xss_clean($this->apiAccessValues->branchId) : $this->session->branchId;

		//: where clause for the user role
        $branchAccess = '';

        //: use the access level for limit contents that is displayed
        if(!$this->accessObject->hasAccess('monitoring', 'branches')) {
            $branchAccess = " AND a.branchId = '{$this->loggedUserBranchId}'";
        }

		// set the logged in user id
        $loggedUserClientId = isset($this->apiAccessValues->clientId) ? xss_clean($this->apiAccessValues->clientId) : $this->session->clientId;
        $loggedUserId = (isset($apiAccessValues->userId)) ? xss_clean($apiAccessValues->userId) : $this->session->userId;

		//: if the request is from an api request then push only json raw data
	    $rawJSON = isset($this->apiAccessValues->branchId) ? true : false;

		// set the limit
		$limit = (isset($_POST["limit"])) ? (int) $_POST["limit"] : $this->data_limit;
		
		//: begin the queries
		if(isset($_POST["listCustomers"]) && $requestInfo === "listCustomers") {
				
			//: query for the list of all customers
			$customersClass = load_class("Customers", "controllers", $loggedUserClientId);
			$customersList = $customersClass->fetch("a.id, a.title, a.customer_id, a.firstname, a.lastname, CONCAT(a.firstname, ' ', a.lastname) 
				AS fullname, a.preferred_payment_type, a.date_log, a.clientId, a.branchId, a.phone_1, a.phone_2, a.email, a.residence, a.postal_address, b.branch_name", 
				"AND a.customer_id != 'WalkIn' {$branchAccess}",
				"LEFT JOIN branches b ON b.id = a.branchId", $limit
			);

			// fetch the data
			$customers = [];
			$row_id = 0;

			// loop through the list
			foreach($customersList as $eachCustomer) {

				//: If the request is from the website
				if($rawJSON) {
					unset($eachCustomer->id);
					$customers[] = $eachCustomer;
				} else {
					$row_id++;
					// set the action button
					$eachCustomer->row_id = $row_id;
					$eachCustomer->action = "<div align=\"center\">";

					if($this->accessObject->hasAccess('update', 'customers')) {
						$eachCustomer->action .= "<a class=\"btn btn-sm edit-customer btn-outline-success\" title=\"Update Customer Details\" 
							data-value=\"{$eachCustomer->customer_id}\" href=\"javascript:void(0)\"><i class=\"fa fa-edit\"></i> </a>";
					} 

					$eachCustomer->action .= "&nbsp;<a href=\"{$config->base_url('customer-detail/'.$eachCustomer->customer_id)}\" 
						title=\"Click to list customer orders history\" data-value=\"{$eachCustomer->customer_id}\" 
						class=\"customer-orders btn btn-outline-primary btn-sm\" data-name=\"{$eachCustomer->fullname}\"><i class=\"fa fa-chart-bar\"></i></a>";

					if($this->accessObject->hasAccess('delete', 'customers')) {
						$eachCustomer->action .= "&nbsp;<a href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-danger delete-item\" 
						data-msg=\"Are you sure you want to delete this Customer?\" data-request=\"customer\" 
						data-url=\"{$config->base_url('api/customerManagement/deleteCustomer')}\" data-id=\"{$eachCustomer->id}\"><i class=\"fa fa-trash\"></i></a>";
					}
					$eachCustomer->action .= "</div>";

					$eachCustomer->fullname = "<a data-id=\"{$eachCustomer->customer_id}\" data-info='".json_encode($eachCustomer)."'>
						{$eachCustomer->title} {$eachCustomer->fullname}<br><span style='background-color: #9ba7ca;' class='badge badge-default'>
							{$eachCustomer->branch_name}</span></a>";

					//append to the list
					$customers[] = $eachCustomer;
				}
			}

			$response->status = true;
			$response->message = $customers;
		}

		//: update the customer information
		elseif(isset($_POST["nc_firstname"]) && $requestInfo === "manageCustomers") {
			//: update the information
			$postData = (Object) array_map('xss_clean', $_POST);

			// create a new object
			$customersObj = load_class("Customers", "controllers");

			// validate the user data
			if(empty(trim($postData->nc_firstname))){
				$response->message = "Please enter customer's name";
			} elseif(!empty($postData->nc_email) && !filter_var($postData->nc_email, FILTER_VALIDATE_EMAIL)) {
				$response->message = "Please enter a valid email address";
			} elseif(!empty($postData->nc_contact) && !preg_match("/^[0-9+]+$/", $postData->nc_contact)) {
				$response->message = "Please enter a valid contact number";
			}
			else{

				// set additioal variables
				$postData->userId = $loggedUserId;
				$postData->clientId = $loggedUserClientId;
				$postData->branchId = (isset($postData->branchId)) ? $postData->branchId : $this->loggedUserBranchId;
				$postData->nc_lastname = (isset($postData->nc_lastname)) ? $postData->nc_lastname : null;
				$postData->nc_title = (isset($postData->nc_title)) ? $postData->nc_title : null;
				$postData->residence = (isset($postData->residence)) ? $postData->residence : null;
				$postData->n_contact2 = (isset($postData->phone_2)) ? $postData->phone_2 : null;
				
				// update the customer information
				if($postData->request == "update-record") {

					// save the previous data set
					$prevData = $this->getAllRows("customers", "*", "clientId='{$loggedUserClientId}' AND customer_id='{$postData->customer_id}'");

					if(!empty($prevData)) {

						/* Record the initial data before updating the record */
						$this->dataMonitoring('customers', $postData->customer_id, json_encode($prevData[0]));

						// update the customer details
						$updateCustomer = $customersObj->quickUpdate($postData);

						// print success message
						if(!empty($updateCustomer)){
							$response->status = 200;
							$response->message = "Customer data successfully updated";
						}
					} else {
						$response->message = "Sorry! An invalid customer id was submitted";
					}
				} else {
					$prevData = null;

					// check the customer id
					if(isset($postData->customer_id)) {
						$prevData = $this->getAllRows("customers",
							"COUNT(*) as customerFound", "clientId='{$loggedUserClientId}' AND customer_id='{$postData->customer_id}'"
						)[0];
					}

					if(!empty($prevData) and ($prevData->customerFound != 0)) {
						$response->message = "Duplicate customer id has been parsed";
					} else {
						// add the customer information
						$addCustomer = $customersObj->quickAdd($postData);

						// print success message
						if(!empty($addCustomer)){
							$response->status = 200;
							$response->message = "Customer data successfully inserted";
						}
					}
				}
			}
		}

		elseif(isset($_POST["itemId"], $_POST["itemToDelete"]) && $requestInfo === 'deleteCustomer') {
			$postData = (Object) array_map("xss_clean", $_POST);

			if(empty($postData->itemId)) {
				$response->message = "Error processing request";
			} else {

				// update the customr status
				$query = $this->db->prepare("UPDATE customers SET status='0' WHERE id='{$postData->itemId}' AND clientId='{$loggedUserClientId}'");
				
				if($query->execute()) {
					
					$this->userLogs('customer', $postData->itemId, 'Deleted the customer details.');

					$response->status = true;
					$response->href = $config->base_url('customers');
					$response->message = "Customer successfully deleted";
				}
			}
		}

		return json_decode(json_encode($response), true);

	}
	
}