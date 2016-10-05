 <?php
class GetMediaShell extends AppShell {
	
	public function main() {
		$start_time = microtime(true);
		$m = new MongoClient();
		$db = $m->instagram;
		$collection = $db->media;
		
		$all_account = $this->__sortAccountByMedia();
		$date = date("dmY");
		
		if (!empty($all_account)) {
			// drop old data
			$collection->drop();
			// we get data of 34 accounts at a time
			$account_chunks = array_chunk($all_account, 34);
			foreach ($account_chunks as $account) {
				foreach ($account as $name) {
					// create 2 processes here
					$pid = pcntl_fork();
					if ($pid == -1) {
						die('could not fork');
					} else if ($pid) {
						// we are the parent
						// collect process id to know when children complete
						$pids[] = $pid;
					} else {
						// we are the child
						$max_id = null;
						$myfile = fopen(APP."Vendor/Data/".$date.".".$name.".media.json", "w+") or die("Unable to open file!");
						do {
							$data = $this->__getMedia($name, $max_id);
							// insert to mongo
							if (isset($data->items) && !empty($data->items)) {
								foreach ($data->items as $val) {
									fwrite($myfile, json_encode($val)."\n");
								}
								$max_id = end($data->items)->id;
							} else {
								$this->out("Error: data is null");
								break;
							}
						}
						while (isset ($data->more_available) && ($data->more_available == true || $data->more_available == 1));
						fclose($myfile);
						
						// check if account's media is missing or not
						$checkMedia = $this->__checkMedia($name);
						$check_count = 0;
						// re-get media if media is missing (maximum 5 times)
						while (!$checkMedia && $check_count < 5) {
							$checkMedia = $this->__reGetMedia($name);
							$check_count ++;
						}
						// write data from json file to database
						$this->__saveIntoDb($name);
						// Jump out of loop in this child. Parent will continue.
						echo "Get media of " . $name . " completed!" . PHP_EOL;
						exit;
					}
				}
				foreach ($pids as $pid) {
					pcntl_waitpid($pid, $status);
					unset($pids[$pid]);
				}
			}
			// indexing
			echo "Indexing media ..." . PHP_EOL;
			$collection->createIndex(array('user.id' => 1, 'created_time' => 1), array('timeout' => -1, 'background' => true));
			echo "Indexing media completed!" . PHP_EOL;
			echo "Total documents: " . $collection->count() . PHP_EOL;
			$end_time = microtime(true);
			echo "Time to get all media: " . ($end_time - $start_time) . " seconds" . PHP_EOL;
		}
	}
	
	private function __getMedia($username, $max_id = null) {
		if ($max_id != null) {
			$data = $this->cURLInstagram('https://www.instagram.com/' . $username . '/media/?max_id=' . $max_id);
		} else {
			$data = $this->cURLInstagram('https://www.instagram.com/' . $username . '/media/');
		}
		return $data;
	}
	
	private function __sortAccountByMedia() {
		$m = new MongoClient();
		$db = $m->instagram_account_info;
		$collection = $db->account_info;
		
		$data = $collection->find()->sort(array('media.count' => -1))->fields(array('username' => true, 'media.count' => true));
		$result = array();
		foreach ($data as $value) {
			$result[] = $value['username'];
		}
		return $result;
	}
	
	private function __checkMedia($name){
		if(isset($name) && !empty($name)){
			$date = date("dmY");
			$filename= APP."Vendor/Data/".$date.".".$name.".media.json";
			$fp = file($filename);
			$lines = count($fp);
			
			$m = new MongoClient();
			$db = $m->instagram_account_info;
			$collection = $db->account_info;
			$query = array('username' => $name);
			$result = $collection->find($query,array('media.count','media.nodes'));
			$total_media = 0;
			foreach ($result as $v){
				$total_media = $v['media']['count'];
				$timeMediaFirst = $v['media']['nodes'][0]['date'];
			}

			$miss_count = $lines - $total_media;
			if($miss_count >= 0 && $miss_count <= 10 ){
				return true;
			}elseif ( $miss_count >= -10 && $miss_count < 0){
				//remove data is over
				for($i=0;$i<10;$i++){
					$current_line = json_decode($fp[$i]);
					if($current_line['created_time']>$timeMediaFirst){
						unset($fp[$i]);
					}
				}
				file_put_contents($filename, implode("", $fp));
			}else {
				return false;
			}
		}else{
			return false;
		}
	}
}