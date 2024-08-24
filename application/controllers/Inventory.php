<?php

class Inventory extends Pos {

    public $apiAccessValues;
    public $insightRequest;
    public $accessObject;

    /**
     * Inventory Management
     * 
     * @param object $clientData
     * 
     * @return object|array
     */
    public function inventoryManagement($clientData, $requestInfo, $setupInfo) {

        global $config;

        //: initializing
        $response = (object) [
            "status" => "error", 
            "message" => "Error Processing The Request"
        ];

        // set the logged in user id
        $loggedUserClientId = isset($this->apiAccessValues->clientId) ? xss_clean($this->apiAccessValues->clientId) : $this->session->clientId;
        $loggedUserBranchId = (isset($apiAccessValues->branchId)) ? xss_clean($apiAccessValues->branchId) : $this->session->branchId;
        $loggedUserId = (isset($apiAccessValues->userId)) ? xss_clean($apiAccessValues->userId) : $this->session->userId;

        //: if the request is from an api request then push only json raw data
	    $rawJSON = isset($this->apiAccessValues->branchId) ? true : false;

        //: initializing
        $message = "";
        $status = false;
        
        // create a new products object
        $productsClass = load_class("Products", "controllers", $loggedUserClientId);

        $baseUrl = $config->base_url();

        //: process inventories
        if (isset($_POST['request']) && $requestInfo === "getAllProducts") {

            $branchID = (!empty($_POST['branchId'])) ? xss_clean($_POST['branchId']) : null;

            $allProducts = $productsClass->all(false, $branchID);
            $this->session->set_userdata("invSelectedCard", $branchID);

            if (!empty($allProducts)) {
                $i = 0;
                    
                $message = [];

                foreach ($allProducts as $product) {

                    // request through api access
                    if($rawJSON) {
                        unset($product->id);
                        $message[] = $product;
                    } else {
                        // request from site
                        $i++;
                        $price = $this->toDecimal($product->product_price, 2, ',');
                        $cost_price = $this->toDecimal($product->cost_price, 2, ',');

                        // Check Indicator
                        $calcA = (0.5 * $product->quantity) + $product->quantity;
                        $calcB = (0.5 * $product->quantity);
                        $indicator = ($calcA > $product->threshold) ? "success" : 
                        (
                            ($calcB <= $product->threshold) ? "danger" : 
                            (
                                ($calcA > $product->threshold && $calcB < $product->threshold) ? "warning" : null
                            )
                        );

                        $action = "<div class='text-center'>
                                <a class=\"btn btn-outline-info btn-sm\" href=\"{$baseUrl}products/{$product->pid}\">
                                    <i class=\"fa fa-edit\"></i></a>";
                        // ensure the user has the needed permissions
                        if($this->accessObject->hasAccess('inventory_branches', 'products')) {
                            // show button if the quantity is more than 1
                            if($product->quantity > 0) {
                                $action .= "<a class=\"btn btn-sm btn-outline-success transfer-product\" href=\"javascript:void(0)\" onclick=\"return transferProduct('{$product->product_id}');\"><i class=\"fa fa-share\"></i></a>";
                            }
                        }
                        $action .= "</div>";

                        $message[] = [
                            'row_id' => $i,
                            'product_name' => "
                            <a href=\"{$baseUrl}products/{$product->pid}\" class=\"b-0 product-name\">{$product->product_title}</a><br>
                            ID: <strong>{$product->product_id}</strong>
                            ",
                            'category' => $product->category,
                            'price' => "{$clientData->default_currency} {$price}",
                            'cost_price' => "{$clientData->default_currency} {$cost_price}",
                            'quantity' => "<div class='text-center'>{$product->quantity}</div>",
                            'expiry_date' => "{$product->expiry_date}",
                            'indicator' => "<div class='text-center'><span style=\"font-size: 9px;\" class=\"fa fa-circle text-{$indicator}\"></span></div>",
                            'action' => $action
                        ];
                    }

                }
                $status = true;
            }

            $response->message = $message;
            $response->status = $status;
        
        }
        
        //: process the request to transfer product
        else if (isset($_POST['transferProductQuantity'], $_POST["transferProductID"], $_POST['branchId']) && $requestInfo === "submitTransferProduct") {

            // if the session parsed does not match the id sent 
            // disallow the user to continue with the process
            if(!$rawJSON && ($this->session->currentBranchId != $_POST["transferFrom"])) {
                echo json_encode([
                    "message" => "Sorry! Please refresh the page to try again.",
                    "status" => "error",
                ]);
                exit;
            }

            // confirm that the product quantity has been set and a branch id has been parsed
            if (preg_match('/^[0-9]+$/', $_POST['transferProductQuantity']) && !empty($_POST['branchId']) && ($_POST['branchId'] != "null")) {

                $postData = (Object) array_map("xss_clean", $_POST);

                $product = $productsClass->getProduct($postData->transferProductID, "", $postData->transferFrom);

                // check if the product quantity parsed is more than the available
                if(!isset($product->quantity)) {
                    $response->message = "Sorry! An invalid product id was supplied";
                } elseif($postData->transferProductQuantity > $product->quantity) {
                    // print error message
                    $response->message = "Sorry! You have entered a quantity higher than what's available of {$product->quantity} items";
                } else {
                    // assign some more variables
                    $sellPrice = $product->product_price;
                    // Check If Product Exists In Receiving Branch
                    $check = $productsClass->getCountdata(
                        "products", 
                        "product_id = '{$postData->transferProductID}' && branchId = '{$postData->branchId}'"
                    );

                    if ($check == 0) {
                        // Get Product Details
                        $product->branchId = $postData->branchId;
                        $product->transferQuantity = $postData->transferProductQuantity;
                        $product->userId = $loggedUserId;
                        // generate a new stock auto id
                        $stockAutoId = random_string('alnum', 12);
                        
                        // set the stock id 
                        $product->stockAutoId = $stockAutoId;

                        // Add Quantity To Product Table Stock
                        $query = $productsClass->addStockToBranch($product);
                    } else {
                        $query = $productsClass->updateBranchProductStock($postData, 'product_id');
                    }

                    if ($query == true) {
                        // Reduce Sending Shop Product Stock
                        $this->updateData(
                            "products", 
                            "quantity = (quantity - $postData->transferProductQuantity)",
                            "product_id = '{$postData->transferProductID}' && branchId = '{$postData->transferFrom}'"
                        );

                        // Add Product Transfer To Inventory
                        $postData->clientId = $loggedUserClientId;
                        $postData->userId = $loggedUserId;
                        $postData->selling_price = $sellPrice;
                        $productsClass->addToInventory($postData);

                        $response->message = "You have successfully transferred {$postData->transferProductQuantity} items of {$product->product_title} to the specified branch";

                        $response->status = true;
                    } else {
                        $response->message = "Transfer of Product Failed";
                    }

                }

            } else {
                $response->message = "Please Check All Fields Are Required";
            }

        }

        //: bulk transfer of products
        else if (isset($_POST['bulkTransferProducts'], $_POST['productIds'], $_POST["transferFrom"]) && $requestInfo === 'bulkTransferProducts') {

            // if the session parsed does not match the id sent 
            // disallow the user to continue with the process
            if(!$rawJSON && ($this->session->currentBranchId != $_POST["transferFrom"])) {
                echo json_encode([
                    "message" => "Sorry! Please refresh the page to try again.",
                    "status" => "error",
                ]);
                exit;
            }

            // ensure all required information has been parsed
            if (!empty($_POST['productIds']) && !empty($_POST['branchId'])) {

                // continue processing the request
                $products = explode(",", $_POST['productIds']);

                $postData = (Object) array_map("xss_clean", $_POST);
                
                $error_state = false;

                foreach ($products as $productToTransfer) {

                    $currItem = explode("=", $productToTransfer);
                    $productID = (int) xss_clean($currItem[0]);
                    $productQty= (int) xss_clean($currItem[1]);
                    $postData->transferProductQuantity = $productQty;

                    // confirm that at least all products has the value 1
                    if($productQty == 0) {
                        $error_state = true;
                    }
                }

                if($error_state) {
                    $response->message = "Ensure no item has the quantity of 0";
                    echo json_encode([
                        "message" => $message,
                        "status" => $status
                    ]);
                    exit;
                }

                else {

                    // generate a new stock auto id
                    $stockAutoId = random_string('alnum', 12);

                    // loop through the list of all products to transfer
                    foreach ($products as $productToTransfer) {

                        $currItem = explode("=", $productToTransfer);
                        $productID = (int) xss_clean($currItem[0]);
                        $productQty= (int) xss_clean($currItem[1]);
                        $postData->transferProductQuantity = $productQty;

                        $productData = $this->getAllRows("products","product_price, cost_price, product_id", "id='{$productID}'")[0];

                        $sellPrice = $productData->product_price;
                        $nproductID = $productData->product_id;
                        $costPrice = $productData->cost_price;

                        // Check If Product Exists
                        $check = $productsClass->getCountdata(
                            "products", 
                            "product_id = '{$nproductID}' && branchId = '{$postData->branchId}'"
                        );

                        if ($check == 0) {
                            // Get Product Details
                            $product = $productsClass->getProduct($nproductID, "", $postData->transferFrom);

                            $product->branchId = $postData->branchId;
                            $product->transferQuantity = $productQty;
                            $product->userId = $loggedUserId;
                            $product->stockAutoId = $stockAutoId;

                            // Add Quantity To Product Table Stock
                            $query = $productsClass->addStockToBranch($product);
                        } else {
                            $postData->transferProductID = $nproductID;
                            $query = $productsClass->updateBranchProductStock($postData);
                        }

                        if ($query == true) {
                            // Reduce Branch Transferring Product Stock
                            $this->updateData(
                                "products", 
                                "quantity = (quantity - $productQty)",
                                "id = '{$productID}' && branchId = '{$postData->transferFrom}'"
                            );

                            // Add Product Transfer To Inventory
                            $postData->clientId = $loggedUserClientId;
                            $postData->userId = $loggedUserId;
                            $postData->selling_price = $sellPrice;
                            $productsClass->addToInventory($postData);

                            $response->status = true;
                            $response->message = "Products Successfully Transfered";
                        } else {
                            $response->message = "Transfer Failed";
                        }
                    }

                }
            }

        }

        //: update the stock of the existing products
        elseif(isset($_POST["updateWareHouseStock"], $_POST["stockQuantities"]) && $requestInfo === 'updateWareHouseStock') {
            
            //: begin the processing
            $stockData = $_POST["stockQuantities"];
            $branchId = $this->session->currentBranchId;
            $autoId = random_string('alnum', 25);
            $updateQuery = '';

            $insertQuery = 'INSERT INTO products_stocks (clientId, branchId, auto_id, product_id, cost_price, retail_price, previous_quantity, quantity, total_quantity, threshold, recorded_by) VALUES ';

            //: explode the list
            $stockLevels = explode(",", $stockData);

            if(empty($stockLevels)) {
                echo json_encode([
                    "message" => "Please select at least one item to continue",
                    "status" => $status
                ]);
                exit;
            }

            //: loop through the list
            foreach($stockLevels as $eachStock) {

                //: explode each stock
                $eachExplode = explode("|", $eachStock);

                //: assign variables
                if(isset($eachExplode[2])) {

                    //: assign variables
                    $productId = (int) $eachExplode[0];
                    $costPrice = xss_clean($eachExplode[1]);
                    $retailPrice = xss_clean($eachExplode[2]);
                    $quantity = (isset($eachExplode[3])) ? (int) $eachExplode[3] : 0;
                    $threshold = (isset($eachExplode[4])) ? (int) $eachExplode[4] : 0;

                    // each product information
                    $eachProduct = $this->getAllRows("products", "quantity", "id='{$productId}'")[0];
                    $newQuantity = $eachProduct->quantity+$quantity;

                    //: form the sql query to insert
                    $updateQuery .= "UPDATE products SET quantity = (quantity+$quantity), threshold='{$threshold}', cost_price = '{$costPrice}', product_price='{$retailPrice}' WHERE id = '{$productId}' AND branchId='{$this->session->currentBranchId}' AND clientId='{$loggedUserClientId}';";

                    $insertQuery .= "('{$loggedUserClientId}', '{$this->session->currentBranchId}', '{$autoId}', '{$productId}', '{$costPrice}', '{$retailPrice}', '{$eachProduct->quantity}', '{$quantity}', '{$newQuantity}', '{$threshold}', '{$loggedUserClientId}'),";
                }

            }

            $insertQuery = substr($insertQuery, 0, -1).";";
            
            // Get Product Details
            $queryString = $productsClass->updateWareHouseStock($insertQuery, $updateQuery);
            
            //: if the result is true
            if($queryString) {
                $response->status = "success";
                $response->message = "Products stock was successfully updated.";
            }

        }

        // all other requests
        else {
            
            // set default data
            $response->branchId = $loggedUserBranchId;

            // set the image directory
            $imgDir = "assets/images/products";

            // if the user wants to add a new product
            if(isset($_POST['addProduct']) && confirm_url_id(2, 'addProduct')){

                // validation
                $productId = isset($_POST["product_code"]) ? $_POST["product_code"] : null;

                // product existence check
                $branchId = ($rawJSON && isset($postData->branchId)) ? $postData->branchId : (
                    (!$rawJSON && isset($postData->branchId)) ? $postData->branchId : $loggedUserBranchId
                );

                // check the product code 
                if(!empty($productId) && ($this->countRows("products", "product_id='".xss_clean($productId)."' AND branchId='{$branchId}'") > 0)) {
                    // print error message
                    $response->message = "A duplicate product is been added to the same outlet.";
                }
                // start the validation process
                else if(empty($_POST['category']) || ($_POST['category'] == "null")) {
                    $response->message = "Please select product's category";
                }
                elseif(empty($_POST['title'])) {
                    $response->message = "Please enter product's title";
                }
                elseif(empty($_POST['cost'])) {
                    $response->message = "Please enter product's cost price";
                }
                elseif(!preg_match("/^[0-9.]+$/", $_POST["cost"])) {
                    $response->message = "Enter a valid product's cost price";	
                }
                elseif(empty($_POST['price'])) {
                    $response->message = "Please enter product's retail price";
                }
                elseif(!preg_match("/^[0-9.]+$/", $_POST["price"])) {
                    $response->message = "Enter a valid product's retail price";	
                }
                elseif(empty($_POST['quantity'])) {
                    $response->message = "Please enter product's quantity";
                }
                elseif(!preg_match("/^[0-9]+$/", $_POST["quantity"])) {
                    $response->message = "Please enter a valid product quantity.";
                }
                else{

                    // process the file image parsed
                    $postData = (Object) array_map("xss_clean", $_POST);
                    $uploadDir = 'assets/images/products/';
                    $fileName = "default.png";

                    // File path config 
                    if(isset($_FILES["product_image"]["tmp_name"]) && !empty($_FILES["product_image"])) {
                        $fileName = basename($_FILES["product_image"]["name"]); 
                    }

                    // set the product image to be used
                    $targetFilePath = $uploadDir . $fileName; 
                    $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));

                    // if the query was made by an api call and the 
                    $postData->branchId = ($rawJSON && isset($postData->branchId)) ? $postData->branchId : (
                        (!$rawJSON && isset($postData->branchId)) ? $postData->branchId : $loggedUserBranchId
                    );

                    // assign some few more variables
                    $postData->clientId = $loggedUserClientId;
                    $postData->userId = $loggedUserId;
                    $postData->description = (isset($postData->description)) ? nl2br($postData->description) : null;
                    $postData->expiry_date = (isset($postData->expiry_date)) ? nl2br($postData->expiry_date) : null;

                    // Allow certain file formats 
                    $allowTypes = array('jpg', 'png', 'jpeg'); 
                    
                    // check if its a valid image
                    if(!empty($fileName) && in_array($fileType, $allowTypes) && isset($_FILES["product_image"]["tmp_name"]) && !empty($_FILES["product_image"])) {
                        
                        // set a new filename
                        $fileName = random_string('alnum', 25).'.'.$fileType;

                        // Upload file to the server 
                        if(move_uploaded_file($_FILES["product_image"]["tmp_name"], $uploadDir .$fileName)){ 
                            $uploadedFile = $fileName;
                            $uploadStatus = 1; 
                        } else { 
                            $uploadStatus = 0; 
                            $response->message = '<div class="alert alert-danger">Sorry, JPG, JPEG, & PNG files are accepted.</div>';
                        }
                    } else { 
                        $uploadStatus = 0;
                    }

                    $postData->threshold = (isset($postData->threshold)) ? (int) $postData->threshold : 10;
                    $postData->image = $uploadDir .$fileName;

                    // auto generated id for stock management
                    $postData->autoId = random_string('alnum', 12);
                    $postData->productId = (empty($postData->product_code)) ? "PD".$this->orderIdFormat($clientData->id.$this->lastRowId('products')) : $postData->product_code;


                    // add the new product
                    if($productsClass->addProduct($postData)){
                        $response->status = "success";
                        $response->message = "Product width code {$postData->productId} was successfully Added";
                        $response->branchId = $postData->branchId;
                    }
                }
            }


