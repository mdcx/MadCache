<?php

/**
 * @package MadCache
 */
/*
  Plugin Name: MadCache
  Plugin Name:
  Plugin URI: http://madrk.com/madcache
  Description: Helper for expiring Posts!
  Version: 0.1
  Author: Mad Mike
  Author URI: http://madrk.com/
  License: GPLv2 or later
 */

if ( !defined( 'MADCACHE_LOADED' ) )
	require('class.mc.php');

/**
 * Handle expiring posts on new comment.
 */
add_action( 'comment_post', 'wp_expire_on_comment' );

function wp_expire_on_comment( $comment_ID )
{
	$comment = get_comment( $comment_ID );
//	$post = get_post($comment->comment_post_ID);
	mc_expire_on_post( $comment->comment_post_ID, 'new_comment' );
}

/**
 * Handles expiration of the post pages upon update.
 */
add_action( 'wp_insert_post', 'mc_expire_on_post' );

function mc_expire_on_post( $post_id, $for = 'new_post' )
{
	/**
	 * Get the post data, record the server http_host, 
	 */
	$mc = new MadCache();

	$post = get_post( $post_id );

	if ( $post->post_parent != 0 )
		$post = get_post( $post->post_parent );

	if ( $post->post_status == 'publish' )
	{

		$url_format = get_option( 'permalink_structure' );
		$fpu = $_SERVER['HTTP_HOST'] . $url_format;

		$post_date = strtotime( $post->post_date );

		if ( strpos( $url_format, '%post_id%' ) !== false )
			$fpu = str_replace( '%post_id%', $post_id, $fpu );

		if ( strpos( $url_format, '%year%' ) !== false )
			$fpu = str_replace( '%year%', date( 'Y', $post_date ), $fpu );

		if ( strpos( $url_format, '%monthnum%' ) !== false )
			$fpu = str_replace( '%monthnum%', date( 'm', $post_date ), $fpu );

		if ( strpos( $url_format, '%day%' ) !== false )
			$fpu = str_replace( '%day%', date( 'd', $post_date ), $fpu );

		if ( strpos( $url_format, '%postname%' ) !== false )
			$fpu = str_replace( '%postname%', $post->post_name, $fpu );

		/*
		 * Definitely expire the post itself if it exists.
		 */
		$path = $mc->get_cache_path();
		$key = $mc->make_hash( $fpu );
		$mc->cache_expire( 'key', 'post::' . $fpu );

		/*
		 * Non-post pages need rebuilt now.
		 */
		$lu_path = $path . '/last_updated';
		$mc->write( $lu_path, time() );
		$mc->cache_log( "Worked on $post_id came up with $fpu with a hash of $key during $for", true);
	}
}

?>
