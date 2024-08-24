<?php 
/**
 * @class Users
 * Extends the POS Super Class
 * @desc Controls all aspects of Users Management
 **/
class Users extends Pos {

	public $accessObject;
	public $apiAccessValues;
	public $insightRequest;

	public function __construct() {
		parent::__construct();
	}
	
	/**
	 * @method addAccount
	 * @desc Insert a new User Account
	 * @param $postData
	 * @return bool
	 **/
	public function addAccount(stdClass $postData) {

		try {

			//: Load the access level details
			$accessQuery = $this->pushQuery("*", "access_levels", "id='{$postData->user_type}'")[0];

			//: insert the user record
			$stmt = $this->db->prepare("
				INSERT INTO users SET fullname = ?, access_level = ?, unique_id = ?, 
					username = ?, password = ?, created_by = ?, clientId = ?
			");
			$stmt->execute([
				$postData->fullname,
				$accessQuery->access_name, $postData->unique_id, $postData->username,
				password_hash($postData->password, PASSWORD_DEFAULT),
				$this->session->userId, $this->clientId
			]);

			//: get the access level permissions
			$accessLevel = $accessQuery->default_permissions;

			//: insert the user access permissions
			$this->db->query("INSERT INTO user_roles SET user_id = '{$postData->unique_id}', permissions='{$accessLevel}'");

			/**
			 * @method userLogs - Update the user activity 
			 **/
			$this->userLogs('user', $postData->unique_id, 'Added a new User Account Details');

			return true;
		} catch(PDOException $e) {
			return false;
		}
	}

	/**
	 * @method updateAccountName
	 * @desc This method updates the fullname of the user
	 * @param stdClass $postData
	 * @param string $postData->fullname This is the fullname of the user
	 * @param string $postData->unique_id This the unique id of the user
	 * @return bool
	 **/
	public function updateAccountName(stdClass $postData) {

		try {

			$stmt = $this->db->prepare("UPDATE users SET fullname = ? WHERE unique_id = ?");
			return $stmt->execute([$postData->fullname, $postData->unique_id]);

		} catch(PDOException $e) {
			return false;
		}
	}


	/**
	 * @method updateStatus
	 * @desc Update the user status
	 * @param $postData
	 * @return bool
	 **/
	public function updateStatus(stdClass $postData) {

		try {

			$stmt = $this->db->prepare("UPDATE users SET status = ? WHERE unique_id = ? AND clientId = ?");
			return $stmt->execute([
				$postData->status, $postData->unique_id, $this->clientId
			]);

		} catch(PDOException $e) {
			return false;
		}
	}


	/**
	 * @method updatePassword
	 * @desc This method updates the password of a user
	 * @return bool
	 **/
	public function updatePassword($postData) {
		/* Update the user password */
		$stmt = $this->db->prepare("
			UPDATE users SET password = ? WHERE unique_id = ? AND clientId = ?
		");
		return $stmt->execute([
			password_hash($postData->password, PASSWORD_DEFAULT), 
			$postData->unique_id, $this->clientId
		]);
	}

	/**
	 * User management processing
	 * 
	 * @param object $clientData
	 * @param object $requestInfo
	 * 
	 * @return array
	 */
	public function userManagement($clientData, $requestInfo, $setupInfo = null) {

		// set the limit
		$limit = (isset($_POST["limit"])) ? (int) $_POST["limit"] : $this->data_limit;

		$status = false;
		$message = [];

		// set the logged in user id
        $loggedUserClientId = isset($this->apiAccessValues->clientId) ? xss_clean($this->apiAccessValues->clientId) : $this->session->clientId;
        $loggedUserId = (isset($apiAccessValues->userId)) ? xss_clean($apiAccessValues->userId) : $this->session->userId;


		//: fetch the list of all users
		if (isset($_POST['fetchUsersLists']) && $requestInfo === 'fetchUsersLists') {

			$condition = "";
			$message = [];

			$query = $this->db->prepare("
				SELECT a.*, b.access_name, c.branch_name 
				  FROM users a 
			INNER JOIN access_levels b
					ON a.access_level = b.id
			INNER JOIN branches c
					ON c.id = a.branchId
				 WHERE a.clientId = ? && a.status = ? {$condition}
			LIMIT {$limit}
			");

			if ($query->execute([$loggedUserClientId, '1'])) {
				$i = 0;

				// get the branches list
				$branches = $this->getBranches($loggedUserClientId);

				// loop through the users list
				while ($data = $query->fetch(PDO::FETCH_OBJ)) {
					$i++;

					$date = date('jS F, Y', strtotime($data->created_on));

					$action = '<div width="100%" align="center">';

					if($this->accessObject->hasAccess('update', 'users')) {
						if(in_array($data->access_level, [1, 2]) && (in_array($this->session->accessLevel, [1, 2]))) {
								$action .= "<button class=\"btn btn-sm btn-outline-success edit-user\" data-user-id=\"{$data->user_id}\">
								<i class=\"fa fa-edit\"></i>
							</button> ";
						} elseif(!in_array($data->access_level, [1, 2])) {
							$action .= "<button class=\"btn btn-sm btn-outline-success edit-user\" data-user-id=\"{$data->user_id}\">
								<i class=\"fa fa-edit\"></i>
							</button> ";
						}
					}

					if($this->accessObject->hasAccess('accesslevel', 'users')) {
						$action .= "<button class=\"btn btn-sm btn-outline-primary edit-access-level\" data-user-id=\"{$data->user_id}\">
								<i class=\"fa fa-sitemap\"></i>
							</button> ";
					}

					if($this->accessObject->hasAccess('delete', 'users')) {
						if(in_array($data->access_level, [1, 2]) && (in_array($this->session->accessLevel, [1, 2]))) {
							$action .= "<button class=\"btn btn-sm btn-outline-danger delete-user\" data-user-id=\"{$data->user_id}\">
								<i class=\"fa fa-trash\"></i>
							</button> ";
						} elseif(!in_array($data->access_level, [1, 2])) {
							$action .= "<button class=\"btn btn-sm btn-outline-danger delete-user\" data-user-id=\"{$data->user_id}\">
								<i class=\"fa fa-trash\"></i>
							</button> ";
						}
					}

					$action .= "</div>";

					$userBranch = explode(",", $data->branches);

					$branch_name = '';
					foreach($branches as $item) {
						if(in_array($item->id, $userBranch)) {
							$branch_name .= "<div>{$item->branch_name}</div>";
						}
					}

					$message[] = [
						'user_id' => $data->user_id,
						'row_id' => $i,
						'fullname' => $data->name,
						'branch_name' => $branch_name,
						'access_level' => $data->access_name,
						'access_level_id' => $data->access_level,
						'gender' => $data->gender,
						'branchId' => $data->branchId,
						'contact' => $data->phone,
						'email' => $data->email,
						'registered_date' => $date,
						'action' => $action,
						'deleted' => 0
					];

				}
				$status = true;
			}

		}

		//: get the details of a single user
		elseif(isset($_POST['getUserDetails'], $_POST['userId']) && $requestInfo === "getUserDetails") {

			$userId = xss_clean($_POST['userId']);

			// Check If User Exists
			$query = $this->db->prepare(
				"SELECT u.*, al.access_name FROM users u INNER JOIN access_levels al ON u.access_level = al.id WHERE u.user_id = ? && u.status = ?"
			);

			if ($query->execute([$userId, '1'])) {

				$data = $query->fetch(PDO::FETCH_OBJ);

				// get branches
				$data->branches = explode(",", $data->branches);
				foreach($data->branches as $row) {
					$branchesList[] = (int)$row;
				}

				// set the message to return
				$message = [
					"user_id"	=> $data->user_id,
					"fullname"	=> $data->name,
					"access_level_id" => $data->access_level,
					"acl_name"	=> $data->access_name,
					"gender"	=> $data->gender,
					"contact"	=> $data->phone,
					"email"		=> $data->email,
					"branches"	=> $branchesList ?? [],
					"country"	=> $data->country_id,
					"branchId" => $data->branchId,
					"branchesList" => $this->getBranches($loggedUserClientId),
					"daily_target" => $data->daily_target,
					"monthly_target" => $data->monthly_target,
					"weekly_target" => $data->weekly_target,
				];

				$status = true;
			} else {
				$message = "Sorry! User Cannot Be Found.";
			}
			
		} 

		//: add or update user information
		elseif(isset($_POST['fullName'], $_POST['access_level']) && $requestInfo === "addUserRecord") {

			// Check If Fields Are Not Empty
			if (!empty($_POST['fullName']) && !empty($_POST['access_level']) && !empty($_POST['gender'])  && !empty($_POST['phone']) && !empty($_POST['email'])) {

				$userData = (Object) array_map("xss_clean", $_POST);

				if(!empty($userData->email) && !filter_var($userData->email, FILTER_VALIDATE_EMAIL)) {
					$message = "Please enter a valid email address";
				} elseif(!empty($userData->phone) && !preg_match('/^[0-9+]+$/', $userData->phone)) {
					$message = "Please enter a valid contact number";
				} else {

					if ($userData->record_type == "new-record") {
						
						if(empty($userData->password) || empty($userData->password_2)) {
							$message = "Sorry the password fields are required";
						} elseif(strlen($userData->password) < 8) {
							$message = "Sorry! The password must be at least 8 characters long.";
						} elseif($userData->password !== $userData->password_2) {
							$message = "Sorry! The passwords does not match.";
						} else {
							// Check Email Exists
							$checkData = $this->getAllRows("users", "COUNT(*) AS proceed", "email='{$userData->email}' && status = '1'");

							if ($checkData != false && $checkData[0]->proceed == '0') {

								// Add Record To Database
								$getUserId   = random_string('alnum', mt_rand(20, 30));
								$getPassword = $userData->password;
								$hashPassword= password_hash($getPassword, PASSWORD_DEFAULT);

								$query = $this->addData(
									"users" ,
									"clientId='{$loggedUserClientId}', user_id='{$getUserId}', 
										name='{$userData->fullName}', gender='{$userData->gender}', 
										email='{$userData->email}', phone='{$userData->phone}', 
										access_level='{$userData->access_level}', branchId='{$userData->branchId}', 
										password='{$hashPassword}', daily_target='{$userData->daily_target}', weekly_target='{$userData->weekly_target}', 
										monthly_target='{$userData->monthly_target}', login='{$userData->email}'"
								);

								if ($query == true) {

									// Record user activity
									$this->userLogs('users', $getUserId, 'Added a new user.');
									
									// Assign Roles To User
									$this->accessObject->assignUserRole($getUserId, $userData->access_level);

									// Show Success Message
									$message = "User Have Been Successfully Registered.";
									$status = true;
								} else {
									$message = "Sorry! User Records Failed To Save.";
								}
							} else {
								$message = "Sorry! Email Already Belongs To Another User.";
							}
						}
						
					} else if ($userData->record_type == "update-record") {
						// CHeck If User ID Exists
						$checkData = $this->getAllRows("users", "COUNT(*) AS userTotal, access_level", "user_id='{$userData->userId}'");

						if ($checkData != false && $checkData[0]->userTotal == '1') {
							
							$continue = true;
							$newPassword = false;
							if(!empty($userData->password)) {
								if(empty($userData->password_2)) {
									$continue = false;
									$message = "Sorry the confirm password field is required";
								} elseif(strlen($userData->password) < 8) {
									$continue = false;
									$message = "Sorry! The password must be at least 8 characters long.";
								} elseif($userData->password !== $userData->password_2) {
									$continue = false;
									$message = "Sorry! The passwords does not match.";
								}
								$newPassword = password_hash($userData->password, PASSWORD_DEFAULT);
							}
							
							if(!$continue) {
								$message = $message;
							} else {
								// update user data
								$query = $this->updateData(
									"users",
									"name='{$userData->fullName}', 
									gender='{$userData->gender}', 
									email='{$userData->email}', 
									phone='{$userData->phone}', 
									access_level='{$userData->access_level}', 
									".(!empty($newPassword) ? "password='{$newPassword}'," : null)."
									branchId='{$userData->branchId[0]}', 
									daily_target='{$userData->daily_target}', 
									branches='".implode(",", $userData->branchId)."',
									weekly_target='{$userData->weekly_target}', 
									monthly_target='{$userData->monthly_target}'",
									"user_id='{$userData->userId}' && clientId='{$loggedUserClientId}'"
								);

								if ($query == true) {

									// set the branches in an array if the user_id is the logged in user id
									if($userData->userId === $loggedUserId) {
										$this->session->set_userdata("branchId", $userData->branchId[0]);
                        				$this->session->set_userdata("branchAccess", implode(",", $userData->branchId));
									}

									// Record user activity
									$this->userLogs('users', $userData->userId, "Update the user details of {$userData->fullName}.");

									// check if the user has the right permissions to perform this action
									if($this->accessObject->hasAccess('accesslevel', 'users')) {

										// Check If User ID Exists
										$userRole = $this->getAllRows("user_roles", "COUNT(*) AS userTotal, permissions", "user_id='{$userData->userId}'");

										// confirm if the user has no credentials
										if($userRole[0]->userTotal == 0) {
											// insert the permissions to this user
											$getPermissions = $this->accessObject->getPermissions($userData->access_level)[0]->default_permissions;
											// assign these permissions to the user
											$this->accessObject->assignUserRole($userData->userId, $userData->access_level);
										}

										// Check Access Level
										if ($userData->access_level != $checkData[0]->access_level) {

											$getPermissions = $this->accessObject->getPermissions($userData->access_level)[0]->default_permissions;

											$this->accessObject->assignUserRole($userData->userId, $userData->access_level, $getPermissions);
										}
									}

									$message = "User Details Have Been Successfully Updated.";
									$status = true;
								} else {
									$message = "Sorry! User Records Failed To Update.";
								}
							}
						} else {
							$message = "Sorry! User Does Not Exist.";
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

		//: load the user access level details
		elseif(isset($_POST['fetchAccessLevelPermissions']) || (isset($_POST['user_id'], $_POST['getUserAccessLevels']) && $requestInfo === "permissionManagement")) {

			if (isset($_POST["getUserAccessLevels"]) ||(isset($_POST['access_level']) && !empty($_POST['access_level']) && $_POST['access_level'] != "null")) {

				$access_level = (isset($_POST['access_level'])) ? xss_clean($_POST['access_level']) : null;
				$access_user  = (isset($_POST['user_id'])) ? xss_clean($_POST['user_id']) : null;

				// Check If User Is Selected
				if (!empty($access_user) && $access_user != "null") {

					// Get User Permissions
					$query = $this->getAllRows("user_roles", "permissions", "user_id='{$access_user}'");

					if ($query != false) {
						$message = json_decode($query[0]->permissions);
						$status = true;
					} else {
						$message = "Sorry! No Permission Found For This User";
					}
				} else {

					$query = $this->accessObject->getPermissions($access_level);
					if ($query != false) {
						$message = json_decode($query[0]->default_permissions);
						$status = true;
					} else {
						$message = "Sorry! No Permission Found.";
					}

				}
			} else {
				$message = "Sorry! You Need To Select An Access Level";
			}

		}

		//: save user access level settings
		elseif(isset($_POST['saveAccessLevelSettings'], $_POST['aclSettings'], $_POST['accessUser']) && $requestInfo === "saveAccessLevelSettings") {

			if (!empty($_POST['acl']) && $_POST['acl'] != "null" && !empty($_POST['aclSettings']) && $_POST['aclSettings'] != "null") {

				$access_level = xss_clean($_POST['acl']);
				$access_user  = xss_clean($_POST['accessUser']);
				$aclSettings  = $_POST['aclSettings'];

				// Prepare Settings
				$aclPermissions = array();
				$array_merged = array();

				foreach($aclSettings as $eachItem) {
					$expl = explode(",", $eachItem);

					$aclPermissions[$expl[0]][$expl[1]] =  xss_clean($expl[2]);

				}

				$permissions = json_encode(array("permissions" => $aclPermissions));

				if ($access_user != "" && $access_user != "null") {
					// Update Settings For User
					$checkData = $this->getAllRows("users", "COUNT(*) AS userTotal", "user_id='{$access_user}' && status = '1'");

					if ($checkData != false && $checkData[0]->userTotal == '1') {

						$query = $this->accessObject->assignUserRole($access_user, $access_level, $permissions);

						if ($query == true) {
							$message = "Access Level Updated Successfully!";
							$status = true;
						} else {
							$message = "Sorry! Access Level Update Failed.";
						}

					} else {
						// $message = "Sorry! User Does Not Exist.";
					}

				} else {
					// Update Settings For Access Level Group
					$checkData = $this->getAllRows("access_levels", "COUNT(*) AS aclTotal", "id='{$access_level}'");

					if ($checkData != false && $checkData[0]->aclTotal == '1') {

						$stmt = $this->db->prepare(
							"UPDATE access_levels SET default_permissions = '{$permissions}' WHERE id = '{$access_level}'"
						);

						if ($stmt->execute()) {
							$message = "Access Level Updated Successfully";
							$status  = true;
						} else {
							$message = "Sorry! Access Level Update Failed.";
						}

					} else {
						$message = "Sorry! Access Level Does Not Exist.";
					}
				}
			} else {
				$message = "Sorry! You Need To Select An Access Level";
			}

		}

		//: save the user profile information
		elseif(isset($_POST["userId"], $_POST["email"], $_POST["phone"], $_POST["gender"], $_POST["fullName"]) && $requestInfo === "quickUpdate") {
			//: process the user information parsed
			$postData = (Object) array_map('xss_clean', $_POST);

			//: validate the user information
			if(!empty($postData->phone) && !preg_match('/^[0-9+]+$/', $postData->phone)) {
				$message = "Please enter a valid contact number";
			} elseif($postData->userId != $loggedUserId) {
				$message = "You are not permitted to update this account.";
			} else {
				//: update the user information
				$stmt = $this->db->prepare("UPDATE users SET name=?, phone=?, gender=? WHERE user_id =?");
				$stmt->execute([
					$postData->fullName, $postData->phone, $postData->gender, $postData->userId
				]);

				//: update the password column of the user

				// print success message
				$status = true;
				$message = "Profile was successfully updated.";

			}

		}

		//: delete a user record
		elseif (isset($_POST['deleteUser'], $_POST['itemId']) && $requestInfo === 'deleteUser') {

			$status = 'error';

			$itemId = xss_clean($_POST['itemId']);

			// Check User ID Exists
			$checkData = $this->getAllRows("users", "COUNT(*) AS proceed", "user_id='{$itemId}' AND clientId = '{$loggedUserClientId}'");
			// confirm that the user has the needed permissions
			if($this->accessObject->hasAccess('delete', 'users')) {

				// proceed if the user was found
				if ($checkData != false && $checkData[0]->proceed == '1') {
					
					// delete the user from the system
					$response = $this->updateData("users", "status='0'", "user_id='{$itemId}' AND clientId = '{$loggedUserClientId}'");

					if ($response == true) {
						
						// check the user who has been deleted
						if($itemId == $loggedUserId) {
							$status = 'success';
							$message = "You have successfully deleted your account. Your session will end now. Contact Support if you need help restoring it.";
							
							// log user activity
							$this->userLogs('user', $itemId, 'You have successfully deleted your account. Your session will end now. Contact Support if you need help restoring it.');

							$loggedUserId = null;
							session_destroy();
						} else {
							$message = "User Have Been Successfully Deleted.";
							
							// log user activity
							$this->userLogs('user', $itemId, 'Deleted the User details.');
						}
					} else {
						$message = "Sorry! User Failed To Delete.";
					}
				} else {
					$message = "Sorry! User Does Not Exists.";
				}
			}
			
		}

		return [
			'status' => $status,
			'message' => $message
		];
	}

	/**
	 * @method logged_InControlled
	 * @desc This confirms if the user  is loggedin
	 * @param array $session
	 * @param string $session->puserLoggedIn
	 * @param string $session->userId
	 * @return bool
	 */
	public function logged_InControlled() {
		return ($this->session->puserLoggedIn && $this->session->userId) ? true : false;
	}

	public function logout_user() {
		
		$this->session->unset_userdata("puserLoggedIn");
		$this->session->unset_userdata("userId");
		$this->session->unset_userdata("userName");
		$this->session->sess_destroy();
		
	}
	
}
?>