            elseif(isset($_POST['editProduct']) && confirm_url_id(2, 'editProduct')){

                // validation
                $postData = (Object) array_map("xss_clean", $_POST);

                $productId = isset($postData->product_code) ? $postData->product_code : $postData->productId;

                // product existence check
                $branchId = ($rawJSON && isset($postData->branchId)) ? $postData->branchId : $postData->branchId;

                if(!empty($productId) && ($this->countRows("products", "
                    (product_id='".xss_clean($productId)."' OR id='".xss_clean($productId)."') AND branchId='{$branchId}'") != 1)
                ) {
                    $response->message = "An invalid product id was supplied.";
                }
                elseif(empty($_POST['category']) || (isset($_POST['category']) && $_POST['category'] == "null")) {
                    $response->message = "Please select product's category";
                }
                elseif(empty($_POST['title'])) {
                    $response->message = "Please enter product's title";
                }
                elseif(empty($_POST['cost'])) {
                    $response->message = "Please enter product's cost price";
                }
                elseif(!preg_match("/^[0-9.]+$/", $_POST["cost"])) {
                    $response->message = "Enter a valid product's cost price";	
                }
                elseif(empty($_POST['price'])) {
                    $response->message = "Please enter product's retail price";
                }
                elseif(!preg_match("/^[0-9.]+$/", $_POST["price"])) {
                    $response->message = "Enter a valid product's retail price";	
                }
                elseif(isset($_POST['quantity'])) {
                    $response->message = "Sorry the quantity of products can only be updated using the ".$this->APIEndpoints['stockUpdates'];
                }
                else {
                    // process the file image parsed
                    $uploadDir = 'assets/images/products/';
                    $fileName = "default.png";

                    // File path config 
                    if(isset($_FILES["product_image"])) {
                        $fileName = basename($_FILES["product_image"]["name"]); 
                    }

                    // set the product image to be used
                    $targetFilePath = $uploadDir . $fileName; 
                    $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));

                    // Allow certain file formats 
                    $allowTypes = array('jpg', 'png', 'jpeg'); 

                    // check if its a valid image
                    if(!empty($fileName) && in_array($fileType, $allowTypes) && isset($_FILES["product_image"])){
                        
                        // set a new filename
                        $fileName = random_string('alnum', 25).'.'.$fileType;

                        // Upload file to the server 
                        if(move_uploaded_file($_FILES["product_image"]["tmp_name"], $uploadDir .$fileName)){ 
                            $uploadedFile = $uploadDir .$fileName;
                        } else { 
                            $uploadStatus = 0; 
                            $response->message = '<div class="alert alert-danger">Sorry, JPG, JPEG, & PNG files are accepted.</div>';
                        }
                        
                    } else { 
                        $uploadedFile = null;
                    }

                    // set more parameters
                    $postData->threshold = (isset($postData->threshold)) ? (int) $postData->threshold : 10;
                    $postData->image = $uploadedFile;
                    $postData->productId = (isset($postData->productId)) ? $postData->productId : $postData->product_code;
                    // assign some few more variables
                    $postData->clientId = $loggedUserClientId;
                    $postData->userId = $loggedUserId;
                    $postData->description = (isset($postData->description)) ? nl2br($postData->description) : null;
                    $postData->expiry_date = (isset($postData->expiry_date)) ? nl2br($postData->expiry_date) : null;

                    // save the previous data set
                    $prevData = $this->getAllRows("products", "*", "clientId='{$loggedUserClientId}' AND (id='{$postData->productId}' OR product_id='{$postData->productId}')")[0];

                    /* Record the initial data before updating the record */
                    $this->dataMonitoring('products', $postData->productId, json_encode($prevData));

                    // update the product information
                    if($productsClass->updateProduct($postData)){
                        // print success message
                        $response->status = "success";
                        $response->message = [
                            "result" => "Product Successfully Updated",
                            "productId" => $postData->productId
                        ];
                    }
                }
            }

