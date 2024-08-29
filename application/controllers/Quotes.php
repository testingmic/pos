<?php 

class Quotes extends Pos {

    public $apiAccessValues;
    public $insightRequest;
    public $accessObject;

    /**
     * Process the quotes
     */
    public function manageQuotes($clientData, $requestInfo, $setupInfo) {
        
        // set the logged in user id
        $loggedUserClientId = isset($this->apiAccessValues->clientId) ? xss_clean($this->apiAccessValues->clientId) : $this->session->clientId;
        $loggedUserBranchId = (isset($apiAccessValues->branchId)) ? xss_clean($apiAccessValues->branchId) : $this->session->branchId;
        $loggedUserId = (isset($apiAccessValues->userId)) ? xss_clean($apiAccessValues->userId) : $this->session->userId;

        // set the limit
		$limit = (isset($_POST["limit"])) ? (int) $_POST["limit"] : $this->data_limit;

        // set global variables
        global $config;

        //: Quotes / Requests
		if(isset($_POST["listRequests"], $_POST["requestType"]) && $requestInfo === 'listRequests') {
            
			// assign variable to remove
			$postData = (Object) array_map('xss_clean', $_POST);

			//: if the request is from an api request then push only json raw data
			$rawJSON = isset($this->apiAccessValues->branchId) ? true : false;

			// check the access levels
			if($this->accessObject->hasAccess('view', 'branches')) {
				// list all quotes
				$accessLevelClause = "AND rq.branchId = '{$loggedUserBranchId}'"; 
			}

			// query the database
			$result = $this->getAllRows(
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

            // handle the user permission
            $quotesAccess = $this->accessObject->hasAccess('delete', strtolower('quotes'));
            $ordersAccess = $this->accessObject->hasAccess('delete', strtolower('orders'));

			// loop through the list of items
			foreach($result as $iReq) {
				// configure the
				$row++;

				if($rawJSON) {
					$iReq->itemLines = $ordersObj->requestDetails($iReq->request_id, $loggedUserClientId, $loggedUserBranchId, $loggedUserId);
					unset($iReq->id);
					$results[] = $iReq;
				} else {

					$iReq->action = "<div align=\"center\">";
		        
			        $iReq->action .= "<a class=\"btn btn-sm btn-outline-primary\" title=\"Export the {$iReq->request_type} to PDF\" data-value=\"{$iReq->request_id}\" href=\"".$config->base_url('export/'.$iReq->request_id)."\" target=\"_blank\"><i class=\"fa fa-file-pdf\"></i> </a> &nbsp;";

			        // check if the user has access to delete this item
			        if(($iReq->request_type === 'Order' && $ordersAccess) || ($iReq->request_type === 'Quote' && $quotesAccess)) {
			        	// print the delete button
			        	$iReq->action .= "<a class=\"btn btn-sm delete-item btn-outline-danger\" data-msg=\"Are you sure you want to delete this {$iReq->request_type}\" data-request=\"{$iReq->request_type}\" data-url=\"{$config->base_url('api/deleteData')}\" data-id=\"{$iReq->request_id}\" href=\"javascript:void(0)\"><i class=\"fa fa-trash\"></i> </a>";
			        }

			        $iReq->action .= "</div>";

					// append to the list of items
					$results[] = [
						'row_id' => $row,
						'request_type' => $iReq->request_type,
						'request_id' => "<a target=\"_blank\" class=\"text-success\" title=\"Click to view full details\" href=\"{$config->base_url('export/'.$iReq->request_id)}\">{$iReq->request_id}</a>",
						'branch_name' => $iReq->branch_name,
						'customer_name' => $iReq->customer_name,
						'quote_value' => "{$clientData->default_currency} ". number_format($iReq->request_sum, 2),
						'recorded_by' => $iReq->recorded_by,
						'request_date' => $iReq->request_date,
						'action' => $iReq->action
					];
				}
			}

			return [
				'status' => 200,
				'message' => $results,
				'data' => [
                    'rows_count' => count($result)
                ]
			];

		}

    }
}
?>