<?php
define( 'MADCACHE_ON', true );
if ( MADCACHE_ON && file_exists(dirname(__FILE__) . '/class.mc.php') )
{
	require(dirname(__FILE__) . '/class.mc.php');
	define( 'INDEX_EXPIRE', 4 * 3600 );
	define( 'POST_EXPIRE', 86400 );
	$mc = new MadCache();
	$REQ = $_SERVER['REQUEST_URI'];

	if ( $REQ == '/favicon.ico' )
		die;

	if ( ($m = preg_match( "/archive|date|topic|page|cat|category|tag|feed/im", $REQ, $match )) || $REQ == "" || $REQ == "/" )
	{
		$EXPIRE = INDEX_EXPIRE;
		if ( $m )
			$type = $match[0];
		else
			$type = 'index';
		switch ($type)
		{
			case 'archive':
			case 'date':
				$type = 'archive';
				break;

			case 'page':
			case 'index':
				$type = 'index';
				break;

			case 'cat':
			case 'category':
			case 'topic':
				$type = 'category';
				break;

			case 'tag':
				$type = 'tag';
				break;

			case 'feed':
				$type = 'feed';
				break;
		}
	}
	else
	{
		$type = 'post';
		$EXPIRE = POST_EXPIRE;
	}

	if ( isSet( $_GET['force_exp'] ) )
	{
		$REQ = str_replace( array("&force_exp=1", "?force_exp=1"), "", $REQ );
		$EXPIRE = -1;
	}

	if ( $type !== 'post' )
	{
		$lu = $mc->get_cache_path() . '/last_updated';
		$cache_expire_or = filemtime( $lu );
		if ( $cache_expire_or !== false )
		{
			$mc->set_expire_override( $cache_expire_or );
		}
	}

	$KEY = $type . '::' . strtolower( $_SERVER['HTTP_HOST'] ) . $REQ;
	if ( $mc->start_cache( $EXPIRE, $KEY ) )
	{
		require(MC_ROOT_PATH . '/class.fs.php');
		/**
		 * Loads the WordPress environment and template.
		 */
		if ( !isset( $wp_did_header ) )
		{
			$wp_did_header = true;
			require_once( './wp-load.php' );
			wp();
			require_once( ABSPATH . WPINC . '/template-loader.php' );
			$mc->end_cache();
		}
		else
		{
			$mc->end_cache();
			$mc->cache_expire('key', $KEY);
		}
		
	}
	else
	{
		$mc->read_cache();
	}
}
else
{
	require('./wp-blog-header.php');
}
	