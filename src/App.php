<?php
/**
 * php-micro-mvc
 *
 * @category   micro framework
 * @package    php-micro-mvc
 * @license    https://opensource.org/licenses/MIT  MIT License
 * @version    0.1
 * @since      2019-02-03
 */
declare(strict_types=1);
namespace MicroMvc;

final class App {
    public static function run(string $env, string $baseDir){
        //static::registerShutdownPage();
        try {
            Config::load($env, $baseDir);
            Action::setDirectory($baseDir);

            //ClassLoader::register();
            ClassLoader::setNameSpaces([
            //    '\\App\\Controllers' => $baseDir . '/controllers',
            //    '\\App\\Services' => $baseDir . '/services',
                '\\App\\Models' => $baseDir . '/models',
            ]);
            $action = (isset($_GET['action'])) ? $_GET['action']: '';
            Router::forward($action);
        }
        catch(HttpNotFoundException $e){
            Router::forward('error.http404', ['error' => $e]);
        }
        catch(HttpBadRequestException | SecurityException $e){
            Router::forward('error.http400', ['error' => $e]);
        }
        catch(Http503Exception $e){
            Router::forward('error.http503', ['error' => $e]);
        }
        catch(\Exception $e){
            Router::forward('error.http500', ['error' => $e->getMessage()]);
        }
    }
    protected static function registerShutdownPage(){
        register_shutdown_function(function(){
            if (!headers_sent()) {
                Router::forward('error.http500', ['error' => 'timeout']);
            }
        });
    }
}

final class HttpNotFoundException extends \RuntimeException {}
final class HttpBadRequestException extends \RuntimeException {}
final class Http503Exception extends \RuntimeException {}
final class SecurityException extends \RuntimeException {}
final class NoAppConfigException extends \LogicException {}
final class ClassNotFoundException extends \LogicException {}


final class ClassLoader {
    protected static $_nameSpaces = [];

    //public static function register() {
    //    spl_autoload_register([__CLASS__, 'loadClass']);
    //}
    public static function loadClass(string $class) {
        $rp = static::resolve($class);
        return ($rp) ? static::load($rp): FALSE;
    }
    public static function load(string $path) {
        if (!is_readable($path)) {
            return FALSE;
        }
        require $path;
        return TRUE;
    }
    public static function setNameSpaces(array $nameSpaces = []) {
        static::$_nameSpaces = $nameSpaces;
    }
    public static function resolve(string $class) {
        $dir = '';
        $name = '';
        foreach (static::$_nameSpaces as $ns => $path) {
            $findMe = $ns . '\\';
            if (strpos($class, $findMe) === 0) {
                $dir = $path;
                $name = substr($class, strlen($ns) + 1);
                break;
            }
        }
        if (!$dir || !$name) {
            return;
        }
        $path = $dir . '/' . $name . '.php';
        $rp = realpath($path);
        return $rp;
    }
}

final class Action {
    protected static $_baseDir = '';
    protected $_params = [];
    protected $_router = '';
    protected $_config = '';
    protected $_request = '';
    protected $_response = '';
    protected $_models = NULL;

    public static function setDirectory(string $path) {
        static::$_baseDir = $path;
    }
    public function __construct(array $params){
        $this->_params = $params;
        $this->_router = Router::class;
        $this->_config = Config::class;
        $this->_request = Request::class;
        $this->_response = Response::class;
        $this->_models = new Models;
    }
    public function __get(string $name){
        return $this->_services->$name;
    }
    public function run(string $action) {
        $path = static::$_baseDir . '/actions/' . strtolower($action) . '.php';
        if (!is_readable($path)) {
            throw new HttpNotFoundException('No Action: ' . $action);
        }
        include $path;
    }
}

abstract class Controller {
    protected $_params = [];
    protected $_router = '';
    protected $_config = '';
    protected $_request = '';
    protected $_response = '';
    protected $_models = NULL;
    protected $_services = NULL;

    public function __construct(array $params){
        $this->_params = $params;
        $this->_router = Router::class;
        $this->_config = Config::class;
        $this->_request = Request::class;
        $this->_response = Response::class;
        $this->_models = new Models;
        $this->_services = new Services($this->_models);
    }
    public function __get(string $name){
        return $this->_services->$name;
    }
}

final class Router {
    public static function forward(string $action, array $params = []) {
        static::_checkActionName($action);
        static::_runAction($action, $params);
        //static::_runController($action, $params);
    }
    protected static function _checkActionName(string $action) {
        if (!$action) {
            return;
        }
        if (preg_match('/\A([a-z][a-z0-9]{0,19})([.][a-z][a-z0-9]{0,19})?\z/', $action) !== 1) {
            //throw new SecurityException('invalid action: ' . $action);
            throw new HttpNotFoundException;
        }
    }
    protected static function _runAction(string $action, array $params = []) {
        list($controller, $method) = static::_parse($action);
        $name = strtolower(($method === 'index') ? $controller: $controller . '.' . $method);
        $cls = new Action($params);
        $cls->run($name);
    }
    protected static function _runController(string $action, array $params = []) {
        list($controller, $method) = static::_parse($action);
        $ns = '\\App\\Controllers\\' . ucfirst(strtolower($controller)) . 'Controller';
        
        if (!class_exists($ns)) {
            if (!ClassLoader::loadClass($ns)) {
                throw new HttpNotFoundException;
            }
        }
        $cls = new $ns($params);
        
        if (!method_exists($cls, $method)){
            throw new HttpNotFoundException;
        }
        call_user_func([$cls, $method]);
    }
    protected static function _parse(string $action, string $separator = '.'):array {
        $list = explode($separator, $action, 2);
        $controller = ($list[0]) ? $list[0]: 'index';
        $method = (isset($list[1]) && $list[1]) ? $list[1]: 'index';
        
        return [$controller, $method];
    }
}

