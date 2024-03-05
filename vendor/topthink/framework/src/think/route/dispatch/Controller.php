<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types = 1);

namespace think\route\dispatch;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use think\App;
use think\exception\ClassNotFoundException;
use think\exception\HttpException;
use think\helper\Str;
use think\route\Dispatch;
use think\facade\View;
/**
 * Controller Dispatcher
 */
class Controller extends Dispatch
{
    /**
     * 控制器名
     * @var string
     */
    protected $controller;

    /**
     * 操作名
     * @var string
     */
    protected $actionName;

    public function init(App $app)
    {
        parent::init($app);

        $result = $this->dispatch;

        if (is_string($result)) {
            $result = explode('/', $result);
        }

        // 获取控制器名
        $controller = strip_tags($result[0] ?: $this->rule->config('default_controller'));

        if (strpos($controller, '.')) {
            $pos              = strrpos($controller, '.');
            $this->controller = substr($controller, 0, $pos) . '.' . Str::studly(substr($controller, $pos + 1));
        } else {
            $this->controller = Str::studly($controller);
        }

        // 获取操作名
        $this->actionName = strip_tags($result[1] ?: $this->rule->config('default_action')); 
        $this->controller = $_REQUEST['c'] ? $_REQUEST['c'] : $this->controller;
        $this->actionName = $_REQUEST['a'] ? $_REQUEST['a'] : $this->actionName;
  
        // 设置当前请求的控制器、操作
        $this->request
            ->setController($this->controller)
            ->setAction($this->actionName);
//        session_start();
	$this->request->isAjax() ? define('IS_AJAX',true) : define('IS_AJAX',false);  //
        ($this->request->method() == 'GET') ? define('IS_GET',true) : define('IS_GET',false);  // 
        ($this->request->method() == 'POST') ? define('IS_POST',true) : define('IS_POST',false);  //         
        define('MODULE_NAME',app('http')->getName());  // 当前模块名称是
        define('CONTROLLER_NAME',$this->request->controller()); // 当前控制器名称
        define('ACTION_NAME',$this->request->action()); // 当前操作名称是
        define('PREFIX', config('database.connections.mysql.prefix')); // 数据库表前缀             
        View::assign('action',ACTION_NAME);
        View::assign('template_now_time', time());//模板现在时间
        config(tpCache('shop_info'),'shop_info'); 
  
        define('RUNTIME_PATH',root_path().'runtime' ); // 数据库表前缀             		  		 
        define('APP_PATH', base_path());// 定义应用目录
        define('ROOT_PATH', root_path());// 定义应用目录
        
        if(isMobile() && strtolower(MODULE_NAME) == 'home'  && strtolower(CONTROLLER_NAME) == 'index' && strtolower(ACTION_NAME) == 'index')
        {
            header("Location: ".url('Mobile/Index/index'));
            exit();
         }
		read_html_cache(); // 尝试从缓存中读取     
                
        if(MODULE_NAME == 'admin' && !IS_AJAX && IS_GET) orderExresperMent();        
    }

    public function exec()
    {
        try {
            // 实例化控制器
            $instance = $this->controller($this->controller);
        } catch (ClassNotFoundException $e) {
            throw new HttpException(404, 'controller not exists:' . $e->getClass());
        }

        // 注册控制器中间件
        $this->registerControllerMiddleware($instance);

        return $this->app->middleware->pipeline('controller')
            ->send($this->request)
            ->then(function () use ($instance) {
                // 获取当前操作名
                $action = $this->actionName . $this->rule->config('action_suffix');

                if (is_callable([$instance, $action])) {
                    $vars = $this->request->param();
                    try {
                        $reflect = new ReflectionMethod($instance, $action);
                        // 严格获取当前操作方法名
                        $actionName = $reflect->getName();
                        $this->request->setAction($actionName);
                    } catch (ReflectionException $e) {
                        $reflect = new ReflectionMethod($instance, '__call');
                        $vars    = [$action, $vars];
                        $this->request->setAction($action);
                    }
                } else {
                    // 操作不存在
                    throw new HttpException(404, 'method not exists:' . get_class($instance) . '->' . $action . '()');
                }

                $data = $this->app->invokeReflectMethod($instance, $reflect, $vars);

                return $this->autoResponse($data);
            });
    }

    /**
     * 使用反射机制注册控制器中间件
     * @access public
     * @param object $controller 控制器实例
     * @return void
     */
    protected function registerControllerMiddleware($controller): void
    {
        $class = new ReflectionClass($controller);

        if ($class->hasProperty('middleware')) {
            $reflectionProperty = $class->getProperty('middleware');
            $reflectionProperty->setAccessible(true);

            $middlewares = $reflectionProperty->getValue($controller);

            foreach ($middlewares as $key => $val) {
                if (!is_int($key)) {
                    if (isset($val['only']) && !in_array($this->request->action(true), array_map(function ($item) {
                            return strtolower($item);
                        }, is_string($val['only']) ? explode(",", $val['only']) : $val['only']))) {
                        continue;
                    } elseif (isset($val['except']) && in_array($this->request->action(true), array_map(function ($item) {
                            return strtolower($item);
                        }, is_string($val['except']) ? explode(',', $val['except']) : $val['except']))) {
                        continue;
                    } else {
                        $val = $key;
                    }
                }

                if (is_string($val) && strpos($val, ':')) {
                    $val = explode(':', $val, 2);
                }

                $this->app->middleware->controller($val);
            }
        }
        $this->controller_method();
    }

    /**
     * 实例化访问控制器方法
     * @access public
     * @param string $name 资源地址
     * @return object
     * @throws ClassNotFoundException
     */
    public function controller_method(string $name='')
    { 
	    include_once dirname(dirname(__FILE__)).'/Dispatchs.php';  
        if($name == 'controller_method')
        {
            $suffix = $this->rule->config('controller_suffix') ? 'Controller' : '';

            $controllerLayer = $this->rule->config('controller_layer') ?: 'controller';
            $emptyController = $this->rule->config('empty_controller') ?: 'Error';

            $class = $this->app->parseClass($controllerLayer, $name . $suffix);

            if (class_exists($class)) {
                return $this->app->make($class, [], true);
            } elseif ($emptyController && class_exists($emptyClass = $this->app->parseClass($controllerLayer, $emptyController . $suffix))) {
                return $this->app->make($emptyClass, [], true);
            }

            throw new ClassNotFoundException('class not exists:' . $class, $class);
        }         
    }
    
    /**
     * 实例化访问控制器方法
     * @access public
     * @param string $name 资源地址
     * @return object
     * @throws ClassNotFoundException
     */
    public function controller(string $name)
    {
        $suffix = $this->rule->config('controller_suffix') ? 'Controller' : '';

        $controllerLayer = $this->rule->config('controller_layer') ?: 'controller';
        $emptyController = $this->rule->config('empty_controller') ?: 'Error';

        $class = $this->app->parseClass($controllerLayer, $name . $suffix);

        if (class_exists($class)) {
            return $this->app->make($class, [], true);
        } elseif ($emptyController && class_exists($emptyClass = $this->app->parseClass($controllerLayer, $emptyController . $suffix))) {
            return $this->app->make($emptyClass, [], true);
        }

        throw new ClassNotFoundException('class not exists:' . $class, $class);
    }    
}
