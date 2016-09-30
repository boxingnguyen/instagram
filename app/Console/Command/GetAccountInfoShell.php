<?php
class GetAccountInfoShell extends AppShell {
	public function main() {
		$time_start = microtime(true);
		$m = new MongoClient();
		$db = $m->instagram_account_info;
		$collection = $db->account_info;
		
		// readfile
		$file = fopen(APP."Vendor/username.txt", "r");
		
		// read file line by line and assign into array
		while(!feof($file)){
			$line = fgets($file);
			$all_account[] = trim(preg_replace('/\s\s+/', ' ', $line));;
		}
		fclose($file);
		$count = 1;
		$result = array();
		if (!empty($all_account)) {
			foreach ($all_account as $name) {
				$data = $this->__getAccountInfo($name);
				if (isset($data->user)) {
					$date_now = date('d M Y');
					$result[] = $data->user;
					echo $count . ". Account " . $name . " completed!" . PHP_EOL;
					$count ++;
				} else {
					echo $name . " Failed !!!!!!!!!!!!!!!!!!!!!!!!!!" . PHP_EOL;
				}
			}
			// drop old data
			$collection->drop();
			echo "Inserting into mongo..." . PHP_EOL;
			// insert new data
			$collection->batchInsert($result);
			// reconnect mongo and re-insert if insert unsuccessfully
			while (!$collection) {
				exec('sudo service mongod restart');
				$m = new MongoClient();
				$db = $m->instagram_account_info;
				$collection = $db->account_info;
				$collection->batchInsert($result);
			}
			// indexing
			echo "Indexing account_info ..." . PHP_EOL;
			$collection->createIndex(array('id' => 1));
			echo "Indexing account_info completed!" . PHP_EOL;
			echo "Total documents: " . $collection->count() . PHP_EOL;
			// save follows_by
			$this->__saveFollows();
			$time_end = microtime(true);
			echo "Time to get all account: " . ($time_end - $time_start) . " seconds" . PHP_EOL;
		}
	}
	
	private function __getAccountInfo($username) {
		$data = $this->cURLInstagram('https://www.instagram.com/' . $username . '/?__a=1');
		return $data;
	}
	
// 	private function __saveFollows() {
// 		$m = new MongoClient();
// 		$db = $m->instagram_account_info;
// 		$collection = $db->account_info;
// 		$follows = $db->follows;
// 		$currentDate = date('Y-m-d');

// 		$data = $collection->find(array(), array('id' => 1, 'followed_by.count' => 1));
		
// 		if(isset($data) && $data->count() > 0) {
// 			foreach($data as $val) {
// 				$dataFollow = $follows->find(array('id' => $val['id'], 'time' => $currentDate));
// 				if($dataFollow->count() > 0){
// 					$follows->update(array(), array('$set' => array('follows' => $val['followed_by']['count'])));
// 				} else {
// 					$val['follows'] = $val['followed_by']['count'];
// 					$val['time'] = $currentDate;
// 					unset($val['followed_by']);
// 					$follows->insert($val);
// 				}
// 			}
// 		}
// 	}
	
	public function readReactionSaveDays() {
		$m = new MongoClient();
		
		$db = $m->instagram_account_info;
		$collection = $db->reaction;
		$dbChart = $m->chart;
		$chart = $dbChart->selectCollection(date('Y-m'));
		
		$currentDate = date('Y-m-d');
		$data = $collection->find(array(), array('_id' => 1, 'followers' => 1,'likes'=>1,'comments'=>1));
	
		if(isset($data) && $data->count() > 0) {
			foreach($data as $val) {
				
				$getChart = $chart->find(array('id' => $val['_id'], 'time' => $currentDate ));
				if($getChart->count() > 0){
					$chart->update(
							array(), 
							array(
									'$set' => array(
											'follows' => $val['followers'],
											'likes'=>$val['likes'],
											'comments'=> $val['comments']
									)
							)
							);
				} else {
					$newChart = array(
							'id' => $val['_id'],
							'follows'=>$val['followers'],
							'likes'=>$val['likes'],
							'comments'=>$val['comments'],
							'time'=>$currentDate
					);
					$chart->insert($newChart);
				}
			}
		}
	}
	
}