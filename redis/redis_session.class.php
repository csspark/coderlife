<?php
/**
 * This file is part of redis opreate class.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author csspark<178806504@qq.com>
 * @copyright csspark<178806504@qq.com>
 * @link http://shujun.site/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

function json_decode_array($d){
  return json_decode($d, true);
}
/**
 * By default this is a no-op but can be replaced by a custom function if REDIS_SESSION_ID_MUTATOR constant is defined
 * to the custom function name. This can be useful if you'd like to filter or change the session ID prior to it
 * being saved in Redis.
 * @param  string $id The session ID
 * @return string     Unaltered session ID
 */
function redis_session_id_mutator($id){
  return $id;
}

class RedisSession{
  private $serializer;
  private $unserializer;
  private $unpackItems;
  private $id_mutator;
  private $redis_servers = array("****","****","****");

  static function start($unpackItems = array()){
    if(!defined('REDIS_SESSION_PREFIX'))
      define('REDIS_SESSION_PREFIX', 'session:php:');
    if(!defined('REDIS_SESSION_SERIALIZER'))
      define('REDIS_SESSION_SERIALIZER', 'json_encode');
    if(!defined('REDIS_SESSION_UNSERIALIZER'))
      define('REDIS_SESSION_UNSERIALIZER', 'json_decode_array');
    if(!defined('REDIS_SESSION_ID_MUTATOR'))
      define('REDIS_SESSION_ID_MUTATOR', 'redis_session_id_mutator');
    $obj = new self($unpackItems);
    session_set_save_handler(
		array($obj, "open"),
		array($obj, "close"),
		array($obj, "read"),
		array($obj, "write"),
		array($obj, "destroy"),
		array($obj, "gc")
	);
	session_start(); 
    return $obj;
  }
  function __construct($unpackItems){
    $this->serializer = function_exists(REDIS_SESSION_SERIALIZER) ? REDIS_SESSION_SERIALIZER : 'json_encode';
    $this->unserializer = function_exists(REDIS_SESSION_UNSERIALIZER) ? REDIS_SESSION_UNSERIALIZER : 'json_decode_array';
    $this->id_mutator = function_exists(REDIS_SESSION_ID_MUTATOR) ? REDIS_SESSION_ID_MUTATOR : 'redis_session_id_mutator';
    $this->unpackItems = $unpackItems;
    // $this->redis = new \Predis\Client($redis_conf);
    $this->redis = new RedisCluster(NULL, $this->redis_servers);

  }
  function serializer(){
    return call_user_func_array($this->serializer, func_get_args());
  }
  function unserializer(){
    return call_user_func_array($this->unserializer, func_get_args());
  }
  function id_mutator(){
    return call_user_func_array($this->id_mutator, func_get_args());
  }
  function read($id) {
    $d = $this->unserializer($this->redis->get(REDIS_SESSION_PREFIX . $this->id_mutator($id)));
    $_SESSION = $d;
  }
  function write($id, $data) {
    /**
     * RANT: It's seemingly impossible to parse the value in $data.
     * Example:
     *
     * Serialising the following:
     * $_SESSION['test'] = "ohai";
     * $_SESSION['md'] = array('test2' => array('multidimensional' => 'array'));
     * $_SESSION['more'] = new stdClass;
     *
     * Gives:
     *
     * test|s:4:"ohai";md|a:1:{s:5:"test2";a:1:{s:16:"multidimensional";s:5:"array";}}more|O:8:"stdClass":0:{}
     *
     * Where are the delimeters between keys? I'm testing this on PHP 5.3.8 with
     * Suhosin patch, and session_decode() gives false.
     *
     * This is why, on write, we have to access $_SESSION and encode that into
     * a format which is more generic and world readable
     */
    $data = $_SESSION;
    $ttl = ini_get("session.gc_maxlifetime");

    $unpackItems = $this->unpackItems;  
    $serializer = $this->serializer;
    $id_mutator = $this->id_mutator;
    $this->redis->setex(REDIS_SESSION_PREFIX . $id_mutator($id), $ttl, $serializer($data));
      // Unpack individual properties into their own keys, if we want
      //foreach ($unpackItems as $item) {
        //$keyname = REDIS_SESSION_PREFIX . $id . ":" . $item;
        //if (isset($_SESSION[$item])) {
          //$r->setex($keyname, $ttl, $_SESSION[$item]);
        //} else {
          //$r->del($keyname);
        //}
      //}
    // });
      // $this->redis->exec();

  }
  function destroy($id) {
    $this->redis->del(REDIS_SESSION_PREFIX . $this->id_mutator($id));
    //$unpacked = $this->redis->keys(REDIS_SESSION_PREFIX . $id . ":*");
    //foreach ($unpacked as $unp) {
      //$this->redis->del($unp);
    //}
  }
  // These functions are all noops for various reasons... opening has no practical meaning in
  // terms of non-shared Redis connections, the same for closing. Garbage collection is handled by
  // Redis anyway.
  function open($path, $name) {}
  function close() {}
  function gc($age) {}
}

/**demo
* overrides PHP's default session_save_handler and calls session_start()
RedisSession::start(); 
//$sessionid=session_id();

* use sessions as normal
$test_value = array("openid"=>"oqtrCvlprmiESyPniuhSzUW-C7kk","nickname"=> "欧阳书俊","sex"=> 1,"language"=>"zh_CN","bak"=>array(1,2,3));
$_SESSION["no"][$sessionid] = $test_value;

var_dump($_SESSION["no"][$sessionid]);

*/