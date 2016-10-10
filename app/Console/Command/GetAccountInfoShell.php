<?php
class GetAccountInfoShell extends AppShell {
	public $m;
	public $db;
	const ACCOUNT_GET = "account_info";
	const ACCOUNT_ORIGIN = "account_username";
	
	public function initialize() {
		$this->m = new MongoClient;
		$this->db = $this->m->instagram_account_info;
	} 
	
	public function main() {
		$time_start = microtime(true);
		// get all instagram's username
		$acc_origin = $this->db->{self::ACCOUNT_ORIGIN}->find(array(), array('username' => true));
		$all_account = array();
		foreach ($acc_origin as $acc) {
			$all_account[] = $acc['username'];
		}
		
		// collect information of account before update
		$acc_change = array();
		$acc_before = $this->db->{self::ACCOUNT_GET}->find(array(), array('username' => true, 'is_private' => true));
		foreach ($acc_before as $acc) {
			$acc_change[$acc['username']]['before'] = $acc['is_private']; 
		}
		
		$date  = date('dmY');
		// write data into json file
		$acc_missing = $this->__writeToJson($all_account, $date);
		// check account if all account is got
		$checkAcc = $this->__checkAccount($date);
		$checkAccCount = 0;
		// re-get missing account (maximum 5 times)
		while (!$checkAcc && $checkAccCount < 5) {
			$checkAcc = $this->__reGetAccount($acc_missing, $date);
		}
		// save account info into db
		$this->__saveIntoDb($date);
		
		// check if any account has changed it's status
		$this->__checkChangeStatus($acc_change);
		
		$time_end = microtime(true);
		echo "Time to get all account: " . ($time_end - $time_start) . " seconds" . PHP_EOL;
	}

/**
 * Get data of instagram's account
 * @param string $username
 * @return object account's data
 */
	private function __getAccountInfo($username) {
		$data = $this->cURLInstagram('https://www.instagram.com/' . $username . '/?__a=1');
		return $data;
	}
	
	private function __writeToJson($all_account, $date) {
		$acc_missing = array();
		$count = 1;
		$myfile = fopen(APP."Vendor/Data/".$date.".acc.json", "w+") or die("Unable to open file!");
		foreach ($all_account as $name) {
			$data = $this->__getAccountInfo($name);
			if (isset($data->user)) {
				fwrite($myfile, json_encode($data->user)."\n");
				echo $count . ". Account " . $name . " completed!" . PHP_EOL;
				$count ++;
			} else {
				// store missing account into an array
				$acc_missing[] = $name;
				echo $name . " Failed !!!!!!!!!!!!!!!!!!!!!!!!!!" . PHP_EOL;
			}
		}
		fclose($myfile);
		return $acc_missing;
	}
	
	private function __checkAccount($date) {
		$filename = APP . "Vendor/Data/" . $date . ".acc.json";
		$fp = file($filename);
		$lines = count($fp);
			
		$m = new MongoClient();
		$db = $m->instagram_account_info;
		$collection = $db->account_username;
		$total_acc = $collection->count();

		$miss_count = $total_acc - $lines;
		if ($miss_count == 0){
			return true;
		} else {
			return false;
		}
	}
	
	private function __reGetAccount($acc_missing, $date) {
		$myfile = fopen(APP."Vendor/Data/".$date.".acc.json", "a") or die("Unable to open file!");
		foreach ($acc_missing as $name) {
			$data = $this->__getAccountInfo($name);
			if (isset($data->user)) {
				fwrite($myfile, json_encode($data->user)."\n");
				echo $count . ". Re-getttttt account " . $name . " completed!" . PHP_EOL;
				$count ++;
			} else {
				echo $name . " Re-gettttt failed !!!!!!!!!!!!!!!!!!!!!!!!!!" . PHP_EOL;
			}
		}
		fclose($myfile);
		return $this->__checkAccount($date);
	}
	
	private function __saveIntoDb($date) {
		// name of file which store account info's data
		$filename = APP."Vendor/Data/".$date.".acc.json";
		$file = fopen($filename, "r");
		$data = array();
		if ($file) {
			while (($line = fgets($file)) !== false) {
				// store media into an array
				$data[] = json_decode($line);
			}
			fclose($file);
		}
		// drop exist collection
		$this->db->{self::ACCOUNT_GET}->drop();
		echo "Inserting into mongo..." . PHP_EOL;
		// insert new data
		$this->db->{self::ACCOUNT_GET}->batchInsert($data, array('timeout' => -1));
	
		// indexing
		echo "Indexing account_info ..." . PHP_EOL;
		$this->db->{self::ACCOUNT_GET}->createIndex(array('id' => 1));
		echo "Indexing account_info completed!" . PHP_EOL;
		echo "Total documents: " . $this->db->{self::ACCOUNT_GET}->count() . PHP_EOL;
	}
	
	private function __checkChangeStatus($acc_change) {
		$flag = true;
		// collect information of account after update
		$acc_after = $this->db->{self::ACCOUNT_GET}->find(array(), array('username' => true, 'is_private' => true));
		foreach ($acc_after as $acc) {
			$acc_change[$acc['username']]['after'] = $acc['is_private'];
		}
		// if any account has change account's status (private or not), we send a messgae to that account
		foreach ($acc_change as $username => $acc) {
			// before is public, after is private
			if (isset($acc['before']) && $acc['before'] != 1 && $acc['after'] == 1) {
				$flag = false;
				echo PHP_EOL . $username . " has changed status from public to private, sending email ..." . PHP_EOL;
				// send message to that account
				$this->__sendMsg($username);
			}
			// before is not exist, after is private
			else if (!isset($acc['before']) && $acc['after'] == 1) {
				$flag = false;
				echo PHP_EOL . $username . " regists as a private account, sending email ..." . PHP_EOL;
				// send message to that account
				$this->__sendMsg($username);
			}
		}
		if ($flag) {
			echo PHP_EOL . "No one has changed account's status!!!" . PHP_EOL;
		}
	}
	
	private function __sendMsg($username) {
		$message = "Hello, I'm TMH-test. I just want to make see your lovely pictures to make a survey.\n Please follow this link if you are intersted in \n http://192.168.0.150/login";				
		try {
			$this->_instagram->direct_message($username, $message);
		} catch (Exception $e) {
			echo $e->getMessage(). PHP_EOL;
		}
	}
}