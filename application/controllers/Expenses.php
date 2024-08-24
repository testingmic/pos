<?php 

class Expenses extends Pos {

	public $accessObject;
	public $apiAccessValues;
	public $insightRequest;

	public function __construct() {
		parent::__construct();
	}

	/**
	 * Process the form submitted
	 * Check the request parsed
	 **/
	public function pushExpense(stdClass $postData) {

		if(!empty($postData) && isset($postData->userRequest)) {

			if($postData->userRequest == 'addExpense') {
				return $this->addExpense($postData);
			} elseif($postData->userRequest == 'updateExpense') {
				return $this->updateExpense($postData);
			}

		} else {
			return false;
		}
	}

	private function addExpense($postData) {
		try {

			$stmt = $this->pos->prepare("
				INSERT INTO expenses
				SET 
					clientId = ?, branchId = ?, category_id = ?,
					start_date = ?, amount = ?, tax = ?, description = ?,
					payment_type = ?, created_by = ?
			");
			return $stmt->execute([
				$this->clientId, $postData->outletId, $postData->category,
				$postData->date, $postData->amount, $postData->tax,
				nl2br($postData->description), $postData->payment_type,
				$this->session->userId
			]);

		} catch(PDOException $e) {
			return false;
		}
	}

	private function updateExpense($postData) {
		
		try {

			// save the previous data set
			$prevData = $this->getAllRows("expenses", "*", "clientId='{$this->clientId}' AND id='{$postData->expenseId}'")[0];

			/* Record the initial data before updating the record */
			$this->dataMonitoring('expenses', $postData->expenseId, json_encode($prevData));

			$stmt = $this->pos->prepare("
				UPDATE expenses
				SET 
					branchId = ?, category_id = ?,
					start_date = ?, amount = ?, tax = ?, description = ?,
					payment_type = ?
				WHERE clientId = ? AND id = ?
			");
			return $stmt->execute([
				$postData->outletId, $postData->category,
				$postData->date, $postData->amount, $postData->tax,
				nl2br($postData->description), $postData->payment_type,
				$this->clientId, $postData->expenseId
			]);

		} catch(PDOException $e) {
			return false;
		}
	}

	public function expensesManagement($clientData, $requestInfo, $setupInfo = []) {

		global $config;

		//: initializing
        $response = (object) ["status" => "error", "message" => "Error Processing The Request"];

		//: if the request is from an api request then push only json raw data
	    $rawJSON = isset($this->apiAccessValues->branchId) ? true : false;

		// set the limit
		$limit = (isset($_POST["limit"])) ? (int) $_POST["limit"] : $this->data_limit;

		// set the logged in user id
        $loggedUserClientId = isset($this->apiAccessValues->clientId) ? xss_clean($this->apiAccessValues->clientId) : $this->session->clientId;
		$loggedUserBranchId = (isset($apiAccessValues->branchId)) ? xss_clean($apiAccessValues->branchId) : $this->session->branchId;

		//: where clause for the user role
        $branchAccess = '';

        //: use the access level for limit contents that is displayed
        if(!$this->accessObject->hasAccess('monitoring', 'branches')) {
            $branchAccess = " AND a.branchId = '{$loggedUserBranchId}'";
        }

		//: list categories
		if(isset($_POST["listExpenseCategories"]) && $requestInfo === "listExpenseCategories") {
			//: run the query
			$i = 0;
			# list categories
			$categoryList = $this->getAllRows("expenses_category a", "a.*", "a.clientId='{$loggedUserClientId}' AND status='1' LIMIT {$limit}");

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

					if($this->accessObject->hasAccess('category_update', 'expenses')) {

						$eachCategory->action .= "<a data-content='".json_encode($eachCategory)."' href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-primary edit-category\" data-id=\"{$eachCategory->id}\"><i class=\"fa fa-edit\"></i></a>";
					}
					
					if($this->accessObject->hasAccess('category_delete', 'expenses')) {
						$eachCategory->action .= "<a href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-danger delete-item\" 
							data-msg=\"Are you sure you want to delete this Expense Category?\" data-request=\"category\" 
							data-url=\"{$config->base_url('api/expensesManagement/deleteCategory')}\" data-id=\"{$eachCategory->id}\"><i class=\"fa fa-trash\"></i></a>";
					}

					if(empty($eachCategory->action)) {
						$eachCategory->action = "---";
					}

					$eachCategory->description = limit_words($eachCategory->description, 10)."...";

					$categories[] = $eachCategory;
				}

				$response->status = true;
				$response->message = $categories;
			}
		}

		elseif(isset($_POST["name"], $_POST["dataset"], $_POST["description"]) && $requestInfo === 'saveCategory') {
			$postData = (Object) array_map("xss_clean", $_POST);

			if(empty($postData->name)) {
				$response->message = "Category name cannot be empty";
			} else {
				// update the category
				if($postData->dataset == "update") {

					// save the previous data set
					$prevData = $this->getAllRows("expenses_category", "*", "clientId='{$loggedUserClientId}' AND id='{$postData->id}'")[0];

					/* Record the initial data before updating the record */
					$this->dataMonitoring('expenses-category', $postData->id, json_encode($prevData));

					// update the expense category details
					$query = $this->db->prepare("
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
						$this->userLogs('expenses-category', $postData->id, 'Updated the expense category into the system.');
					}
				}
				elseif($postData->dataset == "add") {
					
					// execute the statement
					$query = $this->db->prepare("
						INSERT INTO expenses_category 
						SET name = '{$postData->name}', 
							clientId='{$loggedUserClientId}', 
							description='".nl2br($postData->description)."'
					");

					// if it was successfully executed
					if($query->execute()) {
						$response->status = 200;
						$response->message = "Product category was inserted";

						$itemId = $this->lastRowId('expenses_category');

						// Record user activity
						$this->userLogs('expenses-category', $itemId, 'Added a new expense category into the system.');
					}
				}
			}

		}

		elseif(isset($_POST["itemId"], $_POST["itemToDelete"]) && $requestInfo === 'deleteCategory') {
			$postData = (Object) array_map("xss_clean", $_POST);

			if(empty($postData->itemId)) {
				$response->message = "Error processing request";
			} else {

				// delete the product type from the system
				$query = $this->db->prepare("UPDATE expenses_category SET status='0' WHERE id='{$postData->itemId}' AND clientId='{$loggedUserClientId}'");
				
				// if it was successfully executed
				if($query->execute()) {
					// set the response data
					$response->reload = true;
					$response->status = true;
					$response->message = "Expenses Category successfully deleted";
					
					// Record user activity
					$this->userLogs('expenses-category', $postData->itemId, 'Deleted the Expenses Category from the system.');
				}
			}
		}

		//: main expenses data
		elseif(isset($_POST["listExpenses"]) && $requestInfo === "listExpenses") {
			//: run the query
			$i = 0;

			# list expenses
			$expensesList = $this->getAllRows("
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

					if($this->accessObject->hasAccess('expenses_update', 'expenses')) {

						$eachExpense->action .= "<a data-content='".json_encode($eachExpense)."' href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-primary edit-expense\" data-id=\"{$eachExpense->id}\"><i class=\"fa fa-edit\"></i></a>";
					}
					
					if($this->accessObject->hasAccess('expenses_delete', 'expenses')) {
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
				'list' => $expenses,
				'summary' => [
					'tax' => $tax,
					'total' => $totals
				]
			];
		}

		//: process the expense
		elseif(isset($_POST["expenseId"], $_POST["date"], $_POST["category"], $_POST["amount"], $_POST["tax"]) && $requestInfo === 'manageExpenses') {

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
					$validExpense = $this->getAllRows("expenses", "COUNT(*) AS num_rows", "clientId='{$loggedUserClientId}' AND id='{$postData->expenseId}'")[0];
					// check if it was found
					if(!empty($validExpense) && ($validExpense->num_rows == 1)) {
						// set the request to be to update the record
						$postData->userRequest = 'updateExpense';
					}
				}

				//: confirm that the user has the required permissions
				if(($this->accessObject->hasAccess('expenses_add', 'expenses') && $postData->userRequest == 'addExpense') || ($this->accessObject->hasAccess('expenses_update', 'expenses') && $postData->userRequest == 'updateExpense')) {
					
					//: create a new object of the expenses class
					$expensesObject = load_class('Expenses', 'controllers');
					$request = $expensesObject->pushExpense($postData);

					// if the request was successful
					if($request) {
						
						//: add the activity log
						if($postData->userRequest == 'addExpense') {
							//: get the expense id
							$itemId = $this->lastRowId('expenses');
							//: log the activity
							$this->userLogs('expenses', $itemId, 'Added a new Expense from the system.');

							$response->clearform = true;
						} else {

							//: log the user activity
							$this->userLogs('expenses', $postData->expenseId, 'Updated the expense details already recorded.');

							$response->clearform = false;
						}

						// parse the success response
						$response->status = 'success';
						$response->result = 'Expense was successfully recorded';
					}

				}

			}

		}

		// delete expenses record
		elseif(isset($_POST["itemId"], $_POST["itemToDelete"]) && $requestInfo === 'deleteExpense') {
			$postData = (Object) array_map("xss_clean", $_POST);

			if(empty($postData->itemId)) {
				$response->message = "Error processing request";
			} else {

				// delete the product type from the system
				$query = $this->db->prepare("UPDATE expenses SET status='0' WHERE id='{$postData->itemId}' AND clientId='{$loggedUserClientId}'");
				
				// if it was successfully executed
				if($query->execute()) {
					// set the response data
					$response->reload = true;
					$response->status = true;
					$response->message = "Expenses successfully deleted";
					
					// Record user activity
					$this->userLogs('expenses', $postData->itemId, 'Deleted the Expenses Category from the system.');
				}
			}
		}

		return json_decode(json_encode($response), true);

	}

}
?>