            elseif(isset($_POST['removeProduct']) && confirm_url_id(2, 'removeProduct')){
                // clean the user data supplied
                $productId = xss_clean($_POST['productId']);
                $branchId = (isset($_POST['branchId'])) ? xss_clean($_POST['branchId']) : $loggedUserBranchId;

                // check if the product really exists
                $product = $productsClass->getProduct($productId, null, $branchId);

                // check if the product data is not empty
                if(empty($product)) {
                    // print the error message
                    $response->message = "Sorry! The supplied product id does not exist.";
                } else {
                    // continue and remove the product from the system
                    if($productsClass->removeProduct($productId, $branchId)){
                        $response->status = "success";
                        $response->message = "Product has been removed";
                    }
                }
            }

            elseif(isset($_POST['productId']) && confirm_url_id(2, "productDetails")){
                // clean the user data
                $productId = xss_clean($_POST['productId']);
                $branchId = (isset($_POST['branchId'])) ? xss_clean($_POST['branchId']) : $loggedUserBranchId;

                // fetch the product content
                $product = $productsClass->getProduct($productId, null, $branchId);
                
                // confirm that the product data is not empty
                if(!empty($product)){
                    $response->status = "success";
                    unset($product->id);
                    $response->message = $product;
                } else {
                    $response->message = "Sorry! The product id supplied does not exist.";
                }
            }

            elseif(isset($_POST['getProduct'], $_POST['transferFrom'])){
                $productId = xss_clean($_POST['productId']);
                $product = $productsClass->getProduct($productId);
                $categories = $productsClass->getCategories();

                if(!empty($product) && !empty($categories)){
                    $response->status = "success";
                    $response->categories = $categories;
                    $response->product = $product;
                    $response->branchId = $_POST['transferFrom'];
                }
            }

            elseif(isset($_POST['getWarehouseProduct'], $_POST['transferFrom'])){
                $productId = xss_clean($_POST['productId']);
                $transferFrom = xss_clean($_POST['transferFrom']);

                $product = $productsClass->getProduct($productId, null, $transferFrom);

                if(!empty($product)){
                    $response->status = "success";
                    $response->message = "Displaying product content";
                    $response->product = $product;			
                }
            }

        }

        return $response;

    }

    /**
     * Manage all categories in the system
     * 
     * @return array|object
     */
    public function categoryManagement($clientData, $requestInfo, $setupInfo = null) {
        
        global $config;

        //: initializing
        $response = (object) ["status" => "error", "message" => "Error Processing The Request"];

        //: if the request is from an api request then push only json raw data
	    $rawJSON = isset($this->apiAccessValues->branchId) ? true : false;

        // set the limit
		$limit = (isset($_POST["limit"])) ? (int) $_POST["limit"] : $this->data_limit;

        // set the logged in user id
        $loggedUserClientId = isset($this->apiAccessValues->clientId) ? xss_clean($this->apiAccessValues->clientId) : $this->session->clientId;

        //: list categories
        if(isset($_POST["listProductCategories"]) && $requestInfo === "listProductCategories") {
            //: run the query
            $i = 0;
            # list categories
            $categoryList = $this->getAllRows("products_categories a", "a.*, (SELECT COUNT(*) FROM products b WHERE a.category_id = b.category_id) AS products_count", "a.clientId='{$loggedUserClientId}' LIMIT {$limit}");

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

                    if($this->accessObject->hasAccess('category_update', 'products')) {

                        $eachCategory->action .= "<a data-content='".json_encode($eachCategory)."' href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-primary edit-category\" data-id=\"{$eachCategory->id}\"><i class=\"fa fa-edit\"></i></a>";
                    }
                    
                    if($this->accessObject->hasAccess('category_delete', 'products')) {
                        $eachCategory->action .= "<a href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-danger delete-item\" data-msg=\"Are you sure you want to delete this Product Category?\" data-request=\"category\" data-url=\"{$config->base_url('api/categoryManagement/deleteCategory')}\" data-id=\"{$eachCategory->id}\"><i class=\"fa fa-trash\"></i></a>";
                    }

                    if(empty($eachCategory->action)) {
                        $eachCategory->action = "---";
                    }
                    $categories[] = $eachCategory;
                }	                
            }

            $response->message = $categories;
            $response->status = true;
        }

        elseif(isset($_POST["name"], $_POST["dataset"]) && $requestInfo === 'saveCategory') {
            $postData = (Object) array_map("xss_clean", $_POST);

            if(empty($postData->name)) {
                $response->message = "Category name cannot be empty";
            } else {
                if($postData->dataset == "update") {
                    $query = $this->db->prepare("UPDATE products_categories SET category = '{$postData->name}' WHERE id='{$postData->id}' AND clientId='{$loggedUserClientId}'");

                    if($query->execute()) {
                        $response->status = 200;
                        $response->message = "Product category was updated";
                        $response->href = $config->base_url('settings/prd');
                    }
                }
                elseif($postData->dataset == "add") {
                    
                    $itemId = "PC".$this->orderIdFormat($clientData->id.$this->lastRowId('products_categories'));

                    // execute the statement
                    $query = $this->db->prepare("
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
                        $this->userLogs('product-type', $itemId, 'Added a new product category into the system.');
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
                $query = $this->db->prepare("DELETE FROM products_categories WHERE id='{$postData->itemId}' AND clientId='{$loggedUserClientId}'");
                
                // if it was successfully executed
                if($query->execute()) {
                    // set the response data
                    $response->reload = true;
                    $response->status = true;
                    $response->href = $config->base_url('settings/prd');
                    $response->message = "Product category successfully deleted";
                    
                    // Record user activity
                    $this->userLogs('product-type', $postData->itemId, 'Deleted the Product Type from the system.');
                }
            }
        }

        return json_decode(json_encode($response), true);

    }

}
?>