<?php
/**
 *
 * @package ravpage
 * @version 2.9
 */
/*
Plugin Name: ravpage
Plugin URI: http://responder.co.il
Description: plugin to easy page publishing for ravpage clients
Version: 2.9
Author: Mati Skiba @ Rav Messer
*/

if( !class_exists( 'WP_Http' ) )
  require_once( ABSPATH . WPINC. '/class-http.php' );
  
require_once("URLNormalizer.php");

function url_path_encode($url) {
    if (strpos($url,'%') !== false) 
      $url = rawurldecode($url);
    //echo "before: $url\n";
    $path = parse_url($url, PHP_URL_PATH);
    $encoded_path = array_map('rawurlencode', explode('/', $path));
    //echo "after: " . str_replace($path, implode('/', $encoded_path), $url) . "\n";
    //die();
    return str_replace($path, implode('/', $encoded_path), $url);
}

function normalizeURL($url)
{
  $un = new URLNormalizer();
  $un->setUrl( $url );
  return preg_replace(array("~^[^:]+://~","~(\?|#).*~","~\/$~"),array("","",""),url_path_encode($un->normalize()));
}
    
function full_url()
{
    $s = empty($_SERVER["HTTPS"]) ? '' : ($_SERVER["HTTPS"] == "on") ? "s" : "";
    $sp = strtolower($_SERVER["SERVER_PROTOCOL"]);
    $protocol = substr($sp, 0, strpos($sp, "/")) . $s;
    $port = ($_SERVER["SERVER_PORT"] == "80") ? "" : (":".$_SERVER["SERVER_PORT"]);
    $host = (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST']))? $_SERVER['HTTP_HOST']:$_SERVER['SERVER_NAME'];
    return $protocol . "://" . $host . $port . str_replace("\'","'",$_SERVER['REQUEST_URI']);
}

global $jal_db_version;
$jal_db_version = "1.0";

function jal_install() {
   global $wpdb;
   global $jal_db_version;

   $table_name = $wpdb->prefix . "ravpage_urls";
      
   $sql = "CREATE TABLE $table_name (
    url varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;";

   require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
   dbDelta($sql);
 
   add_option("jal_db_version", $jal_db_version);
}

function my_template() {
  global $wpdb;
  global $ravpageContent;
  
  // table name in db
  $table_name = $wpdb->prefix . "ravpage_urls";
   
  $debug = isset($_REQUEST["debug"]);
   
  // handle case of api special address
  if ( preg_match("~/__ravpage/api(#|\?|\$)~",$_SERVER["REQUEST_URI"]) )
  {
    status_header(200);
   
    if ( $debug )
      echo "! handling api request\n";
      
    if ( $debug )
    {
      echo "params:\n";
      var_dump($_REQUEST);
    }

    if ( $debug )
      echo "! checking for proper action\n";      
      
    if ( isset( $_REQUEST["action"] ) )
      $action = $_REQUEST["action"];
    else
      die("nok - no action");
      
    if ( $debug )
      echo "! checking for proper timestamp\n";      
      
    if ( isset( $_REQUEST["timestampMajor"] ) && isset( $_REQUEST["timestampMinor"] ) )
    {
      $val = $_REQUEST["timestampMajor"];
      if ( !is_numeric($val) )
        die("nok - timestamp major invalid - not numeric '$val'");    
      $timestampMajor = intval($val);
      if ( $timestampMajor != $val )
        die("nok - timestamp major invalid - not int '$timestampMajor' != '$val'");
        
      $val = $_REQUEST["timestampMinor"];
      if ( !is_numeric($val) )
        die("nok - timestamp minor invalid - not numeric '$val'");    
      $timestampMinor = intval($val);
      if ( $timestampMinor != $val )
        die("nok - timestamp minor invalid - not int '$timestampMinor' != '$val'");        
        
      if ( $lastTimestampMajor = get_option("ravpageLastTimestampMajor") )
      {
        $lastTimestampMinor = get_option("ravpageLastTimestampMinor");
        
        if ( ( $timestampMajor < $lastTimestampMajor ) || ( $timestampMajor == $lastTimestampMajor ) && ( $timestampMinor <= $lastTimestampMinor ) ) 
          die("nok - timestamp old. timestampMajor=$timestampMajor. lastTimestampMajor=$lastTimestampMajor. timestampMinor=$timestampMinor. lastTimestampMinor=$lastTimestampMinor.");
      }
          
      update_option("ravpageLastTimestampMinor",$timestampMinor);          
      update_option("ravpageLastTimestampMajor",$timestampMajor);          
    }
    else
      die("nok - no timestamp");
    if ( $debug )
      echo "! checking for params\n";      
    if ( isset( $_REQUEST["paramsv2"] ) )
    {
      if ( $debug )
      {
        echo "! checking for params - unserializing\nparams: ";
        echo base64_decode($_REQUEST["paramsv2"]);
        echo "\n"; 
      }
              
      $params = unserialize(base64_decode($_REQUEST["paramsv2"]));
      if ( $debug )
      {
        echo "params:\n";
        var_dump($params);
      }
    }
    else
      die("nok - no params");
    if ( $debug )
      echo "! check for signature\n";            
    if ( isset( $_REQUEST["signature"] ) )
    {
      $signature = md5(base64_decode($_REQUEST["paramsv2"]) . $timestampMajor . $timestampMinor . $action . getKey());
      if ( $debug )
        echo "- raw signature (without key at the end): ***" . base64_decode($_REQUEST["paramsv2"]) . $timestampMajor . $timestampMinor . $action . "***\n- signature: $signature\n";
      
      if ( $signature != $_REQUEST["signature"] )
        die("nok - bad signature");
    }
    else
      die("nok - no signature");
      
    if ( $debug )
      echo "! all good - performing action '$action'\n";            
    
    switch ( $action )
    {
      case "isKeyValid":
        echo "ok";
      break;        
      case "syncurls":
        $wpdb->query("START TRANSACTION");
        $wpdb->query("DELETE FROM $table_name");
        foreach ($params as $rawurl)
        {
          $url = normalizeURL($rawurl);        
          //$url = preg_replace("~^[^:]+://~","",$rawurl);
          //$urlNoArgs = preg_replace("~(#|\?).*~","",$url);
          
          $rows_affected = $wpdb->insert( $table_name, array( 'url' => $url ) );          
        }
        $wpdb->query("COMMIT");
        echo "ok";
      break;
      default:
        echo "nok";
      break;
    }
    exit();
  }
  
  // general url case
  jal_install();
  // get the the access url address
  $url = full_url(); 
  if ( $debug )
    echo "! url = $url\n";            
  $urlNoArgs = normalizeURL($url);
  $urlNoArgsNoWWW = preg_replace("~^www\.~","",$urlNoArgs);
  if ( $debug )
    echo "! urlNoArgs = $urlNoArgs\n";            
  $urlArgs = preg_replace("~^[^#\?]+~","",$url);
  if ( $debug )
    echo "! urlArgs = $urlArgs\n";
    
  if ( $debug )
  {
    $rows = $wpdb->get_results("select * from $table_name");    
    foreach ( $rows as $row )
      echo "# stored url: " . $row->{"url"} . "\n";
  }                
  
  // check if the access url is in the "ravpage urls" list
  $rows = $wpdb->get_results("select * from $table_name where url='$urlNoArgs' OR REPLACE(url,'www.','')='$urlNoArgsNoWWW'");
  if ( count($rows) > 0 )
  {
    $url = $rows[0]->{"url"};
     
    if ( preg_match("~\?~",$urlArgs) )
      $urlArgs = str_replace("?","?wpurl=" . htmlspecialchars($url) . "&",$urlArgs);
    else
      $urlArgs = "?wpurl=" . htmlspecialchars($url) . "&" . $urlArgs;
    // pass on the $_SERVER variable, to allow detection of browser/smartphone
    $fields = array("HTTP_USER_AGENT","HTTP_ACCEPT","HTTP_REFERER","HTTP_X_WAP_PROFILE","HTTP_PROFILE");
    $serverOverride = array();
    foreach ( $fields as $field )
      if ( isset($_SERVER[$field]) )
        $serverOverride[$field] = $_SERVER[$field];
    if ( $debug )
      echo "Server override data: " . print_r($serverOverride,true) . "\n";
    $urlArgs .= "&__requestOverride=" . rawurlencode(json_encode($serverOverride));  
    $request = new WP_Http;
    $result = $request->request( "http://wp.ravpage.co.il" . $urlArgs );
    
    if ( $debug )
      echo "Sending request to http://wp.ravpage.co.il" . $urlArgs . "\n";
    
    if ( is_a($result,WP_Error) )
    {      
      if ( $debug )
      {
        echo "error!!!\n";
        var_dump($result);
      }
      else
        die("internal error");
    }
    
    status_header(200);
    $ravpageContent = $result["body"];
    
    if ( preg_match("~<title>(.*)</title>~Ums",$ravpageContent,$match) )
      echo str_replace("</title>",'</title><script>document.title = ' . json_encode($match[1]) . ';</script>',$ravpageContent);
    else
      echo $ravpageContent;

    die();
  }
}

// call function 'my_template' on every page access
add_action("template_redirect","my_template");

function my_plugin_menu() {
	add_options_page( 'Ravpage', 'Ravpage', 'activate_plugins', 'ravpage', 'my_plugin_options' );
	//print_r($GLOBALS['menu']);
	//die("shit");
}

function getKey() 
{
	$key = get_option("ravpageKey");
	if ( !$key )
	{
    $characters = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $key = "";
    for ($i = 0; $i < 64; $i++) 
        {
            $key .= $characters[rand(0, strlen($characters)-1)];
        }
    add_option("ravpageKey", $key, null, 'no');
  }
  
  return $key;  	
}

function my_plugin_options() {
	if ( !current_user_can( 'activate_plugins' ) )  {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
	
	$key = getKey();
	
	echo '<div class="wrap" style="direction:rtl">';
	echo "<p>הקוד למערכת רב-דף הוא: $key</p>";
	echo '</div>';
}

add_action( 'admin_menu', 'my_plugin_menu' );
?>