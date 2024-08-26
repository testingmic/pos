<?php

class Imports extends Pos {

    public $apiAccessValues;
    public $insightRequest;
    public $accessObject;

    /**
     * Data Import Management
     * 
     * @param object $clientData
     * 
     * @return object|array
     */
    public function importManager($clientData, $requestInfo, $setupInfo = []) {


        global $config;

        // set the logged in user id
        $loggedUserClientId = isset($this->apiAccessValues->clientId) ? xss_clean($this->apiAccessValues->clientId) : $this->session->clientId;

        //: initializing
        $response = (object) [
            "status" => "error", 
            "message" => "Error Processing The Request"
        ];

        // create a new object for the access level
        if($this->accessObject->hasAccess('view', 'settings')) {
            
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
            $currentData = (isset($SITEURL[3])) ? strtolower($SITEURL[3]) : strtolower($requestInfo);

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
            if(isset($_POST["setBranchId"], $_POST["curBranchId"]) && $requestInfo === 'setBranchId') {
                // set the branch id in session
                $this->session->curBranchId = (int) $_POST["curBranchId"];

                // parse success response
                $response->status = 200;
                $response->result = "Branch successfully set";
            }

            // if there is any file uploaded
            elseif(isset($_FILES['csv_file']) && !empty($_FILES['csv_file']) && $requestInfo === 'loadCSV') {

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
                $this->session->set_userdata('csvSessionData', $csvSessionData);

                // set the data to send finally
                $response = array(
                    'column'	=> $column,
                    'csvData'	=>  $csvData,
                    'data_count' => count($csvSessionData)
                );
            }

            // form content has been submitted
            elseif(isset($_POST["csvKey"], $_POST["csvValues"], $_POST["uploadCSVData"]) && $requestInfo === "uploadCSVData") {

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
                            foreach($this->session->csvSessionData as $key => $eachCsvValue) {
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
                                    $productId = $this->orderIdFormat($clientData->id.random_string('nozero', 4), 8);
                                    // append the product_id column and value
                                    $unqData = "'".$productId."',";
                                } elseif(($currentData == "user") && (!in_array("User ID", $_POST["csvKey"]))) {
                                    // append the user_id column and value
                                    $unqData = "'".random_string('alnum', 15)."',";
                                }

                                // initializing
                                $sqlQuery .= "('{$loggedUserClientId}','{$this->session->curBranchId}',{$unqData}";
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
                            $query = $this->db->prepare($sqlQuery);

                            // confirm that the query was successful
                            if($query->execute()) {
                                // set the status to true
                                $this->session->csvSessionData = null;
                                $this->session->curBranchId = null;
                                $response->result = $currentData;
                                $response->status = "success";
                                $response->message = ucfirst($currentData)."s data was successfully imported.";
                            }
                        }
                        
                    }

                }
                
            }

        }

        return json_decode(json_encode($response), true);

    }


}
?>