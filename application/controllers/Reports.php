<?php 

class Reports extends Pos {

    public $clientId;
    public $dateClass;
    public $accessObject;
    public $loggedUserId;
    public $insightRequest;
    public $apiAccessValues;

    private $loggedUserBranchId;
    private $loggedUserBranchAccess;
    private $clientAccessInner;

    private $branchAccess2 = '';
    private $branchAccessInner = '';
    
    private $customerLimit = '';
    private $customerLimitInner = '';
    private $customerLimitInner2 = '';

    private $accessLimit = '';
    private $accessLimitInner = '';
    private $accessLimitInner2 = '';

    /**
     * set some common variables for usage
     * 
     * @param object $clientData
     */
    public function setVariables($clientData, $postData = []) {

        $this->clientId = $clientData->clientId;

        // set the logged in user id
        $this->loggedUserId = isset($this->apiAccessValues->userId) ? xss_clean($this->apiAccessValues->userId) : $this->session->userId;

        // handle the logged in branch id
        $this->loggedUserBranchId = (isset($this->apiAccessValues->branchId)) ? xss_clean($this->apiAccessValues->branchId) : $this->session->branchId;
	    $this->loggedUserBranchAccess = (isset($this->apiAccessValues->branchAccess)) ? xss_clean($this->apiAccessValues->branchAccess) : $this->session->branchAccess;

        // set additional variables
        $this->clientAccessInner = " AND b.clientId = '{$this->clientId}'";

        //: if the customer id is set
        if(!empty($this->session->reportingCustomerId)) {
            $this->customerLimit = " AND a.customer_id = '{$this->session->reportingCustomerId}'";
            $this->customerLimitInner = " AND b.customer_id = '{$this->session->reportingCustomerId}'";
            $this->customerLimitInner2 = " AND c.customer_id = '{$this->session->reportingCustomerId}'";
        }

        //: use the access level for limit contents that is displayed
        if(!$this->accessObject->hasAccess('monitoring', 'branches')) {
            $this->accessLimit = " AND a.recorded_by = '{$this->loggedUserId}'";
            $this->branchAccess2 = " AND a.branch_id = '{$this->loggedUserBranchId}'";
            $this->accessLimitInner = " AND b.recorded_by = '{$this->loggedUserId}'";
            $this->accessLimitInner2 = " AND c.recorded_by = '{$this->loggedUserId}'";
            $this->branchAccessInner = " AND b.branchId = '{$this->loggedUserBranchId}'";
        }

		if(!empty($postData['salesBranch'])) {
			$this->loggedUserBranchId = (int)$postData['salesBranch'];
			$this->branchAccess2 = " AND a.branch_id = '{$this->loggedUserBranchId}'";
            $this->branchAccessInner = " AND b.branchId = '{$this->loggedUserBranchId}'";
		}
        
    }

    /**
     * Report generation and handling
     * 
     * @param object $clientData
     * @param object $setupInfo
     * @param object $expiredAccount
     * 
     * @return array|object
     */
    public function reportsAnalytics($clientData, $setupInfo, $expiredAccount, $requestInfo = '') {

        $this->setVariables($clientData, $_POST);

        global $config;

        //: where clause for the user role
        $branchAccess = '';
        $clientAccess = " AND a.clientId = '{$this->clientId}'";

        //: if the request is from an api request then push only json raw data
	    $rawJSON = isset($this->apiAccessValues->branchId) ? true : false;

        //: use the access level for limit contents that is displayed
        if(!$this->accessObject->hasAccess('monitoring', 'branches')) {
            $branchAccess = " AND a.branchId = '{$this->loggedUserBranchId}'";
        }

		$resultData = [];
		$metric = "unknown";

		// set the query range in an array
		$queryRange = [
			'start' => date("Y-m-d"), 'end' => date("Y-m-d")
		];

		// process the summary results set
		if (isset($_POST['generateReport'], $_POST['queryMetric']) && $requestInfo === 'generateReport') {

			// default variables
			$productLimit = 100;

			// check parsed
			$postData = (Object) array_map('xss_clean', $_POST);
			$period = isset($postData->salesPeriod) ? strtolower($postData->salesPeriod) : "today";
			$metric = isset($postData->queryMetric) ? $postData->queryMetric : null;
			$branch = isset($postData->salesBranch) ? $postData->salesBranch : null;
			$productLimit = ((!empty($postData->productsLimit)) ? (int) $postData->productsLimit : (!empty($this->session->productsLimit) ? $this->session->productsLimit : $productLimit));
			$customerListLimit = (isset($postData->customersLimit)) ? $postData->customersLimit : 30;

			//: if account expired then show only the weeks data
			$period = ($expiredAccount) ? "this-week" : $period;

			// set the range in a session
			if(isset($postData->salesPeriod)) {
				$this->session->set_userdata("reportPeriod", $period);
			}

			// set the range in a session
			if(!empty($postData->salesBranch)) {
				$this->session->set_userdata("reportBranch", $branch);
				$branchAccess = " AND a.branchId = '{$postData->salesBranch}'";
			}

			// alpha filters
			$alphaFilters = ["last-month", "last-30-days", "same-month-last-year", "all-time"];

			/* Check the filters that the user has submitted */
			if(($setupInfo->type != "alpha") && in_array($period, $alphaFilters)) {
				$period = 'this-week';
			}

			// Check Sales Period
			switch ($period) {
				case 'this-week':
					$groupBy = "DATE";
					$format = "jS M Y";
					$groupByClause = "GROUP BY DATE(a.order_date)";
					$groupByInnerClause = "GROUP BY DATE(b.order_date)";
					$dateFrom = date("Y-m-d", strtotime("monday this week"));
					$dateTo = date("Y-m-d", strtotime("sunday this week"));
					$datePrevFrom = date("Y-m-d", strtotime("monday this week -1 week"));
					$datePrevTo = date("Y-m-d", strtotime("sunday this week -1 week"));
					$current = "This Week";
					$display = "Compared to Last Week";
					break;
				case 'this-month':
					$groupBy = "DATE";
					$format = "jS M Y";
					$groupByClause = "GROUP BY DATE(a.order_date)";
					$groupByInnerClause = "GROUP BY DATE(b.order_date)";
					$dateFrom = $this->dateClass->get_month("this_mntstart", date('m'), date('Y'));
					$dateTo = $this->dateClass->get_month("this_mntend", date('m'), date('Y'));
					$datePrevFrom = $this->dateClass->get_month("last_mntstart", date('m'), date('Y'));
					$datePrevTo = $this->dateClass->get_month("last_mntend", date('m'), date('Y'));
					$current = "This Month";
					$display = "Compared to Last Month";
					break;
				case 'same-month-last-year':
					$groupBy = "DATE";
					$format = "jS M Y";
					$groupByClause = "GROUP BY DATE(a.order_date)";
					$groupByInnerClause = "GROUP BY DATE(b.order_date)";
					$dateFrom = date("Y-m-01", strtotime("this month last year"));
					$dateTo = date("Y-m-t", strtotime("this month last year"));
					$datePrevFrom = date("Y-m-01", strtotime("$dateFrom -1 month"));
					$datePrevTo = date("Y-m-t", strtotime("$dateTo -1 month"));
					$current = "This Month Last Year";
					$display = "Compared to ".date("F", strtotime($datePrevFrom))." Last Year";
					break;
				case 'last-30-days':
					$groupBy = "DATE";
					$format = "jS M Y";
					$groupByClause = "GROUP BY DATE(a.order_date)";
					$groupByInnerClause = "GROUP BY DATE(b.order_date)";
					$dateFrom = date("Y-m-d", strtotime("-30 days"));
					$dateTo = date("Y-m-d");
					$datePrevFrom = date("Y-m-d", strtotime("$dateFrom -30 days"));
					$datePrevTo = date("Y-m-d", strtotime("$datePrevFrom +30 days"));
					$current = "Last 30 Days";
					$display = "Compared to Previous 30 days";
					break;
				case 'last-month':
					$groupBy = "DATE";
					$format = "jS M Y";
					$groupByClause = "GROUP BY DATE(a.order_date)";
					$groupByInnerClause = "GROUP BY DATE(b.order_date)";
					$dateFrom = date("Y-m-01", strtotime("-1 month"));
					$dateTo = date("Y-m-t", strtotime("-1 month"));
					$datePrevFrom = date("Y-m-01", strtotime("$dateFrom -1 month"));
					$datePrevTo = date("Y-m-t", strtotime("$dateFrom -1 month"));
					$current = "Last Month";
					$display = "Compared to ".date("F", strtotime($datePrevFrom));
					break;
				case 'this-year':
					$groupBy = "MONTH";
					$format = "F";
					$groupByClause = "GROUP BY MONTH(a.order_date)";
					$groupByInnerClause = "GROUP BY MONTH(b.order_date)";
					$dateFrom = date('Y-01-01');
					$dateTo = date('Y-12-31');
					$datePrevFrom = date('Y-01-01', strtotime("first day of last year"));
					$datePrevTo = date('Y-12-31', strtotime("last day of last year"));
					$current = "This Year";
					$display = "Compared to Last Year";
					break;
				case 'all-time':
					$groupBy = "MONTH";
					$format = "F";
					$groupByClause = "GROUP BY MONTH(a.order_date)";
					$groupByInnerClause = "GROUP BY MONTH(b.order_date)";
					$dateFrom = $setupInfo->setup_date;
					$dateTo = date('Y-m-d');
					$datePrevFrom = $setupInfo->setup_date;
					$datePrevTo = date('Y-m-d');
					$current = "All Time";
					$display = "No Comparison";
					break;
				default:
					$groupBy = "HOUR";
					$format = "HA";
					$groupByClause = "GROUP BY HOUR(order_date)";
					$groupByInnerClause = "GROUP BY HOUR(b.order_date)";
					$dateFrom = date('Y-m-d');
					$dateTo = date('Y-m-d');
					$datePrevFrom = date('Y-m-d', strtotime("-1 day"));
					$datePrevTo = date('Y-m-d', strtotime("-1 day"));
					$current = "Today";
					$display = "Compared to Yesterday";
					break;
			}

			$totalSales = 0;
			$totalServed= 0;
			$grossProfit = 0;
			$netProfit = 0;
			$totalRevenue = 0;
			$orderDiscount = 0;
			$averageInventory = 0;
			$netProfitMargin = 0;
			$costOfGoodsSold = 0;
			$salesPerEmployee = 0;
			$highestSalesValue = 0;
			$averageSalesValue = 0;
			$grossProfitMargin = 0;
			$totalQuantitiesSold = 0;
			$averageUnitPerTransaction = 0;

			// save the selected time frame into the database
			if(isset($postData->salesPeriod)) {
				// update the settings
				$this->updateData(
					"settings",
					"reports_period='{$period}'",
					"clientId='{$this->clientId}'"
				);
			}

			// if the query is for the summary records
			if($metric == 'summaryItems') {

				$sales = $this->getAllRows(
					"sales a LEFT JOIN customers b ON a.customer_id = b.customer_id", 
					"a.order_discount, a.order_amount_paid, a.overall_order_amount, a.order_date, b.title, b.firstname, b.lastname, b.phone_1,
						(
							SELECT MAX(b.order_amount_paid) FROM sales b WHERE b.deleted='0' AND
							(DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') {$this->branchAccessInner} {$this->accessLimitInner} {$this->customerLimitInner} {$this->clientAccessInner}
						) AS highestSalesValue,
						(
							SELECT AVG(b.order_amount_paid) FROM sales b WHERE b.deleted='0' AND
							(DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') {$this->branchAccessInner} {$this->accessLimitInner} {$this->customerLimitInner} {$this->clientAccessInner}
						) AS averageSalesValue,
						(
							SELECT SUM((b.product_unit_price * b.product_quantity)-(b.product_cost_price * b.product_quantity)) FROM sales_details b WHERE b.order_id = a.order_id
							AND (DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') {$this->branchAccessInner} {$this->clientAccessInner} {$this->accessLimit}
						) AS totalGrossProfit,
						(
							SELECT SUM(b.product_cost_price * b.product_quantity) FROM sales_details b WHERE b.order_id = a.order_id
							AND (DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') {$this->branchAccessInner} {$this->clientAccessInner} {$this->accessLimit}
						) AS costOfGoodsSold,
						(
							SELECT 
								SUM(b.product_quantity)
							FROM sales_details b
							WHERE b.order_id = a.order_id AND (DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') {$this->branchAccessInner} {$this->clientAccessInner} {$this->accessLimit}
						) AS totalQuantitiesSold,
						(
							SELECT 
								SUM(b.quantity)
							FROM products b WHERE status='1'
						) AS productQuantity,
						(
							SELECT 
								SUM(b.amount)
							FROM expenses b
							WHERE b.status = '1' AND (DATE(b.start_date) >= '{$dateFrom}' AND DATE(b.start_date) <= '{$dateTo}') {$this->branchAccessInner} {$this->clientAccessInner}
						) AS totalExpenditure,
						(
							SELECT SUM(b.order_amount_paid)/(SELECT COUNT(*) FROM users b WHERE b.status='1' && b.access_level NOT IN (1) {$this->clientAccessInner} {$this->branchAccessInner})
							FROM sales b WHERE b.deleted='0' AND (DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') {$this->branchAccessInner} {$this->clientAccessInner} {$this->accessLimit}
						) AS salesPerEmployee
					", 
					"a.order_status = 'confirmed' && a.deleted = '0' {$this->accessLimit} && (DATE(a.order_date) >= '{$dateFrom}' && DATE(a.order_date) <= '{$dateTo}') {$branchAccess} {$clientAccess} {$this->customerLimit} ORDER BY a.log_date DESC"
				);

				$productQuantity = 0;
				$allExpenses = 0;

				if ($sales != false) {
					$i = 0;
					
					foreach ($sales as $data) {
						$i++;

						$orderDate = date('jS F Y', strtotime($data->order_date));

						$totalSales += $data->order_amount_paid;
						$totalServed += 1;
						$productQuantity = $data->productQuantity;
						$orderDiscount += $data->order_discount;
						$salesPerEmployee = $data->salesPerEmployee;
						$highestSalesValue = $data->highestSalesValue;
						$averageSalesValue = $data->averageSalesValue;
						$totalQuantitiesSold += $data->totalQuantitiesSold;
						$totalRevenue += $data->overall_order_amount;
						$costOfGoodsSold += $data->costOfGoodsSold;
						$allExpenses += $data->totalExpenditure;
					}

					$status = true;
				}

				// gross and net revenue calculation
				$grossProfit = $totalRevenue - $costOfGoodsSold;
				if($totalRevenue > 0) {
					$grossProfitMargin = ((($totalRevenue - $costOfGoodsSold) / $totalRevenue) * 100);
				}

				$netProfit = $totalRevenue - ($allExpenses+$costOfGoodsSold);
				if($totalRevenue > 0) {
					$netProfitMargin = (($totalRevenue - ($allExpenses+$costOfGoodsSold)) / $totalRevenue) * 100;
				}

				// inventory calculation
				$endingInventory = $productQuantity;
				$beginingInventory = ($productQuantity + $totalQuantitiesSold);
				$averageInventory = (($beginingInventory+$endingInventory) / 2);

				$stockTurnoverRate = ($costOfGoodsSold > 0 && $averageInventory > 0) ? ($costOfGoodsSold / $averageInventory) : 0;

				// sell through percentage
				$sellThroughPercentage = ($totalQuantitiesSold > 0 && $beginingInventory > 0) ? (($totalQuantitiesSold / $beginingInventory) * 100) : 0;

				// gross margin return on investment
				$grossMarginInvestmentReturn = ($grossProfit > 0 && $averageInventory > 0) ? ($grossProfit / $averageInventory) : 0;

				// unit per transaction
				$averageUnitPerTransaction = ($totalQuantitiesSold > 0) ? ($totalQuantitiesSold / $totalServed) : 0;

				//: count the number of products available
				$inventoryCount = $this->countRows("products_categories a","1 {$clientAccess}");

				// customer retention rate
				$totalCustomersCount = $this->countRows("customers a","a.status='1' {$branchAccess} {$clientAccess}");
				$customersDuringPeriod = $this->countRows("customers a","a.status='1' AND (DATE(a.date_log) >= '{$dateFrom}' AND DATE(a.date_log) <= '{$dateTo}') {$branchAccess} {$clientAccess}");
				$customersBeforePeriod = $this->countRows("customers a","a.status='1' AND (DATE(a.date_log) < '{$dateFrom}') {$branchAccess} {$clientAccess}");

				// crr calculation
				$customersRetentionRate = ($customersBeforePeriod > 0) ? ((($totalCustomersCount-$customersDuringPeriod)/$customersBeforePeriod) * 100) : 0;

				// previous records
				$prevSales = $this->getAllRows(
					"sales a", 
					"
						COUNT(*) AS totalPrevServed, CASE WHEN SUM(a.order_amount_paid) IS NULL THEN 1 ELSE SUM(a.order_amount_paid) END AS totalPrevSales, SUM(a.order_discount) AS total_order_discount, SUM(a.overall_order_amount) AS totalRevenue,
						(
							SELECT MAX(b.order_amount_paid) FROM sales b WHERE b.deleted='0' AND
							(DATE(b.order_date) >= '{$datePrevFrom}' && DATE(b.order_date) <= '{$datePrevTo}') {$this->branchAccessInner} {$this->customerLimitInner}
							{$this->accessLimitInner} {$this->clientAccessInner}
						) AS highestSalesValue,
						(
							SELECT AVG(b.order_amount_paid) FROM sales b WHERE b.deleted='0' AND
							(DATE(b.order_date) >= '{$datePrevFrom}' && DATE(b.order_date) <= '{$datePrevTo}') {$this->branchAccessInner} {$this->customerLimitInner}
							{$this->accessLimitInner} {$this->clientAccessInner}
						) AS averageSalesValue,
						(
							SELECT SUM((b.product_unit_price * b.product_quantity)-(b.product_cost_price * b.product_quantity)) FROM sales_details b WHERE b.order_id = a.order_id
							AND (DATE(b.order_date) >= '{$datePrevFrom}' AND DATE(b.order_date) <= '{$datePrevTo}') 
							{$this->branchAccessInner} {$this->clientAccessInner} {$this->accessLimit}
						) AS totalGrossProfit,
						(
							SELECT SUM(b.product_cost_price * b.product_quantity) FROM sales_details b WHERE b.order_id = a.order_id
							AND (DATE(b.order_date) >= '{$datePrevFrom}' AND DATE(b.order_date) <= '{$datePrevTo}') {$this->branchAccessInner} {$this->clientAccessInner} {$this->accessLimit}
						) AS costOfGoodsSold,
						(
							SELECT 
								SUM(b.amount)
							FROM expenses b
							WHERE b.status = '1' AND (DATE(b.start_date) >= '{$datePrevFrom}' AND DATE(b.start_date) <= '{$datePrevTo}') {$this->branchAccessInner} {$this->clientAccessInner}
						) AS totalExpenditure,
						(
							SELECT 
								CASE WHEN SUM(b.product_quantity) IS NULL THEN 0.001 ELSE SUM(b.product_quantity) END
							FROM sales_details b
							WHERE b.order_id = a.order_id AND (DATE(b.order_date) >= '{$datePrevFrom}' AND DATE(b.order_date) <= '{$datePrevTo}') {$this->branchAccessInner} {$this->clientAccessInner} {$this->accessLimit}
						) AS totalQuantitiesSold,
						(
							SELECT 
								SUM(b.quantity)
							FROM products b WHERE status='1'
						) AS productQuantity,
						(
							SELECT SUM(b.order_amount_paid)/(SELECT COUNT(*) FROM users b WHERE b.status='1' && b.access_level NOT IN (1) {$this->clientAccessInner} {$this->branchAccessInner})
							FROM sales b WHERE b.deleted='0' AND (DATE(b.order_date) >= '{$datePrevFrom}' AND DATE(b.order_date) <= '{$datePrevTo}') {$this->branchAccessInner} {$this->clientAccessInner} {$this->accessLimit}
						) AS salesPerEmployee
					", 
					"a.order_status = 'confirmed' && a.deleted = '0' {$this->accessLimit} && DATE(a.order_date) >= '{$datePrevFrom}' && DATE(a.order_date) <= '{$datePrevTo}' {$branchAccess} {$clientAccess} {$this->customerLimit} ORDER BY a.log_date DESC"
				);

				if ($prevSales != false) {

					$prevSales = $prevSales[0];

					// inventory calculation
					$endingInventory = $productQuantity;
					$prevBeginingInventory = ($productQuantity + $prevSales->totalQuantitiesSold);
					$prevAverageInventory = (($prevBeginingInventory+$endingInventory) / 2);

					$prevStockTurnoverRate = ($prevSales->costOfGoodsSold > 0 && $prevAverageInventory > 0) ? ($prevSales->costOfGoodsSold / $prevAverageInventory) : 0;

					// trend calculation
					$totalServedTrend = $this->percentDifference(floatval($totalServed), floatval($prevSales->totalPrevServed));
					$totalSalesTrend = $this->percentDifference(floatval($totalSales), floatval($prevSales->totalPrevSales));
					$highestSalesTrend = $this->percentDifference(floatval($highestSalesValue), floatval($prevSales->highestSalesValue));
					$averageSalesTrend = $this->percentDifference(floatval($averageSalesValue), floatval($prevSales->averageSalesValue));
					$stockTurnoverRateTrend = $this->percentDifference(floatval($stockTurnoverRate), floatval($prevStockTurnoverRate));
					$averageUnitPerTransactionTrend = ($prevSales->totalPrevServed > 0) ? $this->percentDifference(floatval($averageUnitPerTransaction), (floatval($prevSales->totalQuantitiesSold)/$prevSales->totalPrevServed)) : '<span class="text-success"><i class="mdi mdi-trending-up"></i> 100%</span>';		
					$orderDiscountTrend = $this->percentDifference(floatval($orderDiscount), floatval($prevSales->total_order_discount));
					$salesPerEmployeeTrend = $this->percentDifference(floatval($salesPerEmployee), floatval($prevSales->salesPerEmployee));
					$grossProfitTrend = $this->percentDifference(floatval($grossProfit), (floatval($prevSales->totalRevenue)-$prevSales->costOfGoodsSold));
					$grossProfitMarginTrend = ($prevSales->totalRevenue > 0) ? $this->percentDifference(floatval($grossProfitMargin), (((floatval($prevSales->totalRevenue)-floatval($prevSales->costOfGoodsSold))/floatval($prevSales->totalRevenue)) * 100)) : '<span class="text-success"><i class="mdi mdi-trending-up"></i> 100%</span>';
					$netProfitTrend = $this->percentDifference(floatval($netProfit), (floatval($prevSales->totalRevenue)-($prevSales->totalExpenditure+$prevSales->costOfGoodsSold)));
					$netProfitMarginTrend = ($prevSales->totalRevenue > 0) ? $this->percentDifference(floatval($netProfitMargin), (((floatval($prevSales->totalRevenue)-($prevSales->totalExpenditure+$prevSales->costOfGoodsSold))/floatval($prevSales->totalRevenue)) * 100)) : '<span class="text-success"><i class="mdi mdi-trending-up"></i> 100%</span>';
				}

				// show this section if the main analytics is been requested
				if(!$this->session->reportingCustomerId) {
					
					$resultData[] = [
						"column" => "sell-through-percentage",
						"total" => number_format($sellThroughPercentage, 2) . "%", 
						"trend" => "Sell Through Percentage"
					];
					$resultData[] = [
						"column" => "gross-margin-return-investment",
						"total" => $clientData->default_currency . (!empty($grossMarginInvestmentReturn) ? number_format($grossMarginInvestmentReturn, 2) : 0), 
						"trend" => "Gross margin investment returns"
					];
					$resultData[] = [
						"column" => "stock-turnover-rate",
						"total" => $clientData->default_currency . (!empty($stockTurnoverRate) ? number_format($stockTurnoverRate, 2) : 0), 
						"trend" => "Total Stock Turnover Rate"
					];
					$resultData[] = [
						"column" => "average-unit-per-transaction",
						"total" => number_format($averageUnitPerTransaction, 2),
						"trend" => $averageUnitPerTransactionTrend . " ".$display
					];
					$resultData[] = [
						"column" => "sales-per-employee",
						"total" => $clientData->default_currency . (!empty($salesPerEmployee) ?number_format($salesPerEmployee, 2) : 0),
						"trend" => $salesPerEmployeeTrend . " ".$display
					];
					$resultData[] = [
						"column" => "gross-profit",
						"total" => $clientData->default_currency . (!empty($grossProfit) ? number_format($grossProfit, 2) : 0),
						"trend" => $grossProfitTrend . " " .$display
					];
					$resultData[] = [
						"column" => "gross-profit-margin",
						"total" => number_format($grossProfitMargin, 2). "%",
						"trend" => $grossProfitMarginTrend . " " .$display
					];
					$resultData[] = [
						"column" => "break-even-point",
						"total" => ($allExpenses > 0 && $grossProfit > 0) ? number_format(($allExpenses/$grossProfit), 2) : 0,
						"trend" => "Sales Break even Point"
					];
					$resultData[] = [
						"column" => "sales-per-category",
						"total" => ($inventoryCount > 0) ? $clientData->default_currency . number_format(($totalSales/$inventoryCount), 2) : 0,
						"trend" => "Sales Per Category"
					];
					$resultData[] = [
						"column" => "net-profit",
						"total" => $clientData->default_currency . (!empty($netProfit) ? number_format($netProfit, 2) : 0),
						"trend" => $netProfitTrend . " " .$display
					];
					$resultData[] = [
						"column" => "net-profit-margin",
						"total" => number_format($netProfitMargin, 2). "%",
						"trend" => $netProfitMarginTrend . " " .$display
					];
					$resultData[] = [
						"column" => "customers-retention-rate",
						"total" => $customersRetentionRate. "%",
						"trend" => 'Customers Retention Rate'
					];
					$resultData[] = [
						"column" => "year-over-year-growth",
						"total" => number_format((($totalSales-$prevSales->totalPrevSales)/$prevSales->totalPrevSales)*100, 2),
						"trend" => "Year over Year Growth Rate"
					];
				}

				$resultData[] = [
					"column" => "total-sales",
					"total" => $clientData->default_currency . (!empty($totalSales) ? number_format($totalSales, 2) : 0), 
					"trend" => $totalSalesTrend ." ". $display
				];
				$resultData[] = [
					"column" => "total-orders",
					"total" => $totalServed,
					"trend" => $totalServedTrend ." ". $display
				];
				$resultData[] = [
					"column" => "average-sales",
					"total" => $clientData->default_currency . (!empty($averageSalesValue) ? number_format($averageSalesValue, 2) : 0),
					"trend" => $averageSalesTrend ." ". $display
				];
				$resultData[] = [
					"column" => "highest-sales",
					"total" => $clientData->default_currency . (!empty($highestSalesValue) ? number_format($highestSalesValue, 2) : 0),
					"trend" => $highestSalesTrend ." ". $display
				];
				$resultData[] = [
					"column" => "order-discount",
					"total" => $clientData->default_currency . (!empty($orderDiscount) ? number_format($orderDiscount, 2) : 0),
					"trend" => $orderDiscountTrend ." ". $display
				];
				
			}

			// if the user is querying for the sales overview
			elseif(in_array($metric, ['salesOverview'])) {

				// payment options processor
				$paymentOptions = $clientData->payment_options;
				$message = [];
				
				// payment sums
				$paymentKeys = array();
				$paymentValues = array();

				// confirm that the record is not empty
				if(!empty($paymentOptions)) {

					// explode the content with the comma
					$opts = explode(',', $paymentOptions);
					
					// using the foreach loop to fetch the records
					foreach ($opts as $eachOption) {
						$queryData = $this->getAllRows(
							"sales a", "CASE WHEN SUM(a.order_amount_paid) IS NULL THEN 0.1 ELSE SUM(a.order_amount_paid) END AS total_amount, a.payment_type",
							"a.payment_type = '".strtolower($eachOption)."' && a.deleted='0' && a.order_status='confirmed' && (DATE(a.order_date) >= '{$dateFrom}' && DATE(a.order_date) <= '{$dateTo}') {$branchAccess} {$this->accessLimit} {$clientAccess}
							"
						);
						
						$paymentData = $queryData[0];
						$paymentKeys[] = ucfirst($eachOption);
						$paymentValues[] = round($paymentData->total_amount);
						
					}
					// payment breakdown
					$paymentBreakdown = [
						"payment_option" => $paymentKeys,
						"payment_values" => $paymentValues
					];

				}

				
				
				// check if product categories insight is in the insight request array
				if(in_array("productCategoryInsight", $this->insightRequest)) {

					// loop through the products category
					$category_stmt = $this->db->prepare("
						SELECT
							a.id, a.category AS category_name,
							(
								SELECT 
						            CASE 
						                WHEN SUM(c.product_quantity*c.product_unit_price) IS NULL THEN 0.1 
						            ELSE 
						                SUM(c.product_quantity*c.product_unit_price)
						            END
						        FROM 
						        	sales_details c
						        LEFT JOIN products d ON d.id = c.product_id
						        LEFT JOIN sales b ON b.order_id = c.order_id
						       	WHERE 
						        	(DATE(c.order_date) >= '{$dateFrom}' && DATE(c.order_date) <= '{$dateTo}') 
						        	AND d.category_id=a.category_id AND b.deleted='0' 
						        	{$this->branchAccessInner} {$this->clientAccessInner} {$this->accessLimitInner}
							) AS amount
						FROM
							products_categories a
						WHERE 1 {$clientAccess}
					");
					$category_stmt->execute();

					// initializing
					$category_names = [];
					$category_amount = [];
					
					// assign the category sales record
					while($categorySales = $category_stmt->fetch(PDO::FETCH_OBJ)) {
						// assign the data set
						$category_names[] = trim($categorySales->category_name);
						$category_amount[] = round($categorySales->amount);
					}

				}

				// check if products performance insight is in the insight request array
				if(in_array("productsPerformanceInsight", $this->insightRequest)) {

					// limit the products list by the branch of the customer if it has been set
					$branchLimit = null;
					if(!empty($this->session->customerBranchId)) {
						if(empty($branchAccess)) {
							$branchLimit = "&& a.branchId = '{$this->session->customerBranchId}'";
						}
					}
					// products performance query
					$products_stmt = $this->db->prepare("
						SELECT
							a.id, a.category_id, a.product_title, b.branch_name,
							a.product_image, a.product_id, a.date_added, a.quantity,
							(
								SELECT 
									b.order_date
								FROM sales_details b
								LEFT JOIN sales c ON b.order_id = c.order_id
								WHERE 
									c.deleted='0' AND b.product_id = a.id AND
									(DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
									{$this->branchAccessInner} {$this->customerLimitInner2} {$this->clientAccessInner} {$this->accessLimitInner2}
								ORDER BY b.id ASC LIMIT 1
							) AS firstSale,
							(
								SELECT 
									b.order_date
								FROM sales_details b
								LEFT JOIN sales c ON b.order_id = c.order_id
								WHERE 
									c.deleted='0' AND b.product_id = a.id AND
									(DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
									{$this->branchAccessInner} {$this->customerLimitInner2} {$this->clientAccessInner} {$this->accessLimitInner2}
								ORDER BY b.id DESC LIMIT 1
							) AS lastSale,
							(
								SELECT 
									COUNT(c.order_id) 
								FROM sales_details b
								LEFT JOIN sales c ON b.order_id = c.order_id
								WHERE 
									c.deleted='0' AND b.product_id = a.id AND
									(DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
									{$this->branchAccessInner} {$this->customerLimitInner2} {$this->clientAccessInner} {$this->accessLimitInner2}
							) AS orders_count,
							(
								SELECT 
									SUM(b.product_quantity) 
								FROM sales_details b
								LEFT JOIN sales c ON b.order_id = c.order_id
								WHERE 
									c.deleted='0' AND b.product_id = a.id AND
									(DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
									{$this->branchAccessInner} {$this->customerLimitInner2} {$this->clientAccessInner} {$this->accessLimitInner2}
							) AS totalQuantitySold,
							(
								SELECT 
									SUM(b.product_returns_count) 
								FROM sales_details b
								LEFT JOIN sales c ON b.order_id = c.order_id
								WHERE 
									c.deleted='0' AND b.product_id = a.id AND
									(DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
									{$this->branchAccessInner} {$this->customerLimitInner2} {$this->clientAccessInner} {$this->accessLimitInner2}
							) AS totalReturnsCount,
							(
								SELECT 
									SUM(b.product_returns_count*b.product_unit_price) 
								FROM sales_details b
								LEFT JOIN sales c ON b.order_id = c.order_id
								WHERE 
									c.deleted='0' AND b.product_id = a.id AND
									(DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
									{$this->branchAccessInner} {$this->customerLimitInner2} {$this->clientAccessInner} {$this->accessLimitInner2}
							) AS totalReturnsValue,
							(
								SELECT 
									SUM(b.product_quantity*b.product_cost_price) 
								FROM sales_details b
								LEFT JOIN sales c ON b.order_id = c.order_id
								WHERE 
									c.deleted='0' AND b.product_id = a.id AND
									(DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
									{$this->branchAccessInner} {$this->customerLimitInner2} {$this->clientAccessInner} {$this->accessLimitInner2}
							) AS totalProductsSoldCost,
							(
								SELECT 
									SUM(b.product_quantity*b.product_unit_price) 
								FROM sales_details b
								LEFT JOIN sales c ON b.order_id = c.order_id
								WHERE 
									c.deleted='0' AND b.product_id = a.id AND
									(DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
									{$this->branchAccessInner} {$this->customerLimitInner2} {$this->clientAccessInner} {$this->accessLimitInner2}
							) AS totalProductsRevenue,
							(
								SELECT 
									SUM((b.product_quantity*b.product_unit_price) - (b.product_quantity*b.product_cost_price)) 
								FROM sales_details b
								LEFT JOIN sales c ON b.order_id = c.order_id
								WHERE 
									c.deleted='0' AND b.product_id = a.id AND
									(DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
									{$this->branchAccessInner} {$this->customerLimitInner2} {$this->clientAccessInner} {$this->accessLimitInner2}
							) AS totalProductsProfit
						FROM 
							products a
						LEFT JOIN branches b ON b.id = a.branchId
						WHERE a.status = '1' {$branchLimit} {$branchAccess} {$clientAccess} ORDER BY totalProductsProfit DESC LIMIT {$productLimit}
					");
					$products_stmt->execute();
					
					// initializing
					$productsArray = [];
					$overallSale = array_sum($paymentValues);
					
					$numberOfDays = $this->dateClass->listDays($dateFrom, $dateTo, $this->stringToArray($clientData->shop_opening_days));

					$iv = 0;

					// loop through the product information that have been fetched
					while($product_result = $products_stmt->fetch(PDO::FETCH_OBJ)) {
						$iv++;

						$product_result->totalProductsSoldCost = !$product_result->totalProductsSoldCost ? 0 : $product_result->totalProductsSoldCost;
						$product_result->totalProductsRevenue = !$product_result->totalProductsRevenue ? 0 : $product_result->totalProductsRevenue;
						$product_result->totalProductsProfit = !$product_result->totalProductsProfit ? 0 : $product_result->totalProductsProfit;
						
						$productsArray[] = [
							'row_id' => $iv,
							'percentage' => ($product_result->totalProductsRevenue > 0) ? number_format(($product_result->totalProductsRevenue/$overallSale)*100) : 0,
							'returns_count' => (int) $product_result->totalReturnsCount,
							'returns_value' => $product_result->totalReturnsValue,
							'returns_percentage' => (($product_result->totalReturnsCount > 0) ? number_format(($product_result->totalReturnsCount/$product_result->totalQuantitySold), 2) : 0)."%",
							'product_id' => $product_result->id,
							'created' => date("jS M", strtotime($product_result->date_added)),
							'items_sold_per_day' => ($product_result->totalQuantitySold > 0) ? round($product_result->totalQuantitySold/count($numberOfDays)) : 0,
							'current_stock' => $product_result->quantity,
							'first_sale' => (!empty($product_result->firstSale)) ? date("jS M", strtotime($product_result->firstSale)) : null,
							'last_sale' => (!empty($product_result->lastSale)) ? date("jS M", strtotime($product_result->lastSale)) : null,
							'category_id' => $product_result->category_id,
							'product_title' => "<strong class='text-dark'><a href='".$config->base_url('products/'.$product_result->id)."'>{$product_result->product_title}</a></strong> (<strong>{$product_result->product_id})</strong><br><span class='text-gray'>({$product_result->branch_name})</span>",
							'product_image' => $product_result->product_image,
							'orders_count' => $product_result->orders_count,
							'quantity_sold' => (int) $product_result->totalQuantitySold,
							'total_selling_cost' => $clientData->default_currency.number_format($product_result->totalProductsSoldCost, 2),
							'total_selling_revenue' => $clientData->default_currency.number_format($product_result->totalProductsRevenue, 2),
							'product_profit' => $clientData->default_currency.number_format($product_result->totalProductsProfit, 2)
						];
					}

				}
				
				// dates range
				// get the list of all sales returns
				$sales_list = $this->db->prepare("
					SELECT 
						SUM(a.order_amount_paid) AS amount_discounted,
						SUM(a.overall_order_amount) AS amount_not_discounted, 
						DATE(a.order_date) AS dates,
						HOUR(a.order_date) AS hourOfDay,
						MONTH(a.order_date) AS monthOfSale,
						(
							SELECT
								COUNT(*)
							FROM sales b 
							WHERE 
								a.order_status = 'confirmed' AND b.deleted = '0' AND  
								($groupBy(b.order_date) = $groupBy(a.order_date))
							AND (DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
							{$this->branchAccessInner} {$this->accessLimitInner} {$this->clientAccessInner} {$this->customerLimitInner}
						) AS orders_count,
						(
							SELECT
								COUNT(DISTINCT b.customer_id)
							FROM sales b 
							WHERE 
								b.order_status = 'confirmed' AND b.deleted = '0' AND  
								($groupBy(b.order_date) = $groupBy(a.order_date))
							AND (DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
							{$this->branchAccessInner} {$this->accessLimitInner} {$this->clientAccessInner} {$this->customerLimitInner}
						) AS unique_customers,
						(
							SELECT CASE WHEN SUM(b.order_amount_paid) IS NULL THEN 0.00 ELSE SUM(b.order_amount_paid) END
							FROM sales b
							WHERE b.payment_type='credit' AND b.order_status='confirmed' AND  
							($groupBy(b.order_date) = $groupBy(a.order_date)) AND b.deleted='0' 
							AND (DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
							{$this->branchAccessInner} {$this->accessLimitInner} {$this->clientAccessInner} {$this->customerLimitInner} 
						) AS total_credit_sales,
						(
							SELECT CASE WHEN SUM(b.order_amount_paid) IS NULL THEN 0.00 ELSE SUM(b.order_amount_paid) END 
							FROM sales b
							WHERE b.payment_type != 'credit' AND b.order_status='confirmed' AND  
							($groupBy(b.order_date) = $groupBy(a.order_date)) AND b.deleted='0'
							AND (DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
							{$this->branchAccessInner} {$this->accessLimitInner} {$this->clientAccessInner} {$this->customerLimitInner}
						) AS total_actual_sales,
						(
							SELECT SUM(b.product_cost_price * b.product_quantity) FROM sales_details b WHERE ($groupBy(b.order_date) = $groupBy(a.order_date))
							AND (DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
							{$this->branchAccessInner} {$this->customerLimit} {$this->clientAccessInner} {$this->accessLimit}
						) AS total_cost_price,
						(
							SELECT SUM(b.product_unit_price * b.product_quantity) FROM sales_details b WHERE ($groupBy(b.order_date) = $groupBy(a.order_date))
							AND (DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
							{$this->branchAccessInner} {$this->customerLimit} {$this->clientAccessInner} {$this->accessLimit}
						) AS total_selling_price,
						(
							SELECT SUM((b.product_unit_price * b.product_quantity)-(b.product_cost_price * b.product_quantity)) FROM sales_details b WHERE 
							($groupBy(b.order_date) = $groupBy(a.order_date))
							AND (DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
							{$this->branchAccessInner} {$this->customerLimit} {$this->clientAccessInner} {$this->accessLimit}
						) AS total_profit_made,
						(
							SELECT SUM(b.order_discount) FROM sales b WHERE ($groupBy(b.order_date) = $groupBy(a.order_date)) AND b.deleted='0'
							AND (DATE(b.order_date) >= '{$dateFrom}' AND DATE(b.order_date) <= '{$dateTo}') 
							{$this->branchAccessInner} {$this->customerLimit} {$this->clientAccessInner} {$this->accessLimit}
						) AS total_order_discount
					FROM sales a
					WHERE 
						a.order_status = 'confirmed' AND a.deleted = '0' AND  
						(DATE(a.order_date) >= '{$dateFrom}' AND DATE(a.order_date) <= '{$dateTo}') 
						{$branchAccess} {$this->accessLimit} {$clientAccess} {$this->customerLimit} {$groupByClause}
				");
				$sales_list->execute();

				// set some array functions
				$Labeling = [];
				$amount_discounted = [];
				$amount_not_discounted = [];
				$totalOrdersArray = [];
				$lowestSalesValue = 0;
				$highestSalesValue = 0;
				$totalOrdersCount = 0;
				$creditSales = [];
				$actualSales = [];
				$uniqueCustomers = [];
				$returningCustomers = [];
				$totalCostPrice = [];
				$totalSellingPrice = [];
				$totalProfit = [];

				// fetch the information
				while($sales_result = $sales_list->fetch(PDO::FETCH_OBJ)) {
					// confirm which record to fetch
					if($period == "today") {
						$Labeling[] = $sales_result->hourOfDay;
					} elseif(in_array($period, ["this-year", "all-time"])) {
						$Labeling[] = $sales_result->monthOfSale;
					} else {
						$Labeling[] = date('Y-m-d', strtotime($sales_result->dates));
					}
					// use amount if the sales overview is queried 
					$amount_not_discounted[] = $sales_result->amount_not_discounted;
					$amount_discounted[] = $sales_result->amount_discounted;
						
					// add these items if the user is on the analytics page
					$creditSales[] = $sales_result->total_credit_sales;
					$actualSales[] = $sales_result->total_actual_sales;

					$totalCostPrice[] = $sales_result->total_cost_price;
					$totalSellingPrice[] = $sales_result->total_selling_price;
					$totalProfit[] = $sales_result->total_profit_made - $sales_result->total_order_discount;
					$totalOrdersArray[] = $sales_result->orders_count;
					
					$uniqueCustomers[] = $sales_result->unique_customers;
					$totalOrdersCount += $sales_result->orders_count;
				
					// if the metric is orders count
					$returningCustomers[] = ($sales_result->orders_count-$sales_result->unique_customers);
				}

				// not discount amount
				$amountNotDiscountedData = array_combine($Labeling, $amount_not_discounted);
				
				// get the hightest and lowest values
				$lowestSalesValue = (!empty($amount_discounted)) ? min($amount_discounted) : 0.00;
				$highestSalesValue = (!empty($amount_discounted)) ? max($amount_discounted) : 0.00;

				// check if actuals and credit insight is in the insight request array
				if(in_array("actualsCreditInsight", $this->insightRequest)) {
					$actualSalesData = array_combine($Labeling, $actualSales);
					$creditSalesData = array_combine($Labeling, $creditSales);
				}

				// check if cost price, selling and profit insight is in the insight request array
				if(in_array("costSellingProfitInsight", $this->insightRequest)) {

					$totalCostData = array_combine($Labeling, $totalCostPrice);
					$totalSellingData = array_combine($Labeling, $totalSellingPrice);
					$totalProfitData = array_combine($Labeling, $totalProfit);
				}

				// check if customer orders insight is in the insight request array
				if(in_array("customerOrdersInsight", $this->insightRequest)) {
					$totalOrderData = array_combine($Labeling, $totalOrdersArray);
					$actualUniqueCustomers = array_combine($Labeling, $uniqueCustomers);
					$actualReturningCustomers = array_combine($Labeling, $returningCustomers);
				}

				// combine the hour and sales from the database into one set
				$databaseData = array_combine($Labeling, $amount_discounted);

				// labels check 
				$listing = "list-days";
				if($period == "today") {
					$listing = "hour";
				}

				// if the period is a year
				if(in_array($period, ["this-year", "all-time"])) {
					$listing = "year-to-months";
				}

				// replace the empty fields with 0
				$replaceEmptyField = $this->dateClass->dataDateComparison($listing, $Labeling, array($dateFrom, $dateTo), $this->stringToArray($clientData->shop_opening_days));

				// append the fresh dataset to the old dataset
				$freshData = array_replace($databaseData, $replaceEmptyField);
				ksort($freshData);

				if(in_array("actualsCreditInsight", $this->insightRequest)) {
					$actualSalesRecord = array_replace($actualSalesData, $replaceEmptyField);
					$creditSalesRecord = array_replace($creditSalesData, $replaceEmptyField);
					ksort($creditSalesRecord);
					ksort($actualSalesRecord);
				}

				if(in_array("discountEffectInsight", $this->insightRequest)) {
					$amountNotDiscounted = array_replace($amountNotDiscountedData, $replaceEmptyField);
					ksort($amountNotDiscounted);
				}
				
				// check if customer orders insight is in the insight request array
				if(in_array("costSellingProfitInsight", $this->insightRequest)) {
					$totalCostRecord = array_replace($totalCostData, $replaceEmptyField);
					$totalSellingRecord = array_replace($totalSellingData, $replaceEmptyField);
					$totalProfitRecord = array_replace($totalProfitData, $replaceEmptyField);
					ksort($totalCostRecord);
					ksort($totalSellingRecord);
					ksort($totalProfitRecord);
				}

				// check if customer orders insight is in the insight request array
				if(in_array("customerOrdersInsight", $this->insightRequest)) {
					// fill in the unique customers list
					$totalOrdersDataRecord = array_replace($totalOrderData, $replaceEmptyField);
					$actualUniqueCustomersRecord = array_replace($actualUniqueCustomers, $replaceEmptyField);
					$actualReturningCustomersRecord = array_replace($actualReturningCustomers, $replaceEmptyField);
					ksort($totalOrdersDataRecord);
					ksort($actualUniqueCustomersRecord);
					ksort($actualReturningCustomersRecord);
				}

				// if the sales overview was requested
				if(in_array("salesOverview", $this->insightRequest)) {

					$labelArray = array_keys($freshData);
					$labels = [];
					// confirm which period we are dealing with
					if($listing == "list-days") {
						// change the labels to hours of day
						foreach($labelArray as $key => $value) {
							$labels[] = date("Y-m-d", strtotime($value));
						}
					} elseif($listing == "hour") {
						// change the labels to hours of day
						foreach($labelArray as $key => $value) {
							$labels[] = $this->dateClass->convertToPeriod("hour", $value);
						}
					} elseif($listing == "year-to-months") {
						// change the labels to hours of day
						foreach($labelArray as $key => $value) {
							$labels[] = $this->dateClass->convertToPeriod("month", $value);
						}
					}

					// Parse the amount into the chart array data
					$resultData = array();
					$resultData["type"] = "bar";
					$resultData["fill"] = false;
					$resultData["legend"] = false;
					$resultData["borderColor"] = "#fff";
					$resultData["borderWidth"] = 2;
					$resultData["labeling"] = $labels;
					$resultData["data"] = array_values($freshData);
				}

				if(in_array("customerOrdersInsight", $this->insightRequest)) {
					// if the metric is orders count
					$resultData["orders"] = [
						"count" => $totalOrdersCount,
						"totals" => array_values($totalOrdersDataRecord),
						"unique_customers" => array_values($actualUniqueCustomersRecord),
						"returning_customers" => array_values($actualReturningCustomersRecord)
					];
				}

				// parse the highest sales value
				$resultData["sales"] = [
					'highest' => $clientData->default_currency . number_format($highestSalesValue, 2),
					'lowest' => $clientData->default_currency . number_format($lowestSalesValue, 2)
				];

				if(in_array("paymentOptionsInsight", $this->insightRequest)) {
					$resultData["sales"]['payment_options'] = $paymentBreakdown;
				}

				if(in_array("productsPerformanceInsight", $this->insightRequest)) {
					$resultData["sales"]['products_performance'] = $productsArray;
				}

				$resultData["sales"]['comparison']['total_sales'] = $clientData->default_currency . number_format(array_sum($amount_discounted), 2);

				if(in_array("actualsCreditInsight", $this->insightRequest)) {
					$resultData["sales"]['actuals'] = array_values($actualSalesRecord);
					$resultData["sales"]['credit'] = array_values($creditSalesRecord);
					$resultData["sales"]['comparison'] = [
						'total_actual_sales' => $clientData->default_currency . number_format(array_sum($actualSalesRecord), 2),
						'total_credit_sales' => $clientData->default_currency . number_format(array_sum($creditSalesRecord), 2),
					];
				}

				if(in_array("costSellingProfitInsight", $this->insightRequest)) {
					$resultData["sales"]['revenue'] = [
						'cost' => array_values($totalCostRecord),
						'selling' => array_values($totalSellingRecord),
						'profit' => array_values($totalProfitRecord)
					];
				}

				if(in_array("productCategoryInsight", $this->insightRequest)) {
					$resultData["sales"]['category_sales'] = [
						'labels' => $category_names,
						'data' => $category_amount
					];
				}

				if(in_array("discountEffectInsight", $this->insightRequest)) {
					$resultData["sales"]['discount_effect'] = [
						'with_discount' => array_values($freshData),
						'without_discount' => array_values($amountNotDiscounted),
						'total_sale' => $clientData->default_currency . number_format(array_sum(array_values($amountNotDiscounted)), 2),
						'discounted_sale' => $clientData->default_currency . number_format(array_sum(array_values($freshData)), 2)
					];
				}
				
			}

			// if the metric is to fetch the sales attendants perfomance 
			elseif($metric == 'salesAttendantPerformance') {

				// create a new orders object
				$ordersObj = load_class("Orders", "controllers");

				// if the sales records of attendant is being fetched
				if(isset($_POST['salesAttendantHistory'])) {

					// set the variables
					$userId = (isset($postData->userId)) ? $postData->userId : null;
					$recordType = (isset($postData->recordType)) ? $postData->recordType : null;

					// check which details to fetch
					if($recordType == "customer") {
						$where = "a.customer_id = '{$userId}'";
					} elseif($recordType == "attendant") {
						$where = "a.recorded_by='{$userId}'";
					}

					$dateFrom = (!empty($this->session->queryRange['start'])) ? $this->session->queryRange['start'] : $dateFrom;
					$dateTo = (!empty($this->session->queryRange['end'])) ? $this->session->queryRange['end'] : $dateTo;
					
					// run this query
					$salesAttendants = $this->getAllRows(
						"sales a LEFT JOIN customers b ON b.customer_id=a.customer_id",
						"a.payment_type, a.order_amount_paid, DATE(a.order_date) AS order_date, 
						a.order_id, b.customer_id, b.phone_1, b.email, b.title, 
						a.payment_type, CONCAT(b.firstname, ' ', b.lastname) AS fullname, a.credit_sales
						",
						"{$where} AND a.order_status='confirmed' AND a.deleted='0' AND (DATE(a.order_date) >= '{$dateFrom}' && 
						DATE(a.order_date) <= '{$dateTo}') {$branchAccess} {$this->accessLimit} {$clientAccess} ORDER BY DATE(a.order_date) ASC"
					);

					// set the response data
					$resultData = [];
					$i = 0;
					// loop through the result
					foreach($salesAttendants as $eachSale) {
						
						$i++;
						$eachSale->order_date = $eachSale->order_date;
						$eachSale->totalOrder = $eachSale->order_amount_paid;

						if($rawJSON) {
							$eachSale->saleDetails = $ordersObj->saleDetails($eachSale->order_id, $this->clientId, $this->loggedUserBranchId, $this->loggedUserId);
							$resultData[] = $eachSale;
						} else {
							$resultData[] = [
								'row' => $i,
								'order_id' => "<a onclick=\"return getSalesDetails('{$eachSale->order_id}')\" data-toggle=\"tooltip\" title=\"View Trasaction Details\" href=\"javascript:void(0)\" type=\"button\" class=\"get-sales-details text-success\" data-sales-id=\"{$eachSale->order_id}\">#$eachSale->order_id</a> <br> ".ucfirst($eachSale->payment_type),
								'fullname' => "<a href=\"{$config->base_url('customer-detail/'.$eachSale->customer_id)}\">{$eachSale->title} {$eachSale->fullname}</a>",
								'phone' => $eachSale->phone_1,
								'date' => $eachSale->order_date,
								'amount' => "{$clientData->default_currency} {$eachSale->totalOrder}",
								'action' => "<a title=\"Print Transaction Details\" href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-primary print-receipt\" data-sales-id=\"{$eachSale->order_id}\"><i class=\"fa fa-print\"></i></a> <a data-toggle=\"tooltip\" title=\"Email the Receipt\" href=\"javascript:void(0)\" class=\"btn-outline-info btn btn-sm resend-email\" data-email=\"{$eachSale->email}\" data-name=\"{$eachSale->title} {$eachSale->fullname}\" data-customer-id=\"{$eachSale->customer_id}\" data-sales-id=\"{$eachSale->order_id}\"><i class=\"fa fa-envelope\"></i></a> <a data-toggle=\"tooltip\" title=\"Download Trasaction Details\" href=\"{$config->base_url('export/'.$eachSale->order_id)}\" target=\"_blank\" class=\"btn-outline-success btn btn-sm get-sales-details\" data-sales-id=\"{$eachSale->order_id}\"><i class=\"fa fa-download\"></i></a>",
							];
						}

					}


				} else {

					// access control
					$userLimit = "";
					if(!$this->accessObject->hasAccess('monitoring', 'branches')) {
						$userLimit = "AND a.user_id='{$this->loggedUserId}'";
					}

					// begin the query
					$salesAttendants = $this->getAllRows(
						"users a", 
						"a.user_id, a.daily_target, a.monthly_target, a.weekly_target,
						CONCAT(a.name) AS fullname,
						(
							SELECT 
								CASE WHEN SUM(b.order_amount_paid) IS NULL THEN 0.00 ELSE SUM(b.order_amount_paid) END 
							FROM sales b 
							WHERE 
								b.recorded_by=a.user_id AND b.order_status='confirmed' AND b.deleted='0' AND
								(DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') 
								{$this->branchAccessInner} {$this->clientAccessInner}
						) AS amnt,
						(
							SELECT 
								SUM(b.product_quantity)
							FROM sales_details b
							LEFT JOIN sales c ON c.order_id = b.order_id
							WHERE c.recorded_by = a.user_id AND c.order_status='confirmed'
								AND c.deleted = '0' AND
								(DATE(c.order_date) >= '{$dateFrom}' && DATE(c.order_date) <= '{$dateTo}')
								{$this->branchAccessInner} {$this->clientAccessInner}

						) AS total_items_sold,
						(
							SELECT COUNT(*) FROM sales b 
							WHERE 
								b.recorded_by=a.user_id AND b.order_status='confirmed' AND b.deleted='0' AND
								(DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') 
								{$this->branchAccessInner} {$this->clientAccessInner}
						) AS orders",
						"a.status='1' {$userLimit} {$branchAccess} {$clientAccess} ORDER BY amnt DESC LIMIT 100"
					);
					
					$personnelNames = array();
					$personnelSales = array();
					$attendantSales = array();
					$salesTarget = 0;

					//: loop throught the dataset
					foreach($salesAttendants as $eachPersonnel) {
						//: append the data
						$personnelNames[] = $eachPersonnel->fullname;
						$personnelSales[] = $eachPersonnel->amnt;

						$uName = "<div class='text-left'><a data-name=\"{$eachPersonnel->fullname}\" data-record=\"attendant\" data-value=\"{$eachPersonnel->user_id}\" class=\"view-user-sales font-weight-bold\" href='javascript:void(0)'>{$eachPersonnel->fullname}</a></div>";

						if($period == "today") {
							$salesTarget = $eachPersonnel->daily_target;
						} elseif($period == "this-week") {
							$salesTarget = $eachPersonnel->weekly_target;
						} elseif($period == "this-month" || $period == "same-month-last-year") {
							$salesTarget = $eachPersonnel->monthly_target;
						} elseif($period == "this-year") {
							$salesTarget = ($eachPersonnel->monthly_target * 12);
						}

						$salesTargetPercentage = ($eachPersonnel->amnt > 0 && $salesTarget > 0) ? (($eachPersonnel->amnt / $salesTarget) * 100) : 0; 

						$attendantSales[] = [
							'fullname' => $uName,
							'sales' => [
								'orders' => $eachPersonnel->orders,
								'amount' => $clientData->default_currency.number_format($eachPersonnel->amnt, 2),
								'average_sale' => ($eachPersonnel->orders > 0) ? $clientData->default_currency.number_format(($eachPersonnel->amnt/$eachPersonnel->orders), 2) : $clientData->default_currency."0.00"
							],
							'items' => [
								'total_items_sold' => (int) $eachPersonnel->total_items_sold,
								'average_items_sold' => ($eachPersonnel->orders > 1) ? number_format($eachPersonnel->total_items_sold / $eachPersonnel->orders) : 0
							],
							'targets' => [
								'target_amount' => $clientData->default_currency.number_format($salesTarget, 2),
								'target_percent' => number_format($salesTargetPercentage, 2)."%"
							]
						];
					}

					$resultData = [
						'list' => $attendantSales,
						'names' => $personnelNames,
						'sales' => $personnelSales
					];

				}
			
			}

			// if the metric is to fetch the top contacts performance
			elseif($metric == 'topCustomersPerformance') {

				if(empty($this->session->reportingCustomerId)) {
					// Fetch the list of contacts and order total amounts
					$contactsPerformance = $this->db->prepare("
						SELECT
							a.customer_id, CONCAT(a.firstname, ' ', a.lastname) AS customer_name, c.branch_name AS outlet_name, 
							a.phone_1, a.phone_2, a.email, 
							(
							    SELECT 
							    	CASE WHEN SUM(b.order_amount_paid) IS NULL THEN 0.00 ELSE SUM(b.order_amount_paid) END
							   	FROM
							    	sales b
							    WHERE
							    	b.order_status='confirmed'
							    	AND b.deleted='0' 
							    	AND (DATE(b.order_date) >= '{$dateFrom}' 
							    	AND DATE(b.order_date) <= '{$dateTo}')
							    	AND b.customer_id = a.customer_id
							    	{$this->accessLimitInner} {$this->branchAccessInner} {$this->clientAccessInner}
							) AS total_amount,
							(
							    SELECT 
							    	CASE WHEN SUM(b.order_amount_balance) IS NULL THEN 0 ELSE SUM(b.order_amount_balance) END
							   	FROM
							    	sales b
							    WHERE
							    	b.order_status='confirmed' 
							    	AND b.deleted='0'
							    	AND (DATE(b.order_date) >= '{$dateFrom}' 
							    	AND DATE(b.order_date) <= '{$dateTo}')
							    	AND b.customer_id = a.customer_id
							    	{$this->accessLimitInner} {$this->branchAccessInner} {$this->clientAccessInner}
							) AS total_balance,
							(
							    SELECT 
							    	COUNT(*)
							   	FROM
							    	sales b
							    WHERE
							    	b.order_status='confirmed' 
							    	AND b.deleted='0'
							    	AND (DATE(b.order_date) >= '{$dateFrom}' 
							    	AND DATE(b.order_date) <= '{$dateTo}')
							    	AND b.customer_id = a.customer_id
							    	{$this->accessLimitInner} {$this->branchAccessInner} {$this->clientAccessInner}
							) AS orders_count

						FROM
							customers a
						LEFT JOIN branches c ON c.id = a.branchId
						WHERE
							1 {$branchAccess} {$clientAccess}
						ORDER BY total_amount DESC LIMIT {$customerListLimit}
					");
					$contactsPerformance->execute();

					$resultData = [];
					$row = 0;
					// set the response data
					while($result = $contactsPerformance->fetch(PDO::FETCH_OBJ)) {
						
						if($rawJSON) {
							$resultData[] = $result;
						} else {
							if($result->total_amount > 0) {
								$row++;
								$result->row_id = $row;
								$result->fullname = "<a href=\"{$config->base_url('customer-detail/'.$result->customer_id)}\" title=\"Click to list customer orders history\" data-value=\"{$result->customer_id}\" class=\"customer-orders\" data-name=\"{$result->customer_name}\">{$result->customer_name}</a>";

								$result->action = "<a href=\"javascript:void(0);\" title=\"Click to list customer orders history\" data-name=\"{$result->customer_name}\" data-record=\"customer\" data-value=\"{$result->customer_id}\" class=\"view-user-sales btn btn-sm btn-outline-success\"><i class=\"fa fa-list\"></i></a> <a href=\"{$config->base_url('customer-detail/'.$result->customer_id)}\" title=\"Click to list customer orders history\" data-name=\"{$result->customer_name}\" data-record=\"customer\" data-value=\"{$result->customer_id}\" class=\"btn btn-sm btn-outline-primary\"><i class=\"fa fa-chart-bar\"></i></a>";
								// calculate the average purchase amount
								$result->average_purchase = "{$clientData->default_currency} ".(($result->total_amount > 0) ? number_format(($result->total_amount/$result->orders_count),2) : 0);
								$result->total_amount = "{$clientData->default_currency} ".number_format($result->total_amount, 2);
								$resultData[] = $result;
							}
						}

					}
				}

			}

			// if the metric is to fetch the performance of various branches
			elseif($metric == 'branchPerformance') {
				
				//: fetch the dataset
				$stmt = $this->db->prepare("
					SELECT 
						a.branch_name, a.square_feet_area,
						(
							SELECT 
								COUNT(*)
							FROM sales b
							WHERE b.branchId = a.id AND b.deleted='0'
								AND (DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') AND order_status='confirmed'
								{$this->accessLimitInner} {$this->clientAccessInner}
						) AS orders_count,
						(
							SELECT 
								ROUND(SUM(b.order_amount_paid) ,2) 
							FROM sales b
							WHERE b.branchId = a.id AND b.deleted='0'
								AND (DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') AND order_status='confirmed'
								{$this->accessLimitInner} {$this->clientAccessInner}
						) AS total_sales,
						(
							SELECT 
								ROUND(MAX(b.order_amount_paid) ,2)
							FROM sales b
							WHERE b.branchId = a.id AND b.deleted='0' 
								AND (DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') AND order_status='confirmed'
								{$this->accessLimitInner} {$this->clientAccessInner}
						) AS highest_sales,
						(
							SELECT 
								ROUND(MIN(b.order_amount_paid) ,2) 
							FROM sales b
							WHERE b.branchId = a.id AND b.deleted='0'
								AND (DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') AND order_status='confirmed'
								{$this->accessLimitInner} {$this->clientAccessInner}
						) AS lowest_sales,
						(
							SELECT 
								ROUND(AVG(b.order_amount_paid) ,2)
							FROM sales b
							WHERE b.branchId = a.id AND b.deleted='0'
								AND (DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') AND order_status='confirmed'
								{$this->accessLimitInner} {$this->clientAccessInner}
						) AS average_sales,
						(
							SELECT COUNT(*) FROM customers b  
							WHERE b.branchId = a.id AND b.status='1'
						) AS customers_count,
						(
							SELECT COUNT(*) FROM products b  
							WHERE b.branchId = a.id AND b.status='1'
						) AS products_count
					FROM branches a
					WHERE 1 {$this->branchAccess2} {$clientAccess}
						ORDER BY total_sales ASC
				");
				$stmt->execute();

				//: fetch the other totals
				$chart_stmt = $this->db->prepare("
					SELECT 
						SUM(a.order_amount_paid) AS amount, 
						DATE(a.order_date) AS dates,
						HOUR(a.order_date) AS hourOfDay,
						MONTH(a.order_date) AS monthOfSale
					FROM sales a
					WHERE 
						a.order_status = 'confirmed' AND a.deleted = '0' AND  
						(DATE(a.order_date) >= '{$dateFrom}' AND DATE(a.order_date) <= '{$dateTo}') 
						{$branchAccess} {$this->accessLimit} {$clientAccess} {$groupByClause}
				");
				$chart_stmt->execute();

				// Check If User ID Exists
				$branchesList = $this->getAllRows("branches", "branch_name, id", "clientId='{$this->clientId}' AND deleted='0'");

				// set some array functions
				$Labeling = array();
				$amount = array();
				$summary = [];
				$lowestSalesValue = 0;
				$highestSalesValue = 0;

				//: using while
				while($chart_result = $chart_stmt->fetch(PDO::FETCH_OBJ)) {
					
					// confirm which record to fetch
					if($period == "today") {
						$Labeling[] = $chart_result->hourOfDay;
					} elseif($period == "this-year") {
						$Labeling[] = $chart_result->monthOfSale;
					} else {
						$Labeling[] = date('Y-m-d', strtotime($chart_result->dates));
					}
					$amount[] = $chart_result->amount;
				}

				//: branch summaries
				while($result = $stmt->fetch(PDO::FETCH_OBJ)) {
					$result->square_feet_sales = ($result->square_feet_area > 0) ? $clientData->default_currency.number_format(($result->total_sales / $result->square_feet_area), 2) : 0;
					$result->lowest_sales = $clientData->default_currency . (!empty($result->lowest_sales) ? number_format($result->lowest_sales, 2) : 0);
					$result->average_sale_per_customer = $clientData->default_currency.(($result->total_sales > 0) ? number_format($result->total_sales/$result->customers_count, 2) : 0.00);
					$result->highest_sales = $clientData->default_currency . (!empty($result->highest_sales) ? number_format($result->highest_sales, 2) : 0);
					$result->average_sales = $clientData->default_currency.(!empty($result->average_sales) ? number_format($result->average_sales, 2) : 0);
					$result->total_sales = $clientData->default_currency . (!empty($result->total_sales) ? number_format($result->total_sales, 2) : 0);
					
					$summary[] = $result;
				}

				// chart data
				$chartData = array();
				$chartData["type"] = "bar";
				$chartData["fill"] = false;
				$chartData["legend"] = false;
				$chartData["borderColor"] = "#fff";
				$chartData["borderWidth"] = 2;
				$chartData["data"] = $amount;
				$chartData["labels"] = $Labeling;

				$resultData = [
					'summary' => $summary,
					'sales_overview' => $chartData
				];
			}

			// set the query range in an array
			$queryRange = [
				'start' => $dateFrom, 'end' => $dateTo
			];

			// set the data query range in a session
			$this->session->set_userdata('queryRange', $queryRange);

		}

		// Set the response data to display
		return [
			'status' => 200,
			'request' => $metric,
			'result' => $resultData,
			'date_range' => json_encode($queryRange)
		];

    }

    /**
     * Process the dashboard analytics data
     * 
     * @param object $clientData
     * @param object $setupInfo
     * @param object $expiredAccount
     * 
     * @return array|object
     */
    public function dashboardAnalytics($clientData, $setupInfo, $expiredAccount, $requestInfo) {

        // set the client data variable information
        $this->setVariables($clientData, $_POST);

        global $config;

        //: if the request is from an api request then push only json raw data
	    $rawJSON = isset($this->apiAccessValues->branchId) ? true : false;

        //: where clause for the user role
        $branchAccess = '';
        $clientAccess = " AND a.clientId = '{$this->clientId}'";

        //: if the request is from an api request then push only json raw data
	    $rawJSON = isset($this->apiAccessValues->branchId) ? true : false;

        //: use the access level for limit contents that is displayed
        if(!$this->accessObject->hasAccess('monitoring', 'branches')) {
            $branchAccess = " AND a.branchId = '{$this->loggedUserBranchId}'";
        }

        if ($requestInfo === "getSales") {

			$period = isset($_POST['salesPeriod']) ? xss_clean($_POST['salesPeriod']) : "today";
			$branchId = !empty($_POST['salesBranch']) ? $_POST['salesBranch'] : null;

			$this->session->set_userdata("reportPeriod", $period);
			
			// set the range in a session
			if(!empty($branchId)) {
				$this->session->set_userdata("reportBranch", $branchId);
				$branchAccess = " AND a.branchId = '{$branchId}'";
			} else {
				$this->session->set_userdata("reportBranch", null);
			}

			$period = ($expiredAccount) ? "this-week" : $period;

			$ordersObj = load_class('Orders', 'controllers');

			// Check Sales Period
			switch ($period) {
				case 'this-week':
					$dateFrom = $this->dateClass->get_week("this_wkstart", date('Y-m-d'));
					$dateTo = $this->dateClass->get_week("this_wkend", date('Y-m-d'));
					$datePrevFrom = $this->dateClass->get_week("last_wkstart", date('Y-m-d'));
					$datePrevTo = $this->dateClass->get_week("last_wkend", date('Y-m-d'));
					$display = "Last Week";
					break;
				case 'this-month':
					$dateFrom = $this->dateClass->get_month("this_mntstart", date('m'), date('Y'));
					$dateTo = $this->dateClass->get_month("this_mntend", date('m'), date('Y'));
					$datePrevFrom = $this->dateClass->get_month("last_mntstart", date('m'), date('Y'));
					$datePrevTo = $this->dateClass->get_month("last_mntend", date('m'), date('Y'));
					$display = "Last Month";
					break;
				case 'this-year':
					$dateFrom = date('Y-01-01');
					$dateTo = date('Y-12-31');
					$datePrevFrom = date('Y-01-01', strtotime("first day of last year"));
					$datePrevTo = date('Y-12-31', strtotime("last day of last year"));
					$display = "Last Year";
					break;
				default:
					$dateFrom = date('Y-m-d');
					$dateTo = date('Y-m-d');
					$datePrevFrom = date('Y-m-d', strtotime("-1 day"));
					$datePrevTo = date('Y-m-d', strtotime("-1 day"));
					$display = "Yesterday";
					break;
			}

			$totalDiscount = 0;
			$totalSales = 0;
			$totalServed= 0;
			$totalProducts = 0;
			$totalProductsWorth = 0;
			$totalCreditSales = 0;
			$averageSalesValue = 0;
			$totalCostPrice = 0;
			$totalSellingPrice = 0;
			$totalProfit = 0;
			$creditTotalProfitMade = 0;
			$creditTotalDiscountGiven = 0;

			$sales = $this->getAllRows(
				"sales a LEFT JOIN customers b ON a.customer_id = b.customer_id", 
				"a.*, b.title, b.firstname, b.lastname, b.phone_1,
				b.email,
					(
						SELECT MAX(b.order_amount_paid) FROM sales b WHERE b.deleted='0' AND 
						(DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') {$this->branchAccessInner} {$this->accessLimitInner} {$this->customerLimitInner} {$this->clientAccessInner}
					) AS highestSalesValue,
					(
						SELECT SUM(b.order_amount_paid) FROM sales b WHERE b.deleted='0' AND
						credit_sales = '1' AND 
						(DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') {$this->branchAccessInner} {$this->accessLimitInner} {$this->customerLimitInner} {$this->clientAccessInner}
					) AS totalCreditSales,
					(
						SELECT AVG(b.order_amount_paid) FROM sales b WHERE b.deleted='0' AND
						(DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') {$this->branchAccessInner} {$this->accessLimitInner} {$this->customerLimitInner} {$this->clientAccessInner}
					) AS averageSalesValue,
					(
						SELECT SUM(b.product_cost_price * b.product_quantity) FROM sales_details b WHERE
						(DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') 
						AND b.order_id = a.order_id
						{$this->branchAccessInner} {$this->accessLimit} {$this->clientAccessInner}
					) AS totalCostPrice,
					(
						SELECT SUM(b.product_unit_price * b.product_quantity) AS selling_price FROM sales_details b WHERE
						b.order_id = a.order_id AND
						(DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') {$this->branchAccessInner} {$this->accessLimit} {$this->clientAccessInner}
					) AS totalSellingPrice,
					(
						SELECT SUM((b.product_unit_price * b.product_quantity)-(b.product_cost_price * b.product_quantity)) AS order_profit FROM sales_details b WHERE
						b.order_id = a.order_id AND 
						(DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}') 
						{$this->branchAccessInner} {$this->accessLimit} {$this->clientAccessInner}
					) AS totalProfitMade,
					(
						SELECT SUM((b.product_unit_price * b.product_quantity)-(b.product_cost_price * b.product_quantity))-(a.order_discount) AS order_profit FROM sales_details b WHERE 
						b.order_id = a.order_id AND a.payment_type='credit' AND
						(DATE(b.order_date) >= '{$dateFrom}' && DATE(b.order_date) <= '{$dateTo}')
						{$this->branchAccessInner} {$this->accessLimit} {$this->clientAccessInner}
				    ) AS creditTotalProfitMade
				", 
				"a.order_status = 'confirmed' && a.deleted = '0' && (DATE(a.order_date) >= '{$dateFrom}' && DATE(a.order_date) <= '{$dateTo}') {$this->accessLimit} {$branchAccess} {$clientAccess} ORDER BY a.order_date DESC"
			);

			$message = [];

			// confirm that the result is not empty
			if ($sales != false && count($sales) > 0) {
				$i = 0;
				$results = [];
				
				// loop through the data submitted from the query
				foreach ($sales as $data) {
					$i++;

					// format the order date
					$orderDate = date('jS F Y h:iA', strtotime($data->order_date));
					$totalOrder= $this->toDecimal($data->order_amount_paid, 2, ',');

					if($rawJSON) {
						$results[] = [
							'id' => $data->id,
							'source' => $data->source,
							'branchId' => $data->branchId,
							'customer_id' => $data->customer_id,
							'order_status' => $data->order_status,
							'saleDetails' => $ordersObj->saleDetails($data->order_id, $this->clientId, $this->loggedUserBranchId, $this->loggedUserId)
						];
					} else {
						// parse this data if the request is from the website
						$results[] = [
							'row' => "$i.",
							'order_id' => "<a onclick=\"return getSalesDetails('{$data->order_id}')\" data-toggle=\"tooltip\" title=\"View Trasaction Details\" 
                                href=\"javascript:void(0)\" type=\"button\" 
                                class=\"get-sales-details text-success\" data-sales-id=\"{$data->order_id}\">#$data->order_id</a> <br> ".ucfirst($data->payment_type),
							'fullname' => "<a href=\"{$config->base_url('customer-detail/'.$data->customer_id)}\">{$data->title} {$data->firstname} {$data->lastname}</a>",
							'phone' => $data->phone_1,
							'date' => $orderDate,
							'amount' => "{$clientData->default_currency} {$totalOrder}",
							'action' => "<a title=\"Print Transaction Details\" href=\"javascript:void(0);\" class=\"btn btn-sm btn-outline-primary print-receipt\" 
                                data-sales-id=\"{$data->order_id}\"><i class=\"fa fa-print\"></i></a> <a data-toggle=\"tooltip\" title=\"Email the Receipt\" 
                                href=\"javascript:void(0)\" class=\"btn-outline-info btn btn-sm resend-email\" data-email=\"{$data->email}\" 
                                data-name=\"{$data->title} {$data->firstname} {$data->lastname}\" data-customer-id=\"{$data->customer_id}\" 
                                data-sales-id=\"{$data->order_id}\"><i class=\"fa fa-envelope\"></i></a> 
                                <a data-toggle=\"tooltip\" title=\"Download Trasaction Details\" href=\"{$config->base_url('export/'.$data->order_id)}\" 
                                    target=\"_blank\" class=\"btn-outline-success btn btn-sm get-sales-details\" data-sales-id=\"{$data->order_id}\"><i class=\"fa fa-download\"></i></a>",
						];
					}

					// perform some calculations
					$totalDiscount += $data->order_discount;
					$totalCostPrice += $data->totalCostPrice;
					$totalSellingPrice += $data->totalSellingPrice;
					$totalProfit += $data->totalProfitMade;
					$totalSales += $data->order_amount_paid;
					$totalServed += 1;
					$creditTotalProfitMade += $data->creditTotalProfitMade;
					
					$totalProductsWorth = 0;
					$totalCreditSales = $data->totalCreditSales;

				}
				$message = $results;
				$status = true;
			}

			$averageSalesValue = ($totalSales > 0 && $totalServed > 0) ? ($totalSales / $totalServed) : 0.00;

			// run this section if the user wants more details
			if(!$this->session->limitedData) {

				// query the previous period data sets
				$prevSales = $this->getAllRows(
					"sales a", 
					"
						COUNT(*) AS totalPrevServed, SUM(a.order_amount_paid) AS totalPrevSales,
						SUM(a.order_discount) AS totalDiscountGiven,
						(
							SELECT MAX(b.order_amount_paid) FROM sales b WHERE b.deleted = '0' &&
							(DATE(b.order_date) >= '{$datePrevFrom}' && DATE(b.order_date) <= '{$datePrevTo}') {$this->branchAccessInner} {$this->customerLimitInner}
							{$this->accessLimitInner} {$this->clientAccessInner}
						) AS highestSalesValue,
						(
							SELECT SUM(b.order_amount_paid) FROM sales b WHERE
							 b.deleted = '0' &&
							credit_sales = '1' AND
							(DATE(b.order_date) >= '{$datePrevFrom}' && DATE(b.order_date) <= '{$datePrevTo}') {$this->branchAccessInner} {$this->accessLimitInner} {$this->customerLimitInner} {$this->clientAccessInner}
						) AS totalPrevCreditSales,
						(
							SELECT AVG(b.order_amount_paid) FROM sales b WHERE 
							 b.deleted = '0' &&
							(DATE(b.order_date) >= '{$datePrevFrom}' && DATE(b.order_date) <= '{$datePrevTo}') {$this->branchAccessInner} {$this->customerLimitInner}
							{$this->accessLimitInner} {$this->clientAccessInner}
						) AS averageSalesValue,
						(
							SELECT SUM(b.product_cost_price * b.product_quantity) FROM sales_details b WHERE
							(DATE(b.order_date) >= '{$datePrevFrom}' && DATE(b.order_date) <= '{$datePrevTo}') {$this->branchAccessInner} {$this->accessLimit} {$this->clientAccessInner}
						) AS totalCostPrice,
						(
							SELECT SUM(b.product_unit_price * b.product_quantity) AS selling_price FROM sales_details b WHERE
							(DATE(b.order_date) >= '{$datePrevFrom}' && DATE(b.order_date) <= '{$datePrevTo}') {$this->branchAccessInner} {$this->accessLimit} {$this->clientAccessInner}
						) AS totalSellingPrice,
						(
							SELECT SUM((b.product_unit_price * b.product_quantity)-(b.product_cost_price * b.product_quantity)) AS order_profit FROM sales_details b WHERE
							(DATE(b.order_date) >= '{$datePrevFrom}' && DATE(b.order_date) <= '{$datePrevTo}') {$this->branchAccessInner} {$this->accessLimit} {$this->clientAccessInner}
						) AS totalProfitMade
					", 
					"a.order_status = 'confirmed' && a.deleted = '0' {$this->accessLimit} && DATE(a.order_date) >= '{$datePrevFrom}' && DATE(a.order_date) <= '{$datePrevTo}' {$branchAccess} {$clientAccess}"
				);
				
				// if the previous sales query is not false
				if ($prevSales != false) {
					$prevSales = $prevSales[0];

					$totalSellingTrend = $this->percentDifference(floatval($totalSellingPrice), floatval($prevSales->totalSellingPrice));
					$totalCostTrend = $this->percentDifference(floatval($prevSales->totalCostPrice), floatval($totalCostPrice));
					$totalProfitTrend = $this->percentDifference(floatval($totalProfit-$totalDiscount), floatval($prevSales->totalProfitMade-$prevSales->totalDiscountGiven));
					$totalServedTrend = $this->percentDifference(floatval($totalServed), floatval($prevSales->totalPrevServed));
					$totalSalesTrend = $this->percentDifference(floatval($totalSales), floatval($prevSales->totalPrevSales));
					$totalCreditTrend = $this->percentDifference(floatval($totalCreditSales), floatval($prevSales->totalPrevCreditSales));
					$totalPercent = (!empty($totalCreditSales) && !empty($totalSales)) ? round(($totalCreditSales/$totalSales)*100, 2) : 0.00;
					$averageSalesTrend = $this->percentDifference(floatval($averageSalesValue), floatval($prevSales->averageSalesValue));
					$totalDiscountTrend = $this->percentDifference(floatval($totalDiscount), floatval($prevSales->totalDiscountGiven));
				}
			
				//: additional calculations
				$creditProfitPercentage = ($creditTotalProfitMade > 0) ? (($creditTotalProfitMade / $totalProfit) * 100) : 0;
				$creditProfitPercentage = number_format($creditProfitPercentage, 2);
			}

			$status = true;
			
			//: print the result
			$response = [
				"message" => [
					"sales_history" => $message
				],
				"status"  => $status
			];


			// set the new response to be parsed
			if(!$this->session->limitedData) {
				$response["message"] = [ 
					"sales_history" => $message,
					"totalSales" => [
						"total" => $clientData->default_currency . $this->toDecimal($totalSales, 2, ','), 
						"trend" => $totalSalesTrend ." ". $display
					],
					"totalServed" => [
						"total" => $this->toDecimal($totalServed, 0, ','),
						"trend" => $totalServedTrend ." ". $display
					],
					"totalDiscount" => [
						"total" => $clientData->default_currency . $this->toDecimal($totalDiscount, 0, ','),
						"trend" => $totalDiscountTrend ." ". $display
					],
					"averageSales" => [
						"total"	=> $clientData->default_currency . number_format($averageSalesValue, 2),
						"trend" => $averageSalesTrend ." ". $display
					],
					"totalCredit" => [
						"total" => $clientData->default_currency . $this->toDecimal($totalCreditSales, 2, ','),
						"trend" =>  "<span class='text-gray'>{$totalPercent}% of Total Sales ({$creditProfitPercentage}% of profit)</span>"
					],
					"salesComparison" => [
						"profit" => $clientData->default_currency . $this->toDecimal(($totalProfit-$totalDiscount), 2, ','),
						"profit_trend" =>  $totalProfitTrend ." ". $display,
						"selling" => $clientData->default_currency . $this->toDecimal($totalSellingPrice, 2, ','),
						"selling_trend" =>  $totalSellingTrend ." ". $display,
						"cost" => $clientData->default_currency . $this->toDecimal($totalCostPrice, 2, ','),
						"cost_trend" =>  $totalCostTrend ." ". $display
					]
				];
			}
			
		} else if ($requestInfo === "getSalesDetails") {

            //: initializing
            $response = (object) [
                "status" => "error", 
                "message" => "Error Processing The Request"
            ];

			if (!empty($_POST['salesID'])) {

				$ordersObj = load_class('Orders', 'controllers');

				$postData = (OBJECT) array_map("xss_clean", $_POST);

				$query = $this->getAllRows(
					"sales_details b
						LEFT JOIN sales a ON a.order_id = b.order_id
						LEFT JOIN products c ON b.product_id = c.id 
						LEFT JOIN customers d ON a.customer_id = d.customer_id
						LEFT JOIN branches e ON e.id = a.branchId
						LEFT JOIN users f ON f.user_id = a.recorded_by
					", 
					"c.product_title, b.*, a.order_discount,
					CONCAT(d.firstname ,' ', d.lastname) AS fullname,
					a.order_date, a.order_id, d.phone_1 AS contact,
					a.payment_type, e.branch_name, f.name AS sales_person
					", 
					"a.clientId = '{$this->clientId}' && b.order_id = '{$postData->salesID}' ORDER BY b.id"
				);

				if ($query != false) {

					if($rawJSON) {
						$response->message = [];
						$response->result = $ordersObj->saleDetails($query[0]->order_id, $this->clientId, $this->loggedUserBranchId, $this->loggedUserId);
					} else {
						$subTotal = 0;

						$message = "
						<div class=\"row table-responsive\">
							<table class=\"table table-bordered\">
								<tr>
									<td colspan='2' class='text-center'>
										<strong>Served By: </strong> {$query[0]->sales_person}<br>
										<strong>Point of Sale: </strong> {$query[0]->branch_name}
									</td>
								</tr>
								<tr>
									<td><strong>Customer Name</strong>: {$query[0]->fullname}</td>
									<td align='left'><strong>Transaction ID:</strong>: {$postData->salesID}</td>
								</tr>
								<tr>
									<td><strong>Contact</strong>: {$query[0]->contact}</td>
									<td align='left'><strong>Transaction Date</strong>: {$query[0]->order_date}</td>
								</tr>
							</table>
			                <table class=\"table table-bordered\">
								<thead>
									<tr>
										<td class=\"text-left\">Product</td>
										<td class=\"text-left\">Quantity</td>
										<td class=\"text-right\">Unit Price</td>
										<td class=\"text-right\">Total</td>
									</tr>
								</thead>
								<tbody>";

						foreach ($query as $data) {
							$productTotal = $this->toDecimal($data->product_total, 2, ',');
							$message .= "
								<tr>
									<td>{$data->product_title}</td>
									<td>{$data->product_quantity}</td>
									<td class=\"text-right\">{$clientData->default_currency} {$data->product_unit_price}</td>
									<td class=\"text-right\">{$clientData->default_currency} {$productTotal}</td>
								</tr>";

							$subTotal += $data->product_total;
							$discount = $this->toDecimal($data->order_discount, 2, ',');
						}
						$overall = $this->toDecimal($subTotal - $discount, 2, ',');
						$message .= "
							<tr>
								<td style=\"font-weight:bolder;text-transform:uppercase\" colspan=\"3\" class=\"text-right\">Subtotal</td>
								<td style=\"font-weight:bolder;text-transform:uppercase\" class=\"text-right\">
									{$clientData->default_currency} ".$this->toDecimal($subTotal, 2, ',')."
								</td>
							</tr>
							<tr>
								<td style=\"font-weight:;text-transform:uppercase\" colspan=\"3\" class=\"text-right\">Discount</td>
								<td style=\"font-weight:;text-transform:uppercase\" class=\"text-right\">{$clientData->default_currency} {$discount}</td>
							</tr>
							<tr>
								<td style=\"font-weight:bolder;text-transform:uppercase\" colspan=\"3\" class=\"text-right\">Overall Total</td>
								<td style=\"font-weight:bolder;text-transform:uppercase\" class=\"text-right\">{$clientData->default_currency} {$overall}</td>
							</tr>
						";

						$message .= "</tbody>
							</table>
						</div>";

						$response->result = $message;
					}

					$response->status = true;
				}

			}
		}

        return $response;
        
    }
    
}
?>