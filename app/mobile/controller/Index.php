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
 * $Author: 当燃 2016-01-09
 */
namespace app\mobile\controller;
use think\facade\View;

use think\facade\Db;
use app\common\logic\wechat\WechatUtil;

class Index extends MobileBase {

    public function index(){
        $edit_ad = input('edit_ad');
        $diy_index = Db::name('mobile_template')->where('is_index=1')->field('template_html,block_info')->find();
        if($diy_index){
            $html = htmlspecialchars_decode($diy_index['template_html']);
            $logo=tpCache('shop_info.wap_home_logo');
            View::assign('wap_logo',$logo);
            View::assign('html',$html);
            View::assign('is_index',"1");
            View::assign('info',$diy_index['block_info']);
            return View::fetch('index2');
            exit();
        }

//        $hot_goods = Db::name('goods')->where("is_hot=1 and is_on_sale=1")->order('goods_id DESC')->limit(6)->cache(true,TPSHOP_CACHE_TIME)->select();//首页热卖商品
//        View::assign('hot_goods',$hot_goods);
        $recommend_goods = \app\common\model\Goods::where("is_recommend=1 and is_on_sale=1")->order('sort DESC')->limit(6)->cache(true,TPSHOP_CACHE_TIME)->select();//首页推荐商品
        //$recommend_goods = Db::name('goods')->where("is_recommend=1 and is_on_sale=1")->order('sort DESC')->limit(6)->cache(true,TPSHOP_CACHE_TIME)->select();//首页推荐商品
        
        
        //获取拼团列表
        //$team_activity = Db::name('team_activity')->with(['teamGoodsItem'])->where(['status'=>1,'deleted'=>0])->limit(6)->cache(true,TPSHOP_CACHE_TIME)->select();
        $team_activity = \app\common\model\TeamActivity::with(['teamGoodsItem'])->where(['status'=>1,'deleted'=>0])->limit(6)->cache(true,TPSHOP_CACHE_TIME)->select();
        View::assign('team_activity',$team_activity);

        //秒杀商品
        $now_time = time();  //当前时间
        if(is_int($now_time/7200)){      //双整点时间，如：10:00, 12:00
            $start_time = $now_time;
        }else{
            $start_time = floor($now_time/7200)*7200; //取得前一个双整点时间
        }
        $end_time = $start_time+7200;   //结束时间
        $flash_sale_list = Db::name('goods')->alias('g')
            ->field('g.goods_id,g.goods_name,g.shop_price,f.price,s.item_id')
            ->join('flash_sale f','g.goods_id = f.goods_id','LEFT')
            ->join('spec_goods_price s','s.prom_id = f.id AND g.goods_id = s.goods_id','LEFT')
            ->where("start_time >= $start_time and end_time <= $end_time and f.is_end = 0  and g.is_on_sale = 1")
            ->limit(3)->select();
        View::assign('flash_sale_list',$flash_sale_list);
        View::assign('start_time',$start_time);
        View::assign('end_time',$end_time);
        View::assign('recommend_goods',$recommend_goods);
        View::assign('edit_ad', $edit_ad);        
        return View::fetch();
    }

    public function index2(){
        $id=input('get.id/d');
        $role=input('get.role'); 

        if($role){
            $arr=Db::name('industry_template')->where(['id'=>$id])->field('template_html,block_info')->find();
        }else{
            if($id){
                $arr=Db::name('mobile_template')->where(['id'=>$id])->field('template_name ,template_html,block_info,is_index')->find();
            }else{
                $arr=Db::name('mobile_template')->order('id DESC')->limit(1)->field('template_name ,template_html,block_info,is_index')->find();
            } 
        }

        $html=htmlspecialchars_decode($arr['template_html']);
        $logo=tpCache('shop_info.wap_home_logo');
        View::assign('wap_logo',$logo);
        View::assign('html',$html);
        View::assign('is_index',$arr['is_index']); //是否为首页, 如果不是首页, 则显示"返回"按钮
        View::assign('info',$arr['block_info']);
        View::assign('template_name',$arr['template_name']);
        return View::fetch();
    }

    //商品列表板块参数设置
    public function goods_list_block(){
        $data=input('post.');
        $sql_where = input('sql_where');//dump($sql_where);exit;
        // 13时，轮播传的是sql_where
        if($sql_where){
            if(!empty($sql_where['label']) && !isset($data['label'])){
                $data['label'] = $sql_where['label'];
            }
            if(!empty($sql_where['ids']) && !isset($data['ids'])){
                $data['ids'] = $sql_where['ids'];
            }
            if(!empty($sql_where['min_price']) && !empty($sql_where['max_price']) && $sql_where['min_price'] < $sql_where['max_price']){
                $data['min_price'] = $sql_where['min_price'];
                $data['max_price'] = $sql_where['max_price'];
            }
        }

        $block = new \app\common\logic\Block();
        $goodsList = $block->goods_list_block($data);

        $html='';
        if($data['block_type']==13){
            foreach ($goodsList as $k => $v) {
                $html.='<div class="containers-slider-item">';
                $html.='<div class="seckill-item-img">';
                $html.='<a href="/Mobile/Goods/goodsInfo/id/'.$v["goods_id"].'"><img src="'.$v["original_img"].'" /></a>';
                $html.='</div>';
                $html.='<div class="seckill-item-name"><p>'.$v["goods_name"].'</p></div>';
                $html.='<div class="seckill-item-price" class="p"><span class="fl">￥<em>'.$v['shop_price'].'</em></span>';
                $html.='</div></div>';
            }
        }else{
            foreach ($goodsList as $k => $v) {
                $num = $v['sales_sum']+$v['virtual_sales_sum'];
                $html.='<li>';
                $html.='<a class="tpdm-goods-pic" href="/Mobile/Goods/goodsInfo/id/'.$v["goods_id"].'"><img src="'.$v["original_img"].'" alt="" /></a>';
                $html.='<a href="/Mobile/Goods/goodsInfo/id/'.$v["goods_id"].'" class="tpdm-goods-name">'.$v["goods_name"].'</a>';
                $html.= $v['label_name'] ? '<span class="rx-sp">'.$v['label_name'].'</span>' :  '<span class="rx-sp"  style="height: 0.747rem;border:none"></span>';
                $html.='<div class="tpdm-goods-des">';
                $html.='<div class="tpdm-goods-price">￥<em>'.explode_price($v['shop_price'],0).'</em>.<em>'.explode_price($v['shop_price'],1).'</em>'.'</div>';
                $html.='<a class="tpdm-goods-like">已售'.$num.'件</a>';
                $html.='</div>';
                $html.='</li>';
            } 
        }
        $this->ajaxReturn(['status' => 1, 'msg' => '成功', 'result' =>$html,'data'=>$data,'goods_list'=>$goodsList]);
    }


    //自定义页面获取秒杀商品数据
    public function get_flash(){
        $now_time = time();  //当前时间
        if(is_int($now_time/7200)){      //双整点时间，如：10:00, 12:00
            $start_time = $now_time;
        }else{
            $start_time = floor($now_time/7200)*7200; //取得前一个双整点时间
        }
        $end_time = $start_time+7200;   //结束时间
        $flash_sale_list = Db::name('goods')->alias('g')
            ->field('g.goods_id,g.goods_name,g.original_img,g.shop_price,fg.price,s.item_id')
            ->join('flash_sale f','g.prom_id = f.id','LEFT')
            ->join('flash_goods fg','fg.flash_sale_id=f.id','LEFT')
            ->join('spec_goods_price s','s.prom_id = f.id AND g.goods_id = s.goods_id','LEFT')
            ->where("start_time = $start_time and end_time = $end_time and f.is_end = 0 and g.is_on_sale = 1")
            ->limit(4)->select();
        $str='';
        $flash_sale_list = $flash_sale_list->toArray();
        if($flash_sale_list){
            foreach ($flash_sale_list as $k => $v) {
                $str.='<a href="'.url('Mobile/Activity/flash_sale_list').'">';
                if(strpos($v['original_img'],'/public') === 0 ){
                    if(!file_exists('.'.$v['original_img'])){
                        $v['original_img'] = '/public/images/icon_goods_thumb_empty_300.png';
                    }
                }elseif(empty($v['original_img'])){
                    $v['original_img'] = '/public/images/icon_goods_thumb_empty_300.png';
                }
                $str.='<img src="'.$v['original_img'].'" alt="" />';
                $str.='<p>'.$v['goods_name'].'</p>';
                $str.='<span>￥'.$v['price'].'</span>';
                $str.='<i>￥'.$v['shop_price'].'</i></a>';
            }
        }
        $time=date('H',$start_time);
        $this->ajaxReturn(['status' => 1, 'msg' => '成功','html' => $str, 'start_time'=>$time, 'end_time'=>$end_time]);
    }

    /**
     * 智能表单提交
     */
    public function save_form(){
        $block = new \app\common\logic\Block();
        $data = $block->add_form(input('post.'));
        $this->ajaxReturn($data);
    }

    /**
     * 分类列表显示
     */
    public function categoryList(){
        return View::fetch();
    }

    /**
     * 模板列表
     */
    public function mobanlist(){
        $arr = glob("D:/wamp/www/svn_tpshop/mobile--html/*.html");
        foreach($arr as $key => $val)
        {
            $html = end(explode('/', $val));
            echo "<a href='http://www.php.com/svn_tpshop/mobile--html/{$html}' target='_blank'>{$html}</a> <br/>";            
        }        
    }

    /**
     * 门店列表
     * province,如果有省名，传省名字
     * lng,lat,search_radius，经伟度，查找半径范围内的门店
     */
    public function shopList(){
        $data = input('param.');
        if(isset($data['province'])){
            $province_id = Db::name('region')->where('name',$data['province'])->value('id');
            if($province_id){
                $where['province_id'] = $province_id;
            }
        }
        $where['deleted'] = 0;
        $where['shop_status'] = 1;
        $shop_list = Db::name('shop')->field('shop_id,shop_name,province_id,city_id,district_id,shop_address,longitude,latitude,deleted,shop_desc')->where($where)->select()->toArray();
        $shop_logic = new \app\common\logic\Shop();
        $shop_list = $shop_logic->filterDistance($shop_list,$data['lng'], $data['lat'],$data['search_radius']);
        $this->ajaxReturn(['status' => 1, 'result' => $shop_list]);
    }
    public function newsList(){
        $ids = input('ids');
        if($ids){
            $ids_arr = explode(',',$ids);
            $where['article_id'] = ['in', $ids_arr];
        }
        $num = input('new_num/d', 2);
        $num = $num > 10 ? $num : $num;
        $where['publish_time'] = ['<=',time()];
        $where['is_open'] = 1;
        $list= Db::view('news')
            ->view('newsCat','cat_name','newsCat.cat_id=news.cat_id','left')
            ->where($where)
            ->order('publish_time DESC')
            ->limit($num)
            ->select();
        foreach($list as $k=>$v){
            // 自带的html样式，有的影响很大
            $list[$k]['content'] = '<p>'. cutstr_html(htmlspecialchars_decode($list[$k]['content']),60).'</p>';
            if(strpos($v['thumb'],'/public') === 0 ){
                if(!file_exists('.'.$v['thumb'])){
                    $list[$k]['thumb'] = '/public/images/icon_goods_thumb_empty_300.png';
                }
            }elseif(empty($v['thumb'])){

                $list[$k]['thumb'] = '/public/images/icon_goods_thumb_empty_300.png';
            }
        }
        $this->ajaxReturn(['status' => 1, 'result' => $list]);
    }
    public function news_list(){
        return View::fetch();
    }
    public function ajax_news_list(){
        $page = input('page/d', 1);
        $where['publish_time'] = ['<=',time()];
        $where['is_open'] = 1;
        $list= Db::view('news')
            ->view('newsCat','cat_name','newsCat.cat_id=news.cat_id','left')
            ->where($where)
            ->order('publish_time DESC')
            ->page($page, 10)
            ->select();
        foreach($list as $k=>$v){
            $list[$k]['content'] = '<p>'. cutstr_html(htmlspecialchars_decode($list[$k]['content']),60).'</p>';
        }
        $this->ajaxReturn(['status' => 1, 'result' => $list]);
    }

    /**
     * 商品列表页
     */
    public function goodsList(){
        $id = input('get.id/d',0); // 当前分类id
        $lists = getCatGrandson($id);
        View::assign('lists',$lists);
        return View::fetch();
    }
    
    public function ajaxGetMore(){
    	$p = input('p/d',1);
        $where = [
            'a.is_hot' => 1,
            'a.exchange_integral'=>0,  //积分商品不显示
            'a.is_on_sale' => 1
        ];

    	$favourite_goods = Db::name('goods')->alias('a')->where($where)->order('a.sort DESC')->page($p,config('PAGESIZE'))->cache(true,TPSHOP_CACHE_TIME)->join('goods_label b','a.label_id = b.label_id','left')->select();//首页推荐商品
        set_goods_label_name($favourite_goods);
    	View::assign('favourite_goods',$favourite_goods);
    	return View::fetch();
    }
    
    //微信Jssdk 操作类 用分享朋友圈 JS
    public function ajaxGetWxConfig()
    {
        $askUrl = input('askUrl');//分享URL
        $askUrl = urldecode($askUrl);

        $wechat = new WechatUtil;
        $signPackage = $wechat->getSignPackage($askUrl);
        if (!$signPackage) {
            exit($wechat->getError());
        }

        $this->ajaxReturn($signPackage);
    }
    /**
     * APP下载地址, 如果APP不存在则显示WAP端地址
     * @return \think\mixed
     */
    public function app_down(){

        $server_host = 'http://'.$_SERVER['HTTP_HOST'];
        $showTip = false;
        if(tpCache('ios.app_path') && strpos($_SERVER['HTTP_USER_AGENT'], 'iPhone')||strpos($_SERVER['HTTP_USER_AGENT'], 'iPad')){
            //苹果:直接指向AppStore下载
            $down_url = tpCache('ios.app_path');
        }else if(tpCache('android.app_path') && strpos($_SERVER['HTTP_USER_AGENT'], 'Android')){
            // 安卓:需要拼接下载地址
            $down_url = $server_host.'/'.tpCache('android.app_path');
            //如果是安卓手机微信打开, 则显示"其他浏览器打开"提示
            (strstr($_SERVER['HTTP_USER_AGENT'],'MicroMessenger') && strpos($_SERVER['HTTP_USER_AGENT'], 'Android')) && $showTip = true;
        }

        $wap_url = $server_host.'/Mobile';
        /*  echo "down_url : ".$down_url;
         echo "wap_url : ".wap_url;
         echo "<br/>showTip : ".$showTip; */
        View::assign('showTip' , $showTip);
        View::assign('down_url' , $down_url);
        View::assign('wap_url' , $wap_url);
        return View::fetch();
    }
}