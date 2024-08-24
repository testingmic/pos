<?php 

class Branches extends Pos {

    public $apiAccessValues;
	public $accessObject;
	public $insightRequest;
	private $loggedUserBranchId;

    public function branchManagment($clientData, $requestInfo, $setupInfo = null) {

        //: initializing
		$response = (object) [
			"status" => "error", 
			"message" => "Error Processing The Request"
		];

        global $config;

        // set the logged in user id
        $loggedUserClientId = isset($this->apiAccessValues->clientId) ? xss_clean($this->apiAccessValues->clientId) : $this->session->clientId;
        
        //: if the request is from an api request then push only json raw data
	    $rawJSON = isset($this->apiAccessValues->branchId) ? true : false;
        
        // fetch the list of all branches
        if (isset($_POST['request'], $_POST['request']) and $requestInfo === "fetchBranchesLists") {

            $condition = "AND deleted=?";
            $message = [];

            // set the branch id to use
            $branchData = (Object) array_map("xss_clean", $_POST);

            // confirm the user permission to perform this action
            if($this->accessObject->hasAccess('view', 'branches')) {

                // append the request
                $response->request = "branchesList";

                // fetch the branch information
                $query = $this->db->prepare("
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
                            if($this->accessObject->hasAccess('update', 'branches')) {
                                $action .=  "<button class=\"btn btn-sm btn-outline-success edit-branch\" data-branch-id=\"{$data->branch_id}\">
                                    <i class=\"fa fa-edit\"></i>
                                </button> ";
                            }

                            if($this->accessObject->hasAccess('delete', 'branches')) {
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

        }
        
        // get the branch details
        elseif(isset($_POST['branchId'], $_POST['getBranchDetails']) && $requestInfo === "getBranchDetails") {

            $branchId = xss_clean($_POST['branchId']);

            // set the branch id to use
            $branchData = (Object) array_map("xss_clean", $_POST);

            // check if the user has permissions to perform this action
            if($this->accessObject->hasAccess('view', 'branches')) {

                // Check If Branch Exists
                $query = $this->db->prepare("SELECT * FROM branches WHERE branch_id = ? && deleted = ? && clientId = ?");

                if ($query->execute([$branchId, '0', $loggedUserClientId])) {

                    $data = $query->fetch(PDO::FETCH_OBJ);

                    $this->session->curBranchId = $branchId;

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
            
        }
        
        // add or update branch record
        elseif (isset($_POST['branchName'], $_POST['branchType']) && $requestInfo === "addBranchRecord") {

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
                        $checkData = $this->getAllRows("branches", "COUNT(*) AS proceed", "branch_name='{$branchData->branchName}' && branch_type = '{$branchData->branchType}' && deleted = '0' && clientId = '{$loggedUserClientId}'");

                        if ($checkData != false && $checkData[0]->proceed == '0') {

                            // check if the user has permissions to perform this action
                            if($this->accessObject->hasAccess('add', 'branches')) {
                                //: Generate a new branch id
                                $branch_id = random_string('alnum', 12);

                                // Add Record To Database
                                $query = $this->addData(
                                    "branches" ,
                                    "clientId='{$loggedUserClientId}', branch_type='{$branchData->branchType}', branch_name='{$branchData->branchName}', location='{$branchData->location}', branch_email='{$branchData->email}', branch_contact='{$branchData->phone}', branch_logo='{$clientData->client_logo}', branch_id = '{$branch_id}'"
                                );

                                // Record user activity
                                $this->userLogs('branches', $branch_id, 'Added a new Store Outlet into the System.');

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
                        $checkData = $this->getAllRows("branches", "COUNT(*) AS branchTotal", "branch_id='{$branchData->branchId}'");

                        if ($checkData != false && $checkData[0]->branchTotal == '1') {

                            // call the branch id from the session
                            $branchData->branchId = $this->session->curBranchId;

                            // check if the user has permissions to perform this action
                            if($this->accessObject->hasAccess('update', 'branches')) {
                                // save the previous data set
                                $prevData = $this->getAllRows("branches", "*", "clientId='{$loggedUserClientId}' AND branch_id='{$branchData->branchId}'")[0];

                                /* Record the initial data before updating the record */
                                $this->dataMonitoring('branches', $branchData->branchId, json_encode($prevData));

                                // update user data
                                $query = $this->updateData(
                                    "branches",
                                    "branch_name='{$branchData->branchName}', location='{$branchData->location}', branch_type='{$branchData->branchType}', branch_email='{$branchData->email}', branch_contact='{$branchData->phone}'",
                                    "branch_id='{$branchData->branchId}' && clientId='{$loggedUserClientId}'"
                                );

                                // Record user activity
                                $this->userLogs('branches', $branchData->branchId, 'Updated the details of the Store Outlet.');

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
        elseif(isset($_POST['itemToDelete'], $_POST['itemId']) && $requestInfo === "updateStatus") {
            // set the branch id to use
            $branchData = (Object) array_map("xss_clean", $_POST);

            // confirm the user permission to perform this action
            if($this->accessObject->hasAccess('update', 'branches')) {

                // get the branch status using the id parsed
                $query = $this->db->prepare("SELECT status FROM branches WHERE id = ? && deleted = ? && clientId = ?");

                // execute and fetch the record
                if ($query->execute([$branchData->itemId, '0', $loggedUserClientId])) {
                    // get the data
                    $data = $query->fetch(PDO::FETCH_OBJ);
                    
                    // branch status
                    $state = ($data->status == 1) ? 0 : 1;
                    
                    // update the information
                    $this->updateData(
                        "branches",
                        "status='{$state}'",
                        "clientId='{$loggedUserClientId}' AND id='{$branchData->itemId}'"
                    );

                    // Record user activity
                    $this->userLogs('branches', $branchData->itemId, 'Updated the status of the branch and set it as '.(($state) ? "Active" : "Inactive"));

                    $status = true;
                    $message = "Branch status was Successfully updated";

                }
            } else {
                $message = "Sorry! You do not have the required permissions to perform this action.";
            }
        }

        // update the store settings
        elseif($requestInfo === 'settingsManager') {

            // save the previous data set
            $prevData = $this->getAllRows("settings", "*", "clientId='{$loggedUserClientId}'")[0];

            /* Record the initial data before updating the record */
            $this->dataMonitoring('settings', $loggedUserClientId, json_encode($prevData));

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
                    if($this->accessObject->hasAccess('update', 'settings')) {
                        
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
                        $query = $this->updateData(
                            "settings",
                            "client_name='{$postData->company_name}', client_email='{$postData->email}', client_website='{$postData->website}', primary_contact='{$postData->primary_contact}', secondary_contact='{$postData->secondary_contact}', address_1='{$postData->address}', display_clock='{$display_clock}', theme_color_code='{$postData->theme_color}', theme_color='".json_encode($theme_color)."'
                            ",
                            "clientId='{$loggedUserClientId}'"
                        );

                        // Record user activity
                        $this->userLogs('settings', $loggedUserClientId, 'Updated the general settings tab of the Company.');

                        // continue
                        $status = 200;

                        $message = "Settings updated";

                        // update the client logo
                        if($uploadStatus == 1) {
                            $this->updateData(
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
                $query = $this->updateData(
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
                $this->userLogs('settings', $loggedUserClientId, 'Updated the sales details tab of the Company.');

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
                    $this->updateData(
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
                    $this->updateData(
                        "settings",
                        "reports_sales_attendant='".xss_clean($postData->attendantPerformance)."'",
                        "clientId='{$loggedUserClientId}'"
                    );
                }
            }

        }

        // load payment options of this client
        elseif(isset($_POST['loadPaymentOptions']) && $requestInfo === "loadPaymentOptions") {
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
        elseif(isset($_POST['updatePaymentOptions'], $_POST['Option'], $_POST['Value']) && $requestInfo === 'updatePaymentOptions') {
            
            // check if the user has permissions to perform this action
            if($this->accessObject->hasAccess('update', 'settings')) {
                
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
                $this->updateData(
                    "settings",
                    "payment_options='".implode(",", $options)."'",
                    "clientId='{$loggedUserClientId}'"
                );

                // Record user activity
                $this->userLogs('settings', $loggedUserClientId, 'Updated the payment options of the Company.');

                $message = $options;

            }

        }

        $response->message = $message;
        $response->status = $status;

        return json_decode(json_encode($response), true);
    }

}