final class Config {
    protected static $_env = '';
    protected static $_cache = NULL;
    
    public static function env():string {
        return statis::$_env;
    }
    public static function get(string $path = '', $defaultValue = NULL, string $separator = '.'){
        $list = array_filter(explode($separator, $path));
        $ptr = static::$_cache;

        foreach ($list as $item) {
            if (!isset($ptr->$item)) {
                return $defaultValue;
            }
            $ptr = $ptr->$item;
        }
        return $ptr;
    }
    public static function load(string $env, string $baseDir) {
        static::$_env = $env;
        $path = $baseDir . '/configs/' . $env . '.php';
        $rp = realpath($path);

        if (!$rp || !is_readable($rp)) {
            throw new NoAppConfigException;
        }
        $arr = include($rp);
        $obj = static::_arrayToObject($arr);
        static::$_cache = $obj;

        if (isset(static::$_cache->php)) {
            static::_setPhpSettings(static::$_cache->php);
        }
    }
    protected static function _setPhpSettings($config) {
        foreach ($config as $key => $val) {
            $sv = (string)$val;
            if ($key && $sv) {
                ini_set($key, $sv);
            }
        }
    }
    protected static function _arrayToObject($d) {
        return (is_array($d)) ? (object) array_map([__CLASS__, __METHOD__], $d): $d;
    }
    protected static function _objectToArray($d):array {
        if (is_object($d)) {
            $d = get_object_vars($d);
        }
        return (is_array($d)) ? array_map([__CLASS__, __METHOD__], $d): $d;
    }
}

final class Services {
    protected $_services = NULL;
    protected $_models = NULL;

    public function __construct(&$models){
        $this->_services = new \stdClass;
        $this->_models = $models;
    }
    public function __get(string $name) {
        if (!isset($this->_services->$name)) {
            $ns = '\\App\\Services\\' . ucfirst(strtolower($name));
            
            if (!class_exists($ns)) {
                if (!ClassLoader::loadClass($ns)) {
                    throw new ClassNotFoundException($ns);
                }
            }
            $cls = new $ns($this, $this->_models);
            $this->_services->$name = $cls;
        }
        return $this->_services->$name;
    }
}
abstract class Service {
    protected $_config = '';
    protected $_services = NULL;
    protected $_models = NULL;
    
    public function __construct(&$services, $models){
        $this->_config = Config::class;
        $this->_services = $services;
        $this->_models = $models;
    }
}

final class Models {
    protected $_models = NULL;

    public function __construct(){
        $this->_models = new \stdClass;
    }
    public function __get(string $name) {
        if (!isset($this->_models->$name)) {
            $ns = '\\App\\Models\\' . ucfirst(strtolower($name));

            if (!class_exists($ns)) {
                if (!ClassLoader::loadClass($ns)) {
                    throw new ClassNotFoundException($ns);
                }
            }
            $cls = new $ns($this);
            $this->_models->$name = $cls;
        }
        return $this->_models->$name;
    }
}
abstract class Model {
    protected $_config = '';
    protected $_models = NULL;
    
    public function __construct($models){
        $this->_config = Config::class;
        $this->_models = $models;
    }
}

final class Request {
    public static function isGet() {
        return static::_method() === 'GET';
    }
    public static function isPost() {
        return static::_method() === 'POST';
    }
    public static function isPut() {
        return static::_method() === 'PUT';
    }
    public static function isDelete() {
        return static::_method() === 'DELETE';
    }
    public static function isAjax() {
        return strtolower(static::server('HTTP_X_REQUESTED_WITH','')) === 'xmlhttprequest';
    }
    protected static function _method() {
        return static::server('REQUEST_METHOD');
    }
    public static function get(string $key = '', $defaultValue = NULL) {
        return (!$key) ? $_GET: ((isset($_GET[$key])) ? $_GET[$key]: $defaultValue);
    }
    public static function post(string $key = '', $defaultValue = NULL) {
        return (!$key) ? $_POST: ((isset($_POST[$key])) ? $_POST[$key]: $defaultValue);
    }
    public static function files(string $key = '', $defaultValue = NULL) {
        return (!$key) ? $_FILES: ((isset($_FILES[$key])) ? $_FILES[$key]: $defaultValue);
    }
    public static function server(string $key = '', $defaultValue = NULL) {
        return (!$key) ? $_SERVER: ((isset($_SERVER[$key])) ? $_SERVER[$key]: $defaultValue);
    }
    public static function cookie(string $key = '', $defaultValue = NULL) {
        return (!$key) ? $_COOKIE: ((isset($_COOKIE[$key])) ? $_COOKIE[$key]: $defaultValue);
    }
    public static function session(string $key = '', $defaultValue = NULL) {
        return (!$key) ? $_SESSION: ((isset($_SESSION[$key])) ? $_SESSION[$key]: $defaultValue);
    }
}

final class Response {
    public static function json($obj, int $httpStatus = 200) {
        static::header('Content-Type', 'application/json; chrset=utf-8', TRUE, $httpStatus);
        echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
    public static function redirect($url, $httpStatus = 302) {
        header('Location: ' . $url, TRUE, $httpStatus);
        exit;
    }
    public static function header(string $name, string $value, bool $replace = TRUE, int $httpStatus = 200) {
        header($name . ': ' . $value, $replace, $httpStatus);
    }
    public static function rawHeader(string $value, bool $replace = TRUE, int $httpStatus = 200) {
        header($value, $replace, $httpStatus);
    }
}

//return App::class;
