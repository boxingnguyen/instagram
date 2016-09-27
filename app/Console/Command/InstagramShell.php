<?php
class InstagramShell extends AppShell {
	public function main() {
		$start_time = microtime(true);
		passthru(ROOT."/app/Console/cake GetAccountInfo");
		passthru(ROOT."/app/Console/cake GetMedia");
		passthru(ROOT."/app/Console/cake CalculateReaction");
		exec('sudo service mongod restart');
		$end_time = microtime(true);
		echo "Time to complete this program: " . (($end_time - $start_time) / 60) . " minutes" . PHP_EOL;
	}
}