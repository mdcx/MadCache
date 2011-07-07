<?php
	require_once("MadCache-current.php");
	$action = $argv[1];
	switch($action)
	{
		CASE 'gc':
			$mc = new MadCache();
			$num = isSet($argv[3]) ? $argv[3] : 5;
			$verbose = isSet($argv[2]) && $argv[2] != FALSE ? TRUE : FALSE;
			for($i=0; $i<$num; $i++) 
			{
				$mc->collect_garbage($verbose);
			}
		break;
		CASE 'cache_stats':
			$stats = file_get_contents("./cache_files/logs/garbage{$mf}.stat");
			$stats = unserialize($stats);
			print_r($stats);
		break;
	}
?>
