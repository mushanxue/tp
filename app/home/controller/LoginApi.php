<?php
/**
 * tpshop
 * ============================================================================
 * * 版权所有 2015-2027 深圳搜豹网络科技有限公司，并保留所有权利。
 * 网站地址: http://www.tp-shop.cn
 * ----------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在不用于商业目的的前提下对程序代码进行修改和使用 .
 * 不允许对程序代码以任何形式任何目的的再发布。
 * 采用最新Thinkphp6
 * ============================================================================
 * 微信交互类
 */
namespace app\home\controller;
use think\facade\View;
use app\common\logic\UsersLogic;
use app\common\logic\CartLogic;
use think\facade\Db;

class LoginApi extends Base {
    public $config;
    public $oauth;
    public $class_obj;

    public function __construct(){
        parent::__construct();
        session('?user');
        $this->oauth = input('get.oauth');
        //获取配置
        $data = Db::name('Plugin')->where("code",$this->oauth)->where("type","login")->find();
        $this->config = unserialize($data['config_value']); // 配置反序列化
        if(!$this->oauth)
            $this->error('非法操作',url('User/login'));
        include_once  "../plugins/login/{$this->oauth}/{$this->oauth}.class.php";
        $class = '\\'.$this->oauth; //
        $this->class_obj = new $class($this->config); //实例化对应的登陆插件
    }

    public function login(){
        if(!$this->oauth)
            $this->error('非法操作',url('User/login'));
        include_once  "../plugins/login/{$this->oauth}/{$this->oauth}.class.php";
        $this->class_obj->login();
    }

    public function callback()
    {
        $data = $this->class_obj->respon();
        $logic = new UsersLogic();
        if($data['oauth'] == 'weixin'){
            if(isMobile()){
                $data['oauth_child'] = 'mp';
            }else{
                $data['oauth_child'] = 'open'; // pc端应该是 open 不是 mp
            }
        }
        
        //过滤昵称中的特殊字符
        $data['nickname'] && $data['nickname'] = replaceSpecialStr($data['nickname']);
        
        if(isMobile()){
            $module = 'Mobile';
        }else{
            $module = 'Home';
        }
        session('third_oauth',$data);
        //如果用户已经登录, 直接绑定
        $user = session("user");
        if($user){
            $res = $logic->oauth_bind_new($user);
            if($res['status'] == 1){
                $this->success("绑定成功", url('Home/User/bind_auth'));
            }else{
                $this->error("绑定失败,失败原因:".$res['msg'] , url('Home/User/bind_auth'));
            }
        }

        if($data['unionid']){
            if($data['oauth'] == 'weixin' || $data['oauth'] == 'wx' || $data['oauth'] == 'miniapp'){
                $thirdUser = Db::name('OauthUsers')->where(function ($query) {
                    $query->where('oauth', 'weixin')->whereor('oauth', 'wx')->whereor('oauth', 'miniapp');
                })->where(['unionid'=>$data['unionid']])->find();
            }else{
                $thirdUser = Db::name('OauthUsers')->where(['unionid'=>$data['unionid'], 'oauth'=>$data['oauth']])->find();
            }
        }else{
            $thirdUser = M('OauthUsers')->where(['openid'=>$data['openid'], 'oauth'=>$data['oauth']])->find();
        }

        if(empty($thirdUser)){
            //用户未关联账号, 跳到关联账号页
            if(isMobile()){
                $url = url($module."/User/binding",array('oauth'=>$data['oauth']));
            }else{
                $url = url($module."/User/bind_guide",array('oauth'=>$data['oauth']));
            }
            return redirect($url);
        }else{
            //已关联账号就直接登录
            $data = $logic->thirdLogin_new($data);
        }
        if ($data['status'] != 1){
            $this->error($data['msg']);
        }
        session('user', $data['result']);
        setcookie('user_id', $data['result']['user_id'], null, '/');
        setcookie('is_distribut', $data['result']['is_distribut'], null, '/');
        $nickname = empty($data['result']['nickname']) ? '第三方用户' : $data['result']['nickname'];
        setcookie('uname', urlencode($nickname), null, '/');
        setcookie('cn', 0, time() - 3600, '/');
        // 登录后将购物车的商品的 user_id 改为当前登录的id
        Db::name('cart')->where("session_id", $this->session_id)->save(array('user_id' => $data['result']['user_id']));
    
        $cartLogic = new CartLogic();
        $cartLogic->setUserId($data['result']['user_id']);
        $cartLogic->doUserLoginHandle();  //用户登录后 需要对购物车 一些操作
        return redirect(url($module.'/index/index'));
    }
}