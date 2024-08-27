<?php
$PAGETITLE = "Sales History";

// include the important files
require_once "headtags.php";

global $branchesList;

$session->limitedData = true;
$session->insightRequest = false;
?>
<!-- Page Content-->
<!-- Header -->
<div class="header <?= $clientData->bg_color ?> pb-6">
  <div class="container-fluid">
    <div class="header-body">
      <div class="row align-items-center py-4">
        <div class="col-lg-6 col-7">
          <nav aria-label="breadcrumb" class="d-none d-md-inline-block ml-md-4">
            <ol class="breadcrumb breadcrumb-links breadcrumb-dark">
              <li class="breadcrumb-item"><a href="<?= $baseUrl ?>"><i class="fas fa-home"></i> Dashboard</a></li>
              <li class="breadcrumb-item"><a href="javascript:void(0)"><?= $PAGETITLE ?></a></li>
            </ol>
          </nav>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Page content -->
<div class="container-fluid mt--6">
  
  <div class="row">
      
      <div class="col-md-6"></div>
      <style>
        .select2-container .select2-selection--single {
          line-height: 2.5;
          height: 54px;
        }
      </style>
      <div class="col-md-3 mb-2">
        <select style="max-width: 400px" class="form-control selectpicker" name="selected_branch" id="selected_branch">
          <option value="">Select Branch</option>
          <?php foreach($branchesList as $branch) {
            if(strtolower($branch->branch_type) !== 'store') continue;
            ?>
            <option <?= $session->reportBranch == $branch->id ? "selected" : null; ?> value="<?= $branch->id ?>">
              <?= $branch->branch_name ?> (<?= $branch->location ?>)
            </option>
          <?php } ?>
        </select>
      </div>
      <div class="col-md-3 mb-2">
        <select class="form-control selectpicker" name="periodSelected">
            <?php foreach($filterPeriod as $key => $value) { ?>
            <option <?= ($session->reportPeriod == $key) ? "selected" : null ?> value="<?= $key ?>"><?= $value ?></option>
            <?php } ?>
        </select>
      </div> <!--end col-->
      <div class="col-lg-1 mb-2 hidden">
          <button class="btn btn-block btn-primary"><i class="fa fa-filter"></i></button>
      </div>
  

      <div class="col-lg-12 col-sm-12 overallSalesHistory">
          <div class="card">
              <div class="card-body table-responsive">
                  <table data-content="non-filtered" class="table nowrap datatable-buttons salesLists" style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                      <thead class="thead-light">
                          <tr>
                              <th>ID</th>
                              <th>Transaction ID</th>
                              <th>Customer</th>
                              <th>Contact</th>
                              <th>Date</th>
                              <th>Sales Value</th>
                              <th></th>
                          </tr>
                      </thead>
                      <tbody></tbody>
                  </table>
              </div>
          </div>
      </div>
    
  </div><!--end row-->
<?php require_once 'foottags.php'; ?>
<?php if($session->accountExpired) { ?>
<script>
  $(`select[name="periodSelected"]`).prop('disabled', true);
</script>
<?php } ?>
</body>
</html>