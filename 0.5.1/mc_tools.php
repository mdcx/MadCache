<?php
	/**
	 * Consider moving this file to an admin folder if you are going to have it accessible via
	 * the web.
	 */
	 
	require_once("/path/to/MadCache-0.5.inc.php");
	if ( isSet($argv[1]) )
		$action = $argv[1];
	elseif ( isSet($_GET['action']) )
		$action = $_GET['action'];
	else
		$action = "none";
		
	
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
