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
 * $Author: 当燃   2016-05-10
 */ 
namespace app\mobile\controller;
use think\facade\View;
use app\common\logic\GoodsLogic;
use app\common\model\FlashSale;
use app\common\model\GroupBuy;
use app\common\model\PreSell;
use think\facade\Db;
use think\Page;
use app\common\logic\ActivityLogic;

class Activity extends MobileBase {
    public function index(){      
        return View::fetch();
    }

    /**
     * 团购活动列表
     */
    public function group_list()
    {
        $type =input('get.type');
        //以最新新品排序
        if ($type == 'new') {
            $order = 'gb.start_time desc';
        } elseif ($type == 'comment') {
            $order = 'g.comment_count desc';
        } else {
            $order = '';
        }
        $group_by_where = array(
            'gb.start_time'=>array('<',time()),
            'gb.end_time'=>array('>',time()),
            'g.is_on_sale'=>1,
            'gb.is_end'            =>0,
        );
        $GroupBuy = new GroupBuy();
    	$count =  $GroupBuy->alias('gb')->join('goods g', 'g.goods_id = gb.goods_id')->where($group_by_where)->count();// 查询满足要求的总记录数
        $pagesize = config('PAGESIZE');  //每页显示数
    	$page = new Page($count,$pagesize); // 实例化分页类 传入总记录数和每页显示的记录数
    	$show = $page->show();  // 分页显示输出
    	View::assign('page',$show);    // 赋值分页输出
        $list = $GroupBuy
            ->alias('gb')
            ->join('goods g', 'gb.goods_id=g.goods_id AND g.prom_type=2')
            ->where($group_by_where)
            ->limit($page->firstRow, $page->listRows)
            ->order($order)
            ->select();
        View::assign('list', $list);
        if(input('is_ajax')) {
            return View::fetch('ajax_group_list');      //输出分页
        }
        return View::fetch();
    }

    /**
     * 活动商品列表
     */
    public function discount_list(){
        $prom_id = input('id/d');    //活动ID
        $where = array(     //条件
            'is_on_sale'=>1,
            'prom_type'=>3,
            'prom_id'=>$prom_id,
        );
        $count =  Db::name('goods')->where($where)->count(); // 查询满足要求的总记录数
         $pagesize = config('PAGESIZE');  //每页显示数
        $Page = new Page($count,$pagesize); //分页类
        $prom_list = Db::name('goods')->where($where)->limit($Page->firstRow,$Page->listRows)->select()->toArray(); //活动对应的商品
        $spec_goods_price = Db::name('specGoodsPrice')->where(['prom_type'=>3,'prom_id'=>$prom_id])->select(); //规格
        foreach($prom_list as $gk =>$goods){  //将商品，规格组合
            foreach($spec_goods_price as $spk =>$sgp){
                if($goods['goods_id']==$sgp['goods_id']){
                    $prom_list[$gk]['spec_goods_price']=$sgp;
                }
            }
        }
        foreach($prom_list as $gk =>$goods){  //计算优惠价格
            $PromGoodsLogicuse = new \app\common\logic\PromGoodsLogic($goods,$goods['spec_goods_price']);
            if(!empty($goods['spec_goods_price'])){
                $prom_list[$gk]['prom_price']=$PromGoodsLogicuse->getPromotionPrice($goods['spec_goods_price']['price']);
            }else{
                $prom_list[$gk]['prom_price']=$PromGoodsLogicuse->getPromotionPrice($goods['shop_price']);
            }

        }
        View::assign('prom_list', $prom_list);
        if(input('is_ajax')){
            return View::fetch('ajax_discount_list');
        }
        return View::fetch();
    }

    /**
     * 商品活动页面
     * @author lxl
     * @time2017-1
     */
    public function promote_goods(){
        $type=input('type/d',0);

        $now_time = time();
        $where = " start_time <= $now_time and end_time >= $now_time and is_end = 0 and type = $type";
        $count = Db::name('prom_goods')->where($where)->count();  // 查询满足要求的总记录数
        $pagesize = config('PAGESIZE');  //每页显示数
        $Page  = new Page($count,$pagesize); //分页类
        $promote = Db::name('prom_goods')->field('id,title,start_time,end_time,prom_img')->where($where)->limit($Page->firstRow,$Page->listRows)->select();    //查询活动列表
        View::assign('promote',$promote);
        if(input('is_ajax')){
            return View::fetch('ajax_promote_goods');
        }
        return View::fetch();
    }


    /**
     * 抢购活动列表页
     */
    public function flash_sale_list()
    {
        $time_space = flash_sale_time_space();
        View::assign('time_space', $time_space);
        return View::fetch();
    }

    /**
     * 抢购活动列表ajax
     */
    public function ajax_flash_sale()
    {
        $p = input('p',1);
        $start_time = input('start_time');
        $end_time = input('end_time');
        $where = array(
            'fl.start_time'=>array('>=',$start_time),
            'fl.end_time'=>array('<=',$end_time),
            'g.is_on_sale'=>1,
            'fl.is_end'=>0
        );
        $FlashSale = new FlashSale();
        $flash_sale_goods = $FlashSale->alias('fl')->join('goods g', 'g.goods_id = fl.goods_id')->with(['specGoodsPrice','goods'])
            ->field('fl.*,100*(FORMAT(buy_num/goods_num,2)) as percent')
            ->where($where)
            ->page($p,10)
            ->select();
        View::assign('flash_sale_goods',$flash_sale_goods);
        return View::fetch();
    }

    public function coupon_list()
    {
        $atype = input('atype', 1);
        $user = session('user');
        $p = input('p', '1');

        $activityLogic = new ActivityLogic();
        $result = $activityLogic->getCouponList($atype, $user['user_id'], $p);
        View::assign('coupon_list', $result);
        if (request()->isAjax()) {
            return View::fetch('ajax_coupon_list');
        }
        return View::fetch();
    }

    /**
     * 领券
     */
    public function getCoupon()
    {
        $id = input('coupon_id/d');
        $user = session('user');
        $user['user_id'] = $user['user_id'] ?: 0;
        $activityLogic = new ActivityLogic();
        $return = $activityLogic->get_coupon($id, $user['user_id']);
        $this->ajaxReturn($return);
    }

    public function pre_sell_list()
    {
        $p = input('p', 1);
        $PreSell = new PreSell();
        //$pre_sell_list = $PreSell->where(['sell_end_time'=>['>',time()],'is_finished' => 0])->order(['pre_sell_id' => 'desc'])->page($p, 10)->select();
        $type = input('type', 0);

        if($type == 1){
            $order['is_new'] = 'desc';
        }elseif($type == 2){
            $order['comment_count'] = 'desc';
        }else{
            $order = ['pre_sell_id' => 'desc'];
        }
        $pre_sell_list = Db::view('PreSell','pre_sell_id,goods_id,item_id,goods_name,deposit_goods_num,sell_end_time')
            ->view('Goods','is_new,sort,comment_count,collect_sum','Goods.goods_id=PreSell.goods_id')
            ->where(['sell_end_time'=>['>',time()],'is_finished' => 0])
            ->page($p, 10)
            ->order($order)
            ->select();
        foreach($pre_sell_list as $k => $v){
            $pre_sell = $PreSell::find($v['pre_sell_id']);
            $pre_sell_list[$k]['ing_price'] = $pre_sell->ing_price;
        }
        View::assign('pre_sell_list', $pre_sell_list);
        if (request()->isAjax()) {
            return View::fetch('ajax_pre_sell_list');
        }
        return View::fetch();
    }

}