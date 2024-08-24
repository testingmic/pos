<?php 

class Handler extends Pos {
    
    /**
     * Api Access Handler
     * 
     * @return bool|object
     */
    public function apiProcess($posClass, $apiAccessValues) {

        //: initializing
        $response = (object) [
            "status" => "error", 
            "message" => "Error Processing The Request"
        ];

        //: create a new object of the notification class
		$accountNotify = load_class('Notifications', 'controllers', $apiAccessValues->clientId);
		$posClass->clientId = $apiAccessValues->clientId;
		$theAccountState = $accountNotify->accountNotification()->hasExpired;

		// get all the parsed data
		$apiCallData = json_decode(file_get_contents('php://input'), true);

		// set the post as empty
		$_POST = [];
		$bugs = false;

		// confirm that an array was parsed as the payload
		if(!is_array($apiCallData)) {
			$bugs = true;
			$response->message = "Sorry! Please ensure that valid data has been parsed as payload.";
		} else {

			// loop through the list and set it as a post data
			foreach($apiCallData as $postKey => $postData) {
				// confirm that the postdata is not an array
				if(!is_array($postData)) {
					// add the user parsed data into the post super global array
					$_POST[$postKey] = xss_clean($postData);
				}
				// run this section if it is an array
				elseif(is_array($postData)) {
					// loop through the list
					foreach($postData as $hKey => $hValue) {
						// add the data to the array list
						$_POST[$postKey][$hKey] = $hValue;
					}
				}
			}

			// if the reportsanalytics endpoint was parsed
			if(confirm_url_id(1, "reportsAnalytics") && confirm_url_id(2, "generateReport")) {

				// confirm if query metric was not parsed
				if(!isset($_POST["queryMetric"])) {
					// set a bug
					$bugs = true;
					// set the message
					$response->message = "Sorry! The metric parsed is invalid. The metric can be one or all of the following: ".implode(", ", $this->availableQueryMetrics);
				} elseif((isset($_POST["queryMetric"]) && $_POST["queryMetric"] != "salesOverview") && isset($_POST["insightRequest"])) {
					// set a bug
					$bugs = true;
					// set the message
					$response->message = "Sorry! The 'insightRequest' parameter can only be used with the 'queryMetric' has the value 'salesOverview'";
				} else {
					// assign the request insight metric 
					$_POST["insightRequest"] = (isset($_POST["insightRequest"]) && !empty($_POST["insightRequest"])) ? $_POST["insightRequest"] : ["salesOverview"];

					// check if the insight is not an array
					if(!is_array($_POST["insightRequest"])) {
						// convert the string to an array
						$_POST["insightRequest"] = $posClass->stringToArray($_POST["insightRequest"]);
					}

					// check if an invalid metric was supplied
					if(isset($_POST["productsLimit"])) {
						// check if the insight metric contains product performance
						if(!in_array("productsPerformanceInsight", $_POST["insightRequest"])) {
							// set the bugs to true
							$bugs = true;
							// set the error message
							$response->message = "Sorry! The parameter 'productsLimit' should be parsed with the metric 'productsPerformanceInsight'";
						}
					}
				}
			}
		}

		// confirm that a bug was found
		if($bugs) {
			// print the error message
			return $response;
		}

        return true;

    }

}
?>