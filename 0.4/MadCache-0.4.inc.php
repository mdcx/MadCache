<?php
 /*
  * Simple caching object can be used to cache entire pages or
  * part of a page. Can also be used to cache variables such as query results.
  * This is also heavily inspired by Nathan Faber's phpCache from many years back.
  */


 class MadCache
 {
    const CACHE_VERSION = "0.4";
 
    const CACHE_TYPE = "OB"; // Use output buffering to cache the parts you want. Or MANUAL to start the cache and store variables/data
    const CACHE_TAG = TRUE; // This sets whether or not you want a <!-- coment --> with some data in it
    const CACHE_PATH = "/www/htdocs/mad_cache/cache_files"; // path to cache folder
    const CACHE_MAX_HASH = 23; // The max # of folders to seperate cache files into. Don't change unless you completely wipe the entire cache structure.

    /*
     * CACHE_CHECK_DIR
     *  ONLOAD - This will create each part of the directory structure as it is needed. Slower than the TRUE/FALSE version.
     *  STAT - This will create stat files that way the dir structure routine is created once.
	 *  NOCHECK - This is if you are 100% sure the cache directory or cache/host_directory has a .madCache.stat file in it. Considerable performance increase.
     */
    const CACHE_CHECK_DIR = "ONLOAD";
    const CACHE_STORAGE_PERM = 0755; // Default permissions of a cache storage directory. Be careful with 0700 etc if you can't override apache.
    const CACHE_PER_HOST = TRUE; // If this is true, you can put different caches in diff folders by setting the key like FOLDER_NAME::whatever ..
    const CACHE_KEY_METHOD = "md5"; // crc32, sha1, md5
    const CACHE_LOG = FALSE; // For debug purposes.
    const CACHE_LOG_TYPE = "host"; // host, combined
    const CACHE_LOG_LIMIT = 100000; // size in bytes
    const CACHE_MAX_AGE = 604800; // max cache age - used for garbage collection on simple cache files.
    const CACHE_GC = .50;

    private $_now = '';
    private $_pretty_time;
    private $_key;
    private $_expire;
    private $_cache_content;
    private $_original_key;
    private $_host_folder;
    private $_cache_file;
    private $_cache_mtime;
    private $_cache_age;
    private $_cache_path;    
    private $_cache_running = array();
    private $_cache_variables = array();
    private $_cache_method = "include";
    private $_calling_script;
    private $_initialized = FALSE;
    private $_default_key = FALSE;
    public  $check_only = FALSE;
    protected $cache_data;


    function __construct()
    {
        $this->initialize();
    }

    function initialize()
    {
        $this->_now = time();
        $this->_pretty_time = date("Y-m-d h:i:s",$this->_now);
        $this->_calling_script = $this->make_hash($_SERVER['DOCUMENT_ROOT'],"crc32");
        $this->_cache_path = self::CACHE_PATH ."/$this->_calling_script";
        if(!is_dir($this->_cache_path))
        {
            @mkdir($this->_cache_path,self::CACHE_STORAGE_PERM);
            $this->_write_flock(self::CACHE_PATH."/mapping","$this->_calling_script => ".$_SERVER['DOCUMENT_ROOT']."\n","a+");
        }
        $this->_initialized = TRUE;
    }

    function __destruct()
    {
        $this->cache_reset();
    }


    public function start_cache($EXPIRE,$KEY=NULL,$CHECK_ONLY=FALSE)
    {
        if($this->_initialized == FALSE) $this->initialize();
        /* Use default Cache Key ..
         * This will make the key unique to the page based on request_uri, post and get
         */ 
        if($KEY == NULL) 
        {
            $this->_default_key = TRUE;
            $KEY = $this->default_key();
        }
        
        $this->_original_key = $KEY;
        if(self::CACHE_PER_HOST == TRUE)
        {
            if($this->_default_key == TRUE || !strstr($this->_original_key,"::"))
            {
                $this->_host_folder = $_SERVER['HTTP_HOST']; 
            }
            else
            {
                $tmp_key = explode("::",$this->_original_key);
                $this->_host_folder = $tmp_key[0];
            }
            $this->_cache_path .= "/$this->_host_folder";
            if(!is_dir($this->_cache_path)) @mkdir($this->_cache_path,self::CACHE_STORAGE_PERM);
        }
        $this->_expire = $EXPIRE;  
        $this->_key = $this->make_hash($this->_original_key);
        $this->_cache_path = $this->make_path();
        $this->_cache_file = $this->_cache_path."/".$this->_key;
        echo("<!-- after make_path $this->_cache_path -->\n");
        /*
         * This will be used eventually for caching specific items
         * such as database results, variables, sessions, etc.
         */
        if(self::CACHE_TYPE == "MANUAL")
        {
            return TRUE;
        }

        if($CHECK_ONLY === TRUE)
        {
            return $this->cache_check();
        }

        if($this->cache_check() == TRUE)
        {
            $this->_cache_running[$this->_key] = TRUE;
            ob_start();
            return TRUE;
        }
        else
        {           
            return FALSE;
        }
    }

    public function end_cache()
    {
         echo("<!-- in end cache $this->_cache_path - $this->_cache_file -->\n");
        if(isSet($this->_cache_running[$this->_key]) && $this->_cache_running[$this->_key] == TRUE)
        {
            $level = ob_get_level();
            $buffer_content = array();
            for($i=0; $i<$level; $i++)
            {
                $buffer_length = ob_get_length();
                if($buffer_length !== FALSE)
                {
                    $buffer_content[] = ob_get_clean();
                }
            }
            $this->_content = implode("",array_reverse($buffer_content));
            if(self::CACHE_TAG) $this->cache_tag("CACHE GENERATED AND WRITTEN");
            $this->cache_this($this->_content);
            $this->_cache_running[$this->_key] = FALSE;

        }
        echo($this->_content);
    }

    public function echo_cache()
    {
        if(strlen($this->_cache_content) > 0) echo($this->_cache_content);
    }

    public function cache_reset()
    {
        $this->_key = $this->_expire = $this->_cache_content = $this->_original_key = $this->_cache_file = $this->_cache_file = "";
        $this->_cache_mtime = $this->_cache_age = $this->_cache_path = $this->_calling_script = "";
        $this->_initialized = FALSE;
        $this->_cache_running = array();
        $this->_cache_variables = array();
        $this->check_only = FALSE;
    }

    public function cache_expire($how="key",$what=NULL)
    {
        /*
         * Options for expiring are going to be all or by host
         */
        if($how == "key")
        {
            $cache_file = $this->_cache_path;
            if($what == NULL) $what = $this->default_key();
            if(self::CACHE_PER_HOST == TRUE)
            {

                if($what == NULL || !strstr($what,"::"))
                {
                    $host_folder = $_SERVER['HTTP_HOST'];
                }
                else
                {
                    $tmp_key = explode("::",$what);
                    $host_folder = $tmp_key[0];
                }
                $cache_file .= "/".$host_folder;
            }
            $what = $this->make_hash($what);
            $path = $this->make_path($what);
            $cache_file .= $path."/".$what;            
            if(file_exists($cache_file)) unlink($cache_file);
            $this->cache_log("Manually expired $cache_file",TRUEs);
        }
        else if($how == "host")
        {
            $cache_path = $this->_cache_path;
            if($what == NULL)
            {
                $what = $_SERVER['HTTP_HOST'];
            }
            $cache_path .= "/".$what;
            if(is_dir($cache_path))
            {
                for($a=0; $a<self::CACHE_MAX_HASH; $a++)
                {
                    $thedira = $cache_path . "/$a/";
                    if(!is_dir($thedira)) continue;
                    for($b=0; $b<self::CACHE_MAX_HASH; $b++)
                    {
                        $thedirb = $cache_path . "/$a/$b/";
                        if(!is_dir($thedirb)) continue;
                        for($c=0; $c<self::CACHE_MAX_HASH; $c++)
                        {
                            $thedirc = $cache_path . "/$a/$b/$c";
                            if(!is_dir($thedirc)) continue;
                            $this->_clean_dir($thedirc);
                            rmdir($thedirc);
                        }
                        rmdir($thedirb);
                    }
                    rmdir($thedira);
                }
            }
            @unlink($cache_path."/.madCache.stat");
        }
    }

    public function set_method($method)
    {
        switch($method)
        {
            CASE 'include': $this->_cache_method = "include"; break;
            CASE 'diskread': $this->_cache_method = "diskread"; break;
        }
    }

    public function cache_variable($var)
    {
        if(!is_array($var))
        {
            $this->cache_log("Attempted to cache a non array variable Must be an array(\"var_name\"=>\"var_value\")");
        }
        else
        {
            foreach($var as $k=>$v)
            {
                $this->_cache_variables[$k] = $v;                
            }
            $this->cache_log("Cached a set of variables");
        }
    }

    public function cache_method($method)
    {
        $options = array("diskread","include");
        if(in_array($options,$method))
        {
            $this->_cache_method = $method;
        }
    }

    private function cache_tag($action)
    {
        $pretty_cache_mtime = $this->_cache_mtime != 0 ? date("Y-m-d h:i:s",$this->_cache_mtime) : 0;
        $vers = self::CACHE_VERSION;
        echo("
            <!--
                VERSION:     MadCache-{$vers}
                TIME NOW:    $this->_pretty_time
                ACTION:      $action
                CACHE AGE:   $this->_cache_age seconds
                CACHE MTIME: $pretty_cache_mtime
                CACHE KEY:   $this->_key
                ORIG. KEY:   $this->_original_key
                EXPIRE:      $this->_expire
                CACHE PATH:  $this->_cache_path
                CACHE FILE:  $this->_cache_file
                CACHE HOST:  $this->_host_folder
		IP #:	$_SERVER[REMOTE_ADDR]
            -->
        ");
    }

    private function cache_check()
    {
        if(file_exists($this->_cache_file))
        {
            $this->_cache_mtime = filemtime($this->_cache_file);
            $this->_cache_age = $this->_now - $this->_cache_mtime;
            if(($this->_cache_age) > $this->_expire)
            {
                $this->cache_log("Cache file has expired ($this->_cache_age secs / exp: $this->_expire)");
                return TRUE;
            }
            else
            {
                $this->cache_log("Cache file is current ($this->_cache_age secs / exp: $this->_expire)");
                return FALSE;
            }
        }
        else
        {
            $this->_cache_mtime = 0;
            $this->_cache_age = 0;
            $this->cache_log("Cache file doesn't exist.");
            return TRUE;
        }
    }
    private function cache_this($content=null)
    {
        if($this->_cache_method == "diskread")
        {
            $this->cache_data = array();            
            $this->cache_data['key'] = $this->_key;
            $this->cache_data['expire'] = $this->_expire;
            $this->cache_data['mtime'] = $this->_now;       
            $this->cache_data['variables'] = $this->_cache_variables;
            $this->cache_data['content'] = $content;
            $this->cache_data = serialize($this->cache_data);
            
        }
        else
        {
            $this->cache_data = $content;
        }

        if($this->_write_flock($this->_cache_file,$this->cache_data))
        {
            $this->cache_log("Cache file was written.");
            return TRUE;
        }
        else
        {
            $this->cache_log("Cache file failed to write.");
            return FALSE;
        }
    }

     private function make_hash($key,$hash_type=NULL)
     {
        if($hash_type == NULL) $hash_type = self::CACHE_KEY_METHOD;
        switch($hash_type)
        {
                CASE 'crc32':
                        $key = crc32($key);
                        $key = sprintf("%u",$key);
                break;
                CASE 'md5': $key = md5($key); break;
                CASE 'sha1': $key = sha1($key); break;
        }
        return $key;
     }

     public function read_cache($echo_content = TRUE)
     {          
          if (self::CACHE_GC>0) $this->_cache_gc();
          if($this->_cache_method == "diskread")
          {
              $this->cache_log("Cache read from disk.");
              $this->cache_data = $this->_read_flock($this->_cache_file);
              $this->_cache_content = $this->cache_data['content'];
              if($echo_content == TRUE)
              {
                  $this->echo_cache();
                  unset($this->cache_data['content']);
              }
              if(self::CACHE_TAG) $this->cache_tag("CACHE READ: $this->_cache_method");
              return $this->cache_data;
          }
          else
          {
              include($this->_cache_file);
              if(self::CACHE_TAG) $this->cache_tag("CACHE READ: $this->_cache_method");
              $this->cache_log("Cache file included.");
              return TRUE;
          }
     }


    private function cache_log($what,$override = FALSE)
    {
        $host_ident = "";
        $log_folder = $this->_cache_path."/logs";
        if(self::CACHE_LOG == TRUE || $override == TRUE)
        {
            if(self::CACHE_LOG_TYPE != "combined" && self::CACHE_PER_HOST == TRUE)
            {
                $log_file = "$log_folder/".$this->_host_folder.".log";
            }
            else
            {
                if(self::CACHE_PER_HOST == TRUE) $host_ident = $this->_host_folder ."|";
                $log_file = "$log_folder/cache.log";
            }

            $log_line = "{$host_ident}$this->_pretty_time|$this->_original_key|$what\n";
            if(!is_dir($log_folder)) mkdir($log_folder,0777,true);
            $this->_write_flock($log_file,$log_line,"a+");
        }
    }

    private function default_key()
    {
        $KEY['SERVER'] = $_SERVER['HTTP_POST'];
        $KEY['REQUEST_URI'] = $_SERVER['REQUEST_URI'];
        $KEY['GET'] = $_GET;
        $KEY['POST'] = $_POST;
        $KEY = serialize($KEY);
        return $KEY;
    }

    private function make_dir_structure()
    {           
        /* If caching per host, then look for a cache file in that dir, if not cache root */
        if(self::CACHE_PER_HOST == TRUE)
        {
            $stat_file = $this->_cache_path . "/" . $this->_host_folder . "/.madCache.stat";
        }
        else
        {
            $stat_file = $this->_cache_path . "/.madCache.stat";
        }
        if(file_exists($stat_file)) return TRUE;
        $failed = 0;        
        for($a=0; $a<self::CACHE_MAX_HASH; $a++)
        {
            $thedir = $this->_cache_path . "/$a/";
            $failed|=!@mkdir($thedir, self::CACHE_STORAGE_PERM);
            for($b=0; $b<self::CACHE_MAX_HASH; $b++)
            {
                $thedir = $this->_cache_path . "/$a/$b/";
                $failed|=!@mkdir($thedir, self::CACHE_STORAGE_PERM);
                for($c=0; $c<self::CACHE_MAX_HASH; $c++)
                {
                    $thedir = $this->_cache_path . "/$a/$b/$c";
                    $failed|=!@mkdir($thedir, self::CACHE_STORAGE_PERM);
                }
            }
        }
        $now = date("Y-m-d h:i:s",time());
		if($failed == 0) $this->_write_flock($stat_file,$now);
        $this->cache_log("Full cache directory structure created for $this->_host_folder");
        return TRUE;
    }
    private function make_path($tmp_key=NULL)
    {        
        if($tmp_key === NULL)
        {
            $tmp_key = $this->_key;
            $folder = $this->_cache_path;
            $check = TRUE;
        }
        else
        {
            $check = FALSE;
            $folder = "";
        }

        // get the folder structure
        for($i=0; $i<3; $i++)
        {
            $thenum = abs(crc32(substr($tmp_key,$i,4)))%self::CACHE_MAX_HASH;
            $folder .= "/".$thenum;
        }

        if($check == TRUE)
        {
			// I think I am going to build a gradual directory maker in.	
            if(self::CACHE_CHECK_DIR == "STAT")
            {
                $this->make_dir_structure();
            }
            else if(self::CACHE_CHECK_DIR == "ONLOAD")
            {
                if(!is_dir($folder))
                {
                    mkdir($folder,self::CACHE_STORAGE_PERM,TRUE);
                    $this->cache_log("Directory strctured created (onload) $folder");
                }
            }
        }     
        return $folder;
    }

    private function _clean_dir($path)
    {
        if(!is_dir($path)) return FALSE;
        $dh = @opendir($path);
        if($dh)
        {
            while (($file = readdir($dh)) !== false)
            {
                if($file == "." || $file == "..") continue;
                $actual_file = $path ."/".$file;
                @unlink($actual_file);
            }
            closedir($dh);
            $this->cache_log("Directory cleaned: $path");
            return TRUE;
        }
        else
        {
            return FALSE;
        }
    }
	public function collect_garbage()
	{
		$this->cache_log("Manual garbage collection running.",TRUE);
		$this->_cache_gc(1);
	}
    private function _cache_gc($start=0)
     {
        $precision = 100000;
        $r=(mt_rand()%$precision)/$precision;
        if($start == 1 || $r<=(self::CACHE_GC/100))
        {
            $dh = @opendir($this->_cache_path);
            if($dh)
            {
                while (($file = readdir($dh)) !== false)
                {
                    if($file == "." || $file == "..") continue;
                    $file = $this->_cache_path."/".$file;
                    if($this->_cache_method == "include")
                    {
                        $mtime = filemtime($file);
                        if(($this->_now - $mtime) > self::CACHE_MAX_AGE) unlink($file);
                    }
                    else
                    {
                        $tmp_cache = $this->_read_flock($file);
                        if(($this->_now - $tmp_cache['mtime']) > $tmp_cache['expire']) unlink($file);
                    }
                }
                closedir($dh);
                $this->cache_log("GC Sucessfully run on $this->_cache_path",TRUE);
            }
            else
            {
                $this->cache_log("GC Failed to run.",TRUE);
            }
        }
     }

     private function _read_flock($cache_file = NULL)
     {
         if($cache_file === NULL) $cache_file = $this->_cache_file;
         $buff = "";
         $fp = @fopen($cache_file,"r");
         if($fp)
         {
             flock($fp,LOCK_SH);
             while(($tmp = fread($fp,4096)))
             {
                 $buff .= $tmp;
             }
             flock($fp,LOCK_UN);
             fclose($fp);
             $buff = unserialize($buff);             
             return $buff;
         }
         else
         {
             $this->cache_log("Cache file could not be opened: $cache_file");
             return FALSE;
         }
     }

     private function _write_flock($path,$what,$mode="w")
     {
         $blocksize = 4096;
         $fp = fopen($path,$mode);
         if($fp)
         {
             flock($fp,LOCK_EX);
             $fwrite = 0;
             for($written = 0; $written < strlen($what); $written += $fwrite)
             {
                 if($written != 0 && $fwrite != $blocksize)
                 {
                     $diff = $blocksize - $fwrite;
                     $written -= $diff;
                 }
                 $line = substr($what,$written,$blocksize);
                 $fwrite = fwrite($fp,$line);
             }
             flock($fp,LOCK_UN);
             fclose($fp);             
             return TRUE;
         }
         else
         {             
             return FALSE;
         }
     }

     private function _truncate_flock($path)
     {
         $fp = @fopen($path,"w");
         if($fp)
         {
             flock($fp,LOCK_EX);
             ftruncate($fp,0);
             flock($fp,LOCK_UN);
             fclose($fp);
             return TRUE;
         }
         else
         {
             return FALSE;
         }
     }
 }

 ?>
