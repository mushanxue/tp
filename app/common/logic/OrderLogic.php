<?php
/**
 * tpshop
 * ============================================================================
 * 版权所有 2015-2027 深圳搜豹网络科技有限公司，并保留所有权利。
 * 网站地址: http://www.tp-shop.cn
 * ----------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在不用于商业目的的前提下对程序代码进行修改和使用 .
 * 不允许对程序代码以任何形式任何目的的再发布。
 * 采用最新Thinkphp6
 * ============================================================================
 * Author: 当燃
 * Date: 2016-03-19
 */

namespace app\common\logic;

use app\common\logic\wechat\WechatUtil;
use app\common\model\Order as OrderModel;
use app\common\model\SpecGoodsPrice;
use app\common\logic\MessageTemplateLogic;
use app\common\logic\MessageFactory;
use app\admin\logic\RefundLogic;
use think\facade\Cache;
use think\facade\Db;
use PHPExcel_Cell;
use PHPExcel_IOFactory;
/**
 * Class orderLogic
 * @package Common\Logic
 */
class OrderLogic
{
    protected $user_id=0;
    public function setUserId($user_id){
        $this->user_id=$user_id;
    }
	/**
	 * 取消订单
	 * @param $user_id|用户ID
	 * @param $order_id|订单ID
	 * @param string $action_note 操作备注
	 * @return array
	 */
	public function cancel_order($user_id,$order_id,$action_note='您取消了订单'){
		$order = DB::name('order')->where(array('order_id'=>$order_id,'user_id'=>$user_id))->find();
		//检查是否未支付订单 已支付联系客服处理退款
		if(empty($order))
			return array('status'=>-1,'msg'=>'订单不存在','result'=>'');
		if($order['order_status'] == 3){
			return array('status'=>-1,'msg'=>'该订单已取消','result'=>'');
		}
		//检查是否未支付的订单
 		//if($order['pay_status'] > 0 || $order['order_status'] > 0)
		// 	return array('status'=>-1,'msg'=>'支付状态或订单状态不允许','result'=>'');
		//获取记录表信息
		//$log = DB::name('account_log')->where(array('order_id'=>$order_id))->find();
		if($order['prom_type'] == 6){
			$team_follow = Db::name('team_follow')->where(['order_id'=>$order_id])->find();
			if($team_follow){
				$team_found_queue = Cache::get('team_found_queue');
				$team_found_queue[$team_follow['found_id']] = $team_found_queue[$team_follow['found_id']] + 1;
				Cache::set('team_found_queue', $team_found_queue);
			}
		}
		//有余额支付的情况
		if($order['user_money'] > 0 || $order['integral'] > 0){
			accountLog($user_id,$order['user_money'],$order['integral'],"订单取消，退回{$order['user_money']}元,{$order['integral']}积分",0,$order['order_id'],$order['order_sn']);
		}

		if($order['coupon_price'] >0){
			$res = array('use_time'=>0,'status'=>0,'order_id'=>0);
			//判断是不是被拆分的子订单，且兄弟订单都已取消订单或退货，在改变了此订单状态后执行
			if (empty($order['parent_sn'])) {
				DB::name('coupon_list')->where(array('order_id'=>$order_id,'uid'=>$user_id))->save($res);
			} else {
				$refundLogic = new RefundLogic();
				$parentOrderId = $refundLogic->is_split_all($order);
				if ($parentOrderId) {
					DB::name('coupon_list')->where(array('order_id'=>$parentOrderId,'uid'=>$user_id))->save($res);
				}
			}
		}

		$row = DB::name('order')->where(array('order_id'=>$order_id,'user_id'=>$user_id))->save(array('order_status'=>3));
        //上述约束了已付款订单不可取消，此处返回库存等操作应直接执行
		$reduce = tpCache('shopping.reduce');
		//if($reduce == 1 || empty($reduce)){

		//}
		// 支付成功时，减库存，但是没有支付，直接取消订单 不需要减库存。
		if(!($reduce == 2 && empty($order['pay_status']))){
			$this->alterReturnGoodsInventory($order);
		}
        $this->orderActionLog($order_id,$action_note,'用户取消订单');
		// 设置分销的订单也取消
		$distribut_switch = tpCache('distribut.switch');
		if ($distribut_switch == 1) {
			Db::name('rebate_log')->where('order_id',$order_id)->update(['status'=>4]);
		}
		if(!$row)
			return array('status'=>-1,'msg'=>'操作失败','result'=>'');
		return array('status'=>1,'msg'=>'操作成功','result'=>'');

	}

	public function addReturnGoods($rec_id,$order)
	{
		$data = input('post.');
		$confirm_time_config = tpCache('shopping.auto_service_date');//后台设置多少天内可申请售后
		$confirm_time = $confirm_time_config * 24 * 60 * 60;
		if ((time() - $order['confirm_time']) > $confirm_time && !empty($order['confirm_time'])) {
			return ['status'=>-1,'msg'=>'已经超过' . ($confirm_time_config ?: 0) . "天内退货时间"];
		}
        
        $img = $this->uploadReturnGoodsImg();
        if ($img['status'] !== 1) {
            return $img;
        }
        $data['imgs'] = $img['result'] ?: ($data['imgs'] ?: ''); //兼容小程序，多传imgs
	
		$data['addtime'] = time();
		$data['user_id'] = $order['user_id'];
		$order_goods = DB::name('order_goods')->where(array('rec_id'=>$rec_id))->find();
		if($data['type'] < 2){
            $useRapplyReturnMoney = $order_goods['final_price']*$data['goods_num']-$order['discount'];    //要退的总价 商品购买单价*申请数量
            $userExpenditureMoney = $order['goods_price']-$order['order_prom_amount']-$order['coupon_price']-$order['discount'];    //用户实际使用金额
            $rate = round($useRapplyReturnMoney/$userExpenditureMoney,8);
            $data['refund_integral'] = floor($rate*$order['integral']);//该退积分支付
			//积分规则修改后的逻辑
			$point_rate = tpCache('integral.point_rate');
            $integralDeductionMoney = 0;
            if( $data['refund_integral'] > 0){
                $integralDeductionMoney = $data['refund_integral']/$point_rate ;  //积分抵了多少钱，要扣掉
            }
            if($order['order_amount']>0){
                $order_amount = $order['order_amount']+$order['paid_money'];   //三方支付总额，预售要退定金
                if($order_amount>$order['shipping_price']){
                    $data['refund_money'] = round($rate*($order_amount - $order['shipping_price']),2); //退款金额
                    $data['refund_deposit'] = $rate*$order['user_money'];
                }else{
                    $data['refund_deposit'] = round($rate*($order['user_money'] - $order['shipping_price']+$order['paid_money'])-$integralDeductionMoney,2);//该退余额支付部分
                }
            }else{
                $data['refund_deposit'] = round($useRapplyReturnMoney-$integralDeductionMoney,2);//该退余额支付部分
            }
		}
		$data['suppliers_id'] = $order['suppliers_id'];

		if(!empty($data['id'])){
			$result = DB::name('return_goods')->where(array('id'=>$data['id']))->save($data);
		}else{
			$result = DB::name('return_goods')->insertGetId($data);
		}
	
		if($result){
			return ['status'=>1,'msg'=>'申请成功'];
		}
		return ['status'=>-1,'msg'=>'申请失败'];
	}
    
    /**
     * 上传退换货图片，兼容小程序
     * @return array
     */
    public function uploadReturnGoodsImg()
    {        
        $return_imgs = [];            
        // 只要上传了一张图片就开始处理 in_array(0,$_FILES['return_imgs']['error']
        if($_FILES['return_imgs']['error'] != 4  || in_array(0,$_FILES['return_imgs']['error'])){
		$files = request()->file("return_imgs");            
            if (is_object($files)) {
                $files = [$files]; //可能是一张图片，小程序情况
            }
			$image_upload_limit_size = config('image_upload_limit_size');
			$validate = ['size'=>$image_upload_limit_size,'ext'=>'jpg,png,gif,jpeg'];
			// $dir = UPLOAD_PATH.'return_goods/';
                         $save_url = 'return_goods/'.date('Ymd');
                        // echo UPLOAD_PATH.$save_url;
			//if (!($_exists = file_exists(UPLOAD_PATH.$save_url))){
				//$isMk = mkdir(UPLOAD_PATH.$save_url);
			//}	                        
                        if($files)
                        {
                            foreach ($files as $file) 
                            {                    
                                        $originalName = strtolower($file->getOriginalName());
                                    if(strstr($originalName,'.php') || strstr($originalName,'.js')) 
                                            return ['status' => 0,'msg' =>'上传图片格式有错误', 'result' =>''];
                                     // 上传图片
                                    try {                        
                                            \think\facade\Filesystem::disk('public')->putFileAs($save_url, $file,$originalName);  
                                            $return_imgs[] = '/' .UPLOAD_PATH.$save_url . '/' . $originalName;
                                    } catch (think\exception\ValidateException $e) {
                                            $upload_error = $e->getMessage();
                                            return ['status' =>-1,'msg' =>$upload_error];                       
                                    }
                            }                        
                        }
			if (!empty($return_imgs)) {
				$return_imgs = implode(',', $return_imgs);// 上传的图片文件
			}
                        return ['status' => 1, 'msg' => '操作成功', 'result' => $return_imgs];
		}
                return ['status' => 1, 'msg' => '上传错误', 'result' => $return_imgs];        
    }
	
    /**
     * 获取可申请退换货订单商品
     * @param $sale_t
     * @param $keywords
     * @param $user_id
     * @return array
     */
    public function getReturnGoodsIndex($sale_t, $keywords, $user_id)
    {
        if($keywords){
            $condition['order_sn'] = $keywords;
        }
		$confirm_time_config = tpCache('shopping.auto_service_date');//后台设置多少天内可申请售后
		$confirm_time = strtotime("-$confirm_time_config day");
		$condition['add_time'] = ['>',$confirm_time];
    	$condition['user_id'] = $user_id;
    	$condition['pay_status'] = 1;
    	$condition['shipping_status'] = 1;
    	$condition['deleted'] = 0;
    	$count = DB::name('order')->where($condition)->count();
    	$Page  = new \think\Page($count,10);
    	$show = $Page->show();
    	$order_list = DB::name('order')->where($condition)->order('order_id desc')->limit($Page->firstRow,$Page->listRows)->select()->toArray();
    	
    	foreach ($order_list as $k=>$v) {
            $order_list[$k] = set_btn_order_status($v);  // 添加属性  包括按钮显示属性 和 订单状态显示属性
            $data = DB::name('order_goods')->where(['order_id'=>$v['order_id'],['is_send','<',2]])->select();
            if(!$data->isEmpty()){
                $order_list[$k]['goods_list'] = $data;
            }else{
                unset($order_list[$k]);  //除去没有可申请的订单
            }

    	}

        return [
            'order_list' => $order_list,
            'page' => $show
        ];
    }

    /**
     * 获取退货列表
     * @param $keywords
     * @param $addtime
     * @param $status
     * @param int $user_id
     * @return array
     */
    public function getReturnGoodsList($keywords, $addtime, $status, $user_id = 0)
	{
		if($keywords){
            $where['order_sn|goods_name'] = array('like',"%$keywords%");
    	}
    	if($status === '0' || !empty($status)){
            $where['status'] = $status;
    	}
    	if($addtime == 1){
            $where['addtime'] = array('>',(time()-90*24*3600));
    	}
    	if($addtime == 2){
            $where['addtime'] = array('<',(time()-90*24*3600));
    	}
    	$query = DB::name('return_goods')->alias('r')->field('r.*,g.goods_name')
                ->join('order o', 'r.order_id = o.order_id AND o.deleted = 0 AND o.user_id='.$user_id)
                ->join('goods g', 'r.goods_id = g.goods_id', 'LEFT')
                ->where($where);
        $query2 = clone $query;
        $count = $query->count();
    	$page = new \think\Page($count,10);
    	$list = $query2->order("id desc")->limit($page->firstRow, $page->listRows)->select();
    	$goods_id_arr = get_arr_column($list, 'goods_id');
    	if(!empty($goods_id_arr)) {
            $goodsList = DB::name('goods')->where("goods_id in (".  implode(',',$goods_id_arr).")")->column('goods_name','goods_id');
        }
        
        return [
            'goodsList' => $goodsList,
            'return_list' => $list,
            'page' => $page->show()
        ];
	}
    
    /**
     * 记录取消订单
     * @param $user_id
     * @param $order_id
     * @param $user_note
     * @param $consignee
     * @param $mobile
     * @return array
     */
    public function recordRefundOrder($user_id, $order_id, $user_note, $consignee, $mobile)
	{
		$order = Db::name('order')->where(['order_id' => $order_id, 'user_id' => $user_id])->find();
		if (!$order) {
			return ['status' => -1, 'msg' => '订单不存在'];
		}
		$order_return_num = Db::name('return_goods')->where(['order_id' => $order_id, 'user_id' => $user_id, ['status' , '<>', 5]])->count();
		if ($order_return_num > 0) {
			return ['status' => -1, 'msg' => '该订单中有商品正在申请售后'];
		}
		$order_status = 3;//已取消
		$order_info = ['order_status' => $order_status];
		if ($mobile) {
			$order_info['mobile'] = $mobile;
		}
		if ($consignee) {
			$order_info['consignee'] = $consignee;
		}
		if ($user_note) {
			$order_info['cancel_reason'] = $user_note;
//			$order_info['user_note'] = $user_note;
//			$data['action_note'] = $user_note;
		}

		$result = Db::name('order')->where(['order_id' => $order_id])->update($order_info);
		if (!$result) {
			return ['status' => 0, 'msg' => '操作失败'];
		}
		if ($order['prom_type'] == 5) {
			//活动订单可能要操作其他东西,目前只是虚拟订单才需要，以后根据业务做修改
			Db::name('vr_order_code')->where(['order_id' => $order_id])->update(['refund_lock' => 1]);
		}
		Db::name('rebate_log')->where(['order_id' => $order_id])->save(array('status' => 4, 'confirm_time' => time()));
		//短信通知
		$scene = 9;
		$params['num'] = 1;
		$mobiles = tpCache("sms")['unconfirmed_cancel_order_sms_enable'];
		$mobileArr = explode("|", $mobiles);
		if (count($mobileArr) > 0) {
			foreach ($mobileArr as $v) {
				$resp = sendSms($scene, $v, $params, '');
			}

			$order = new \app\common\logic\Order();
			$order->setOrderById($order_id);
			$order->orderActionLog('', '用户取消已付款订单');
			return ['status' => 1, 'msg' => '提交成功'];
		}
	}

	/**
	 * 	生成兑换码
	 * 长度 =3位 + 4位 + 2位 + 3位  + 1位 + 5位随机  = 18位
	 * @param $order
	 * @return mixed
	 */
	function make_virtual_code($order){
		$order_goods = DB::name('order_goods')->where(array('order_id'=>$order['order_id']))->find();
		$goods = DB::name('goods')->where(array('goods_id'=>$order_goods['goods_id']))->find();
		DB::name('order')->where(array('order_id'=>$order['order_id']))->save(array('order_status'=>1,'shipping_time'=>time()));
		$perfix = mt_rand(100,999);
		$perfix .= sprintf('%04d', $order['user_id'] % 10000)
				. sprintf('%02d', (int) $order['user_id'] % 100).sprintf('%03d', (float) microtime() * 1000);


		for ($i = 0; $i < $order_goods['goods_num']; $i++) {
			$order_code[$i]['order_id'] = $order['order_id'];
			$order_code[$i]['user_id'] = $order['user_id'];
			$order_code[$i]['vr_code'] = $perfix. sprintf('%02d', (int) $i % 100) . rand(5,1);
			$order_code[$i]['pay_price'] = $goods['shop_price'];
			$order_code[$i]['vr_indate'] = $goods['virtual_indate'];
			$order_code[$i]['vr_invalid_refund'] = $goods['virtual_refund'];
		}
		$message_logic = new \app\common\logic\MessageLogisticsLogic([]);
		$message_logic->sendVirtualOrder($order, $goods);

		$res = checkEnableSendSms("7");

		//生成虚拟订单, 向用户发送短信提醒
		if($res && $res['status'] ==1){
			$sender = $order['mobile'];
			$goods_name = $goods['goods_name'];
			$goods_name = getSubstr($goods_name, 0, 10);
			$params = array('goods_name'=>$goods_name);
			sendSms("7", $sender, $params);
		}


		return DB::name('vr_order_code')->insertAll($order_code);
	}

	/**
	 * 自动取消订单
	 */
	public function abolishOrder(){
		$set_time=1; //自动取消时间/天 默认1天
		$abolishtime = strtotime("-$set_time day");
		//$abolishtime = time()-60*30; // 60*30分钟=半小时
		$order_where = [
				'user_id'      =>$this->user_id,
				['add_time'     ,'<',$abolishtime],
				'pay_status'   =>0,
				'order_status' => 0
		];
		$order = Db::name('order')->where($order_where)->column('order_id');
		foreach($order as $key =>$value){
			$result = $this->cancel_order($this->user_id,$value);
		}
		return $result;
	}

	/**
	 * 获取订单 order_sn
	 * @return string
	 */
	public function get_order_sn()
	{
	    $order_sn = null;
	    // 保证不会有重复订单号存在
	    while(true){
	        $order_sn = date('YmdHis').rand(1000,9999); // 订单编号	        
	        $order_sn_count = DB::name('order')->where("order_sn = ".$order_sn)->count();
	        if($order_sn_count == 0)
	            break;
	    }
	    
	    return $order_sn;
	}

    /**
     * 批量订单操作记录
     * @param $order_id
     * @param $action_note 备注
     * @param $status_desc 状态描述
     * @param $action_user
     * @return mixed
     */
    public function orderActionLog($order_id,$action_note,$status_desc,$action_user=0,$group=0){
        $order = Db::name('order')->where(['order_id'=>$order_id])->find();
        $data=[
            'order_id'      => $order_id,
            'action_user'   => $action_user,
            'action_note'   => $action_note,
            'order_status'  => $order['order_status'],
            'pay_status'    => $order['pay_status'],
            'log_time'      => time(),
            'status_desc'   => $status_desc,
            'shipping_status'   => $order['shipping_status'],
			'group'         => $group,
        ];
        return DB::name('order_action')->insert($data);//订单操作记录
    }

	/**
	 * 取消订单后改变库存，根据不同的规格，商品活动修改对应的库存
	 * @param $order
     * @param $rec_id|订单商品表id 如果有只返还订单某个商品的库存,没有返还整个订单
     */
    public function alterReturnGoodsInventory($order, $rec_id='')
	{
        if($order['prom_type'] == 6){
            if($order['teamActivity']['team_type']==2){  //抽奖团取消不用退库存
                return ;
            }
        }

        if($rec_id){
            $orderGoodsWhere['rec_id'] = $rec_id;
            $retunn_info = Db::name('return_goods')->where($orderGoodsWhere)->select(); //查找购买数量和购买规格
            $order_goods_prom = Db::name('order_goods')->where($orderGoodsWhere)->find(); //购买时参加的活动
            $order_goods = $retunn_info->toArray();
            $order_goods[0]['prom_type'] = $order_goods_prom['prom_type'];
            $order_goods[0]['prom_id'] = $order_goods_prom['prom_id'];
            $order_goods[0]['goods_name'] = $order_goods_prom['goods_name'];
            $order_goods[0]['spec_key_name'] = $order_goods_prom['spec_key_name'];
            $order_goods[0]['item_id'] = $order_goods_prom['item_id'];
        }else{
            $orderGoodsWhere = ['order_id'=>$order['order_id']];
            $order_goods = Db::name('order_goods')->where($orderGoodsWhere)->select(); //查找购买数量和购买规格
        }
        if($order['prom_type'] == 8){
            //取消订单恢复砍价活动的库存
            Db::name('promotion_bargain_goods_item')->where(['bargain_id'=>$order['prom_id'],'item_id'=>$order_goods[0]['item_id']])->dec('buy_num',$order_goods[0]['goods_num'])->update();
        }
		foreach($order_goods as $key=>$val){
			if(!empty($val['spec_key'])){ // 先到规格表里面扣除数量
				$SpecGoodsPrice = new SpecGoodsPrice();
				$specGoodsPrice = $SpecGoodsPrice::where(['goods_id' => $val['goods_id'], 'key' => $val['spec_key']])->find();
				if($specGoodsPrice){
					$specGoodsPrice->store_count = $specGoodsPrice->store_count + $val['goods_num'];
					$specGoodsPrice->save();//有规格则增加商品对应规格的库存
				}
			}
			update_stock_log($order['user_id'], $val['goods_num'], $val, $order['order_sn'], 2);//库存日志
			Db::name('goods')->where(['goods_id' => $val['goods_id']])->inc('store_count', $val['goods_num'])->update();//增加商品库存
			Db::name('Goods')->where("goods_id", $val['goods_id'])->dec('sales_sum', $val['goods_num'])->update(); // 减少商品销售量
			$sales_sum = Db::name('goods')->where('goods_id',$val['goods_id'])->value('sales_sum');
			if($sales_sum < 0 ){
				Db::name('goods')->where('goods_id',$val['goods_id'])->save(['sales_sum'=>0]);
			}
			//更新活动商品购买量
			if ($val['prom_type'] == 1 || $val['prom_type'] == 2) {
				$GoodsPromFactory = new GoodsPromFactory();
				$goodsPromLogic = $GoodsPromFactory->makeModule($val, $specGoodsPrice);
				$prom = $goodsPromLogic->getPromModel();
				// 即然要恢复库存，不用判断是否结束了活动,如果活动结束了，恢复后，要打开。
				$tb = $val['prom_type'] == 1 ? 'flash_sale' : 'group_buy';
				DB::name($tb)->where("id", $val['prom_id'])->dec('buy_num', $val['goods_num'])->update();
				DB::name($tb)->where("id", $val['prom_id'])->dec('order_num',1)->update();
				if ($prom['is_end'] == 1 && $prom['end_time'] > time()) {
					DB::name($tb)->where("id", $val['prom_id'])->update(['is_end'=>0]);
					DB::name('goods')->where('goods_id',$prom['goods_id'])->update(['prom_type'=>$val['prom_type'],'prom_id'=>$val['prom_id']]);
				}
			}
		}
	}

    /**
     * 修改订单兑换码
     * @param $order
     */
    public function alterOrderCode($order)
    {
        Db::name('vr_order_code')->where(['order_id'=>$order['order_id']])->save(['refund_lock'=>1]);
    }



/*****admin*****/
    /**
     * 根据商品型号获取商品
     * @param $goods_id_arr
     * @return array|bool
     */
    public function get_spec_goods($goods_id_arr){
        if(!is_array($goods_id_arr)) return false;
        foreach($goods_id_arr as $key => $val)
        {
            $arr = array();
            $goods = Db::name('goods')->where("goods_id = $key")->find();
            $arr['goods_id'] = $key; // 商品id
            $arr['goods_name'] = $goods['goods_name'];
            $arr['goods_sn'] = $goods['goods_sn'];
            $arr['market_price'] = $goods['market_price'];
            $arr['goods_price'] = $goods['shop_price'];
            $arr['cost_price'] = $goods['cost_price'];
            $arr['member_goods_price'] = $goods['shop_price'];
			$arr['store_count'] = $goods['store_count'];
			$arr['suppliers_id'] = $goods['suppliers_id'];
            foreach($val as $k => $v)
            {
                $arr['goods_num'] = $v['goods_num']; // 购买数量
                // 如果这商品有规格
                if($k != 'key')
                {
                    $arr['spec_key'] = $k;
                    $spec_goods = Db::name('spec_goods_price')->where("goods_id = $key and `key` = '{$k}'")->find();
					if($spec_goods){
						$arr['spec_key_name'] = $spec_goods['key_name'];
						$arr['member_goods_price'] = $arr['goods_price'] = $spec_goods['price'];
						$arr['sku'] = $spec_goods['sku']; // 参考 sku  http://www.zhihu.com/question/19841574
						$arr['store_count'] = $spec_goods['store_count'];
						if($spec_goods['cost_price']>0) $arr['cost_price'] = $spec_goods['cost_price'];
					}
                }
				if($arr['goods_num'] > $arr['store_count']) return false; // 购买数大于库存
                $order_goods[] = $arr;
            }
        }
        return $order_goods;
    }


    /*
     * 获取订单商品总价格
     */
    public function getGoodsAmount($order_id){
        $sql = "SELECT SUM(goods_num * goods_price) AS goods_amount FROM __PREFIX__order_goods WHERE order_id = {$order_id}";
        $res = DB::query($sql);
        return $res[0]['goods_amount'];
    }

    /**
     * 得到发货单流水号
     */
    public function get_delivery_sn()
    {
//        /* 选择一个随机的方案 */send_http_status('310');
        mt_srand((double) microtime() * 1000000);
        return date('YmdHi') . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    /*
     * 获取当前可操作的按钮
     */
    public function getOrderButton($order){
        /*
         *  操作按钮汇总 ：付款、设为未付款、确认、取消确认、无效、去发货、确认收货、申请退货
         *
         */
        $os = $order['order_status'];//订单状态
        $ss = $order['shipping_status'];//发货状态
        $ps = $order['pay_status'];//支付状态
        $pt = $order['prom_type'];//订单类型：0默认1抢购2团购3优惠4预售5虚拟6拼团
        $btn = array();
        if($order['pay_code'] == 'cod') {
            if($os == 0 && $ss == 0){
                if($pt != 6){
                    $btn['confirm'] = '确认';
                }
            }elseif($os == 1 && ($ss == 0 || $ss == 2)){
                $btn['delivery'] = '去发货';
                if($pt != 6){
                    $btn['cancel'] = '取消确认';
                }
            }elseif($ss == 1 && $os == 1 && $ps == 0){
                $btn['pay'] = '付款';
            }elseif($ps == 1 && $ss == 1 && $os == 1){
                if($pt != 6){
                    $btn['pay_cancel'] = '设为未付款';
                }
            }
        }else{
            if($ps == 0 && $os == 0 || $ps == 2){
                $btn['pay'] = '付款';
            }elseif($os == 0 && $ps == 1){
                if($pt != 6){
                    $btn['pay_cancel'] = '设为未付款';
                    $btn['confirm'] = '确认';
                }
            }elseif($os == 1 && $ps == 1 && ($ss == 0 || $ss == 2)){
                if($pt != 6){
                    $btn['cancel'] = '取消确认';
                }
                $btn['delivery'] = '去发货';
            }
        }

        if($ss == 1 && $os == 1 && $ps == 1){
//        	$btn['delivery_confirm'] = '确认收货';
            $btn['refund'] = '申请退货';
        }elseif($os == 2 || $os == 4){
            $btn['refund'] = '申请退货';
        }elseif($os == 3 || $os == 5){
            $btn['remove'] = '移除';
        }
        if($os != 5){
            $btn['invalid'] = '无效';
        }
        return $btn;
    }


    public function orderProcessHandle($order_id,$act,$ext=array()){
        $update = array();
        switch ($act){
            case 'pay': //付款
                $order_sn = Db::name('order')->where("order_id = $order_id")->value('order_sn');
                update_pay_status($order_sn,$ext); // 调用确认收货按钮
                return true;
            case 'pay_cancel': //取消付款
				$update['pay_status'] = 0;
                $this->order_pay_cancel($order_id);
                return true;
            case 'confirm': //确认订单
				$update['order_status'] = 1;
                break;
            case 'cancel': //取消确认
				$update['order_status'] = 0;
                break;
            case 'invalid': //作废订单
				$update['order_status'] = 5;
				$reduce = tpCache('shopping.reduce');
				$order = Db::name('order')->where("order_id", $order_id)->find();
				if(($reduce == 1 || empty($reduce)) || ($reduce == 2 && $order['pay_status'] == 1)){
					$this->alterReturnGoodsInventory($order);
				}
				// 拼团订单作废，排队名额恢复一位
				if($order['prom_type'] == 6){
					$teamFoundId = $order['prom_id'];
					$team_found_queue = Cache::get('team_found_queue');
					if($team_found_queue && isset($team_found_queue[$teamFoundId])){
						$team_found_queue[$teamFoundId] = $team_found_queue[$teamFoundId] + 1;
						Cache::set('team_found_queue', $team_found_queue);
					}
				}
                break;
            case 'remove': //移除订单
                $order = new  \app\common\logic\Order();
				$order->setOrderById($order_id);
				$order->AdminDelOrder();
                break;
            case 'delivery_confirm'://确认收货
                confirm_order($order_id); // 调用确认收货按钮
                return true;
            default:
                return true;
        }
        return Db::name('order')->where("order_id=$order_id")->save($update);//改变订单状态
    }


    //管理员取消付款
    function order_pay_cancel($order_id)
    {
        //如果这笔订单已经取消付款过了
		$count = Db::name('order')->where(['order_id' => $order_id, 'pay_status' => 1])->count();   // 看看有没已经处理过这笔订单  支付宝返回不重复处理操作
        if($count == 0) return false;
        // 找出对应的订单
		$Order = new \app\common\logic\Order();
		$Order->setOrderById($order_id);
		$order = $Order->getOrder();
        // 增加对应商品的库存
        $orderGoodsArr = Db::name('OrderGoods')->where("order_id = $order_id")->select();
        foreach($orderGoodsArr as $key => $val)
        {
            if(!empty($val['spec_key']))// 有选择规格的商品
            {   // 先到规格表里面增加数量 再重新刷新一个 这件商品的总数量
                $SpecGoodsPrice = new \app\common\model\SpecGoodsPrice();
                $specGoodsPrice = $SpecGoodsPrice::where(['goods_id' => $val['goods_id'], 'key' => $val['spec_key']])->find();
                $specGoodsPrice->where(['goods_id' => $val['goods_id'], 'key' => $val['spec_key']])->dec('store_count', $val['goods_num'])->update();
                refresh_stock($val['goods_id']);
            }else{
                $specGoodsPrice = null;
                Db::name('Goods')->where("goods_id = {$val['goods_id']}")->inc('store_count',$val['goods_num'])->update(); // 增加商品总数量
            }
            Db::name('Goods')->where("goods_id = {$val['goods_id']}")->dec('sales_sum',$val['goods_num'])->update(); // 减少商品销售量
			$sales_sum = Db::name('goods')->where('goods_id',$val['goods_id'])->value('sales_sum');
			if($sales_sum < 0 ){
				Db::name('goods')->where('goods_id',$val['goods_id'])->save(['sales_sum'=>0]);
			}
            //更新活动商品购买量
            if ($val['prom_type'] == 1 || $val['prom_type'] == 2) {
                $GoodsPromFactory = new \app\common\logic\GoodsPromFactory();
                $goodsPromLogic = $GoodsPromFactory->makeModule($val, $specGoodsPrice);
                $prom = $goodsPromLogic->getPromModel();
                if ($prom['is_end'] == 0) {
                    $tb = $val['prom_type'] == 1 ? 'flash_sale' : 'group_buy';
                    Db::name($tb)->where("id", $val['prom_id'])->inc('buy_num', $val['goods_num'])->update();
                    Db::name($tb)->where("id", $val['prom_id'])->inc('order_num')->update();
                }
            }
        }
        // 根据order表查看消费记录 给他会员等级升级 修改他的折扣 和 总金额
        Db::name('order')->where("order_id=$order_id")->save(array('pay_status'=>0));
//        update_user_level($order['user_id']);
        $User =new \app\common\logic\User();
		$User->setUserById($order['user_id']);
        $User->updateUserLevel();
		$Order->orderActionLog('订单取消付款','用户取消已付款订单');
        //分销设置
        Db::name('rebate_log')->where("order_id = {$order['order_id']}")->save(array('status'=>0));
    }

    /**
     *	处理发货单
     * @param array $data  查询数量
     * @return array
     * @throws \think\Exception
     */
    public function deliveryHandle($data){
        $orderModel = new \app\common\model\Order();
        $orderObj = $orderModel::where(['order_id'=>$data['order_id']])->find();
        $order = $orderObj->append(['full_address','orderGoods'])->toArray();
        $orderGoods = $order['orderGoods'];
        $selectgoods = $data['goods'];
        $order['shipping_code'] && $data['shipping_code'] = $order['shipping_code'];
        $data['order_sn'] = $order['order_sn'];
        $data['delivery_sn'] = $data['invoice_no']?trim($data['invoice_no']):'';
        $data['zipcode'] = $order['zipcode'];
        $data['user_id'] = $order['user_id'];
        !isset($data['admin_id']) && $data['admin_id'] = session('admin_id');
        $data['consignee'] = $order['consignee'];
        $data['mobile'] = $order['mobile'];
        $data['country'] = $order['country'];
        $data['province'] = $order['province'];
        $data['city'] = $order['city'];
        $data['district'] = $order['district'];
        $data['address'] = $order['address'];
        $data['shipping_price'] = $order['shipping_price'];
        $data['create_time'] = time();
		if($data['shipping'] == 1){  //修改物流
			$r =$this->updateOrderShipping($data,$order);
		}else{
			if(empty($selectgoods)){
				return array('status'=>0,'msg'=>'未选商品发货');
			}
			$goods_count = count($orderGoods);
			// 部分发货的判断
			if($data['shipping'] == 2){
				$delivery_doc_flag = false;
				foreach ($orderGoods as $k=>$v){
					// 选中的是未发货的商品
					if($v['is_send'] == 0 && in_array($v['rec_id'],$selectgoods)){
						$delivery_doc_flag = true;
					}else{
						unset($orderGoods[$k]);
					}
				}
				if(!$delivery_doc_flag){
					return array('status'=>0,'msg'=>'未选中商品发货');
				}
			}

			if($data['send_type'] == 0 || $data['send_type'] == 3){
				$did = Db::name('delivery_doc')->insertGetId($data);
			}else{
				$result = $this->submitOrderExpress($data,$orderGoods);
				if($result['status'] == 1){
					$did = $result['did'];
				}else{
					return array('status'=>0,'msg'=>$result['msg']);
				}
			}

			$is_delivery = 0;
			foreach ($orderGoods as $k=>$v){
				if($v['is_send'] >= 1){
					$is_delivery++;
				}
				if($v['is_send'] == 0 && in_array($v['rec_id'],$selectgoods)){
					$res['is_send'] = 1;
					$res['delivery_id'] = $did;
					$r = Db::name('order_goods')->where("rec_id=".$v['rec_id'])->save($res);//改变订单商品发货状态
					$is_delivery++;
				}
			}
			// shipping_code or shipping_name 为null时报错
			$update['shipping_time'] = time();
			$update['shipping_code'] = $data['shipping_code'] ? $data['shipping_code'] : '';
			$update['shipping_name'] = $data['shipping_name'] ? $data['shipping_name'] : '';
			$is_send = Db::name('order_goods')->where('order_id = ' .$data['order_id'].' and is_send > 0')->count();
			if($is_send == $goods_count){
				$update['shipping_status'] = 1;
			}else{
				$update['shipping_status'] = 2;
			}
			$group = isset($data['group']) ? $data['group'] : 0;
			Db::name('order')->where("order_id=".$data['order_id'])->save($update);//改变订单状态
            $convert_action= config('CONVERT_ACTION')["delivery"];
			$this->orderActionLog($order['order_id'],$data['note'],$convert_action,$data['admin_id'],$group);//操作日志
		}

        if($r){

			//商家发货, 发送短信给客户
			$res = checkEnableSendSms("5");
			if ($res && $res['status'] ==1) {
				$user_id = $data['user_id'];
				$users = Db::name('users')->where('user_id', $user_id)->column('user_id , nickname , mobile' );
				if($users){
					$nickname = $users[$user_id]['nickname'];
					$sender = $users[$user_id]['mobile'];
					$params = array('user_name'=>$nickname , 'consignee'=>$data['consignee']);
					sendSms("5", $sender, $params,'');
				}
			}

			// 发送微信模板消息通知
			$wechat = new WechatLogic;
			$wechat->sendTemplateMsgOnDeliver($data);

        	$order_arr = Db::name('order_goods')->where("order_id", $order['order_id'])->find();
        	// 添加发消息通知
		    $goods_original_img = Db::name('goods')->where("goods_id", $order_arr['goods_id'])->value('original_img');
		    $send_data = [
		        'message_title' => '商品已发货',
		        'message_content' => $order_arr['goods_name'],
		        'img_uri' => $goods_original_img,
		        'order_sn' => $order['order_sn'],
		        'order_id' => $order['order_id'],
		        'mmt_code' => 'deliver_goods_logistics',
		        'type' => 2,
		        'users' => [$order['user_id']],
				'category' => 2,
		        'message_val' => []
		    ];
			$messageFactory = new MessageFactory();
			$messageLogic = $messageFactory->makeModule($send_data);
			$messageLogic->sendMessage();

            return array('status'=>1,'printhtml'=>isset($result['printhtml']) ? $result['printhtml'] : '');
        }else{
            return array('status'=>0,'msg'=>'发货失败');
        }
    }

    /**
     * 修改订单发货信息
     * @param array $data
     * @param array $order
     * @return bool|mixed
     */
    public function updateOrderShipping($data=[],$order=[]){
        $updata['shipping_code'] = $data['shipping_code'];
        $updata['shipping_name'] = $data['shipping_name'];
		$group = isset($data['group']) ? $data['group'] : 0;
		$admin_id = isset($data['admin_id']) ? $data['admin_id'] : session('admin_id');
        Db::name('order')->where(['order_id'=>$data['order_id']])->save($updata); //改变物流信息
        $updata['invoice_no'] = $data['invoice_no'];
        $delivery_res  = Db::name('delivery_doc')->alias('d')->join('order_goods o','o.delivery_id=d.id')->where(['o.rec_id'=>['in',$data['goods']]])->save($updata);
        if ($delivery_res){
            return $this->orderActionLog($order['order_id'],$data['note'],'订单修改发货信息',$admin_id, $group);//操作日志
        }else{
            return false;
        }

    }


	//订单发货在线下单、电子面单
	public function submitOrderExpress($data,$orderGoods){
		/*code_21快递鸟电子面单*/
		$eorder = [];
		$eorder["ShipperCode"] = $data['shipping_code'];//物流公司编码
		$eorder["OrderCode"] =  $data['order_sn'];//订单号
		$eorder["PayType"] = 1;
		$eorder["ExpType"] = 1;

		$shop_info = tpCache('shop_info');
		$region_ids = array($shop_info['province'],$shop_info['city'],$shop_info['district'],$data['province'],$data['city'],$data['district']);
		$region = Db::name('region')->where(array('id'=>array('in',$region_ids)))->column('name','id');

		$sender = [];
		$sender["Name"] = $shop_info['contact'];
		$sender["Mobile"] = $shop_info['mobile'];
		$sender["ProvinceName"] = $region[$shop_info['province']];
		$sender["CityName"] = $region[$shop_info['city']];
		$sender["ExpAreaName"] = $region[$shop_info['district']];
		$sender["Address"] = $shop_info['address'];
        $sender['PostCode'] = $data['zipcode'];   //发货人邮政编码

		$receiver = [];
		$receiver["Name"] = $data['consignee'];
		$receiver["Mobile"] = $data['mobile'];
		$receiver['PostCode'] = $data['zipcode'];
		$receiver["ProvinceName"] = $region[$data['province']];
		$receiver["CityName"] = $region[$data['city']];
		$receiver["ExpAreaName"] = $region[$data['district']];
		$receiver["Address"] = $data['address'];

		$commodityOne = $commodity = [];
		foreach ($orderGoods as $val){
			if($val['is_send'] == 0 && in_array($val['rec_id'],$data['goods']) || 2 == $data['shipping']){
				$commodityOne["GoodsName"] = $val['goods_name'];
				$commodityOne['Goodsquantity'] = $val['goods_num'];
				$commodity[] = $commodityOne;
			}
		}

		$eorder["Sender"] = $sender;//收件人信息
		$eorder["Receiver"] = $receiver;//发件人信息
		$eorder["Commodity"] = $commodity;//发货商品信息
		$eorder['Remark'] = $data['note'];
        $eorder['IsReturnPrintTemplate'] = 1;
		$eorder['TemplateSize'] = '180';
        $conf = tpCache('express');
        $eorder['CustomerName'] = $conf['kdniao_customer_name'];
        $eorder['CustomerPwd'] = $conf['kdniao_customer_pwd'];
        $eorder['MonthCode'] = $conf['kdniao_customer_pwd'];
		$jsonParam = json_encode($eorder, JSON_UNESCAPED_UNICODE);
		$jsonParam = str_replace("+","/",$jsonParam); // 电子面单时，商品带有+号，会提示非法参数
		require_once(PLUGIN_PATH . 'kdniao/kdniao.php');
		//1001预约取件接，1007电子面单
		$request_type = ($data['send_type']>1) ? 1007 : 1001;
		$kdniao = new \kdniao($request_type);
		$jsonResult = $kdniao->submitEOrder($jsonParam);
		$res = json_decode($jsonResult,true);
        //ajaxReturn($res);
		if(!$res['Success']){
			return array('status'=>0,'msg'=>$res['Reason']);
		}else{
		    if(2 == $data['shipping']){
                $printhtml = empty($res['PrintTemplate']) ? '' : $res['PrintTemplate'];
                return array('status'=>1,'printhtml'=>$printhtml);
            }
			$data['invoice_no'] = $res['Order']['LogisticCode'];
			$did = Db::name('delivery_doc')->insertGetId($data);
			$printhtml = empty($res['PrintTemplate']) ? '' : $res['PrintTemplate'];
			return array('status'=>1,'did'=>$did,'printhtml'=>$printhtml);
		}
		/*code_21快递鸟电子面单*/
	}


    //取消电子面单
    public function cancelEOrder($rec){
        $info  = Db::name('delivery_doc')->alias('d')->join('order_goods o','o.delivery_id=d.id')
            ->where(['o.rec_id'=>['in',$rec['rec_id']]])->field('d.shipping_code,d.invoice_no,d.order_sn,d.order_id')->select();
        if(!$info){
            return array('status'=>0,'msg'=>'未获取到单号信息');
        }
        $info = array_unique($info);
        require_once(PLUGIN_PATH . 'kdniao/kdniao.php');
        $request_type = 1147;
        $kdniao = new \kdniao($request_type);
        foreach ($info as $v){

            $data['ShipperCode'] = $v['shipping_code'];
            $data['OrderCode'] = $v['order_sn'];
            $data['ExpNo'] = $v['invoice_no'];
            $data['CustomerName']= 'admin';  //TODO 快递运营商账号密码
            $data['CustomerPwd'] = 'admin';
            $jsonParam = json_encode($data, JSON_UNESCAPED_UNICODE);
            $jsonParam = str_replace("+","/",$jsonParam);
            $jsonResult = $kdniao->submitEOrder($jsonParam);
            $res = json_decode($jsonResult,true);
            if(!$res['Success']){
                return array('status'=>0,'msg'=>'取消失败，请联系对应快递运营商','result'=>$res);
            }
        }
        //更改订单状态
        Db::name('order')->where('order_id',$info[0]['order_id'])->update(['shipping_status'=>0]);
        Db::name('order_goods')->where('order_id',$info[0]['order_id'])->update(['is_send'=>0]);
        Db::name('delivery_doc')->where('order_id',$info[0]['order_id'])->update(['is_del'=>0]);
        return array('status'=>1,'msg'=>'取消成功');
    }


    /**
     * 获取地区名字
     * @param int $p
     * @param int $c
     * @param int $d
     * @return string
     */
    public function getAddressName($p=0,$c=0,$d=0){
        $p = Db::name('region')->where(array('id'=>$p))->field('name')->find();
        $c = Db::name('region')->where(array('id'=>$c))->field('name')->find();
        $d = Db::name('region')->where(array('id'=>$d))->field('name')->find();
        return $p['name'].','.$c['name'].','.$d['name'].',';
    }
    
    /**
     * 当订单里商品都退货完成，将订单状态改成关闭
     * @param $order_id
     * @return bool
     * @throws \think\Exception
     */
    function closeOrderByReturn($order_id)
    {
        $order_goods_list = Db::name('order_goods')->where(['order_id' => $order_id])->select();
        $order_goods_count = count($order_goods_list);
        $order_goods_return_count = 0;//退货个数
        for ($i = 0; $i < $order_goods_count; $i++) {
            if ($order_goods_list[$i]['is_send'] == 3) {
                $order_goods_return_count++;
            }
        }
        if ($order_goods_count == $order_goods_return_count) {
            $res = Db::name('order')->where(['order_id' => $order_id])->update(['order_status' => 5]);
            if(!$res){
                return false;
            }
        }
        return true;
    }

    /**
     * 退货，取消订单，处理优惠券
     * @param $return_info
     */
    public function disposereRurnOrderCoupon($return_info){
        $coupon_list = Db::name('coupon_list')->where(['uid'=>$return_info['user_id'],'order_id'=>$return_info['order_id']])->find();    //有没有关于这个商品的优惠券
        if(!empty($coupon_list)){
            $update_coupon_data = ['status'=>0,'use_time'=>0,'order_id'=>0];
            Db::name('coupon_list')->where(['id'=>$coupon_list['id'],'status'=>1])->save($update_coupon_data);//符合条件的，优惠券就退给他
        }
        //追回赠送优惠券,一般退款才会走这里
        $coupon_info = Db::name('coupon_list')->where(['uid'=>$return_info['user_id'],'get_order_id'=>$return_info['order_id']])->find();
        if(!empty($coupon_info)){
            if($coupon_info['status'] == 1) { //如果优惠券被使用,那么从退款里扣
                $coupon = Db::name('coupon')->where(array('id' => $coupon_info['cid']))->find();
                if ($return_info['refund_money'] > $coupon['money']) {
                    //退款金额大于优惠券金额，先从这里扣
                    $return_info['refund_money'] = $return_info['refund_money'] - $coupon['money'];
                    Db::name('return_goods')->where(['id' => $return_info['id']])->save(['refund_money' => $return_info['refund_money']]);
                }else{
                    $return_info['refund_deposit'] = $return_info['refund_deposit'] - $coupon['money'];
                    Db::name('return_goods')->where(['id' => $return_info['id']])->save(['refund_deposit' => $return_info['refund_deposit']]);
                }
            }else {
                Db::name('coupon_list')->where(array('id' => $coupon_info['id']))->delete();
                Db::name('coupon')->where(array('id' => $coupon_info['cid']))->dec('send_num')->update();
            }
        }
    }


    public function getRefundGoodsMoney($return_goods){
        $order_goods = Db::name('order_goods')->where(array('rec_id'=>$return_goods['rec_id']))->find();
        if($return_goods['is_receive'] == 1){
            if($order_goods['give_integral']>0){
                $user = get_user_info($return_goods['user_id']);
                if($order_goods['give_integral']>$user['pay_points']){
                    //积分被使用则从退款金额里扣
                    $return_goods['refund_money'] = $return_goods['refund_money'] - $order_goods['give_integral']/100;
                }
            }
            $coupon_info = Db::name('coupon_list')->where(array('uid'=>$return_goods['user_id'],'get_order_id'=>$return_goods['order_id']))->find();
            if(!empty($coupon_info)){
                if($coupon_info['status'] == 1) { //如果优惠券被使用,那么从退款里扣
                    $coupon = Db::name('coupon')->where(array('id' => $coupon_info['cid']))->find();
                    if ($return_goods['refund_money'] > $coupon['money']) {
                        $return_goods['refund_money'] = $return_goods['refund_money'] - $coupon['money'];//退款金额大于优惠券金额
                    }
                }
            }
        }
        return $return_goods['refund_money'];
    }



    //识别单号
    public function distinguishExpress(){
        require_once(PLUGIN_PATH . 'kdniao/kdniao.php');
        $kdniao = new \kdniao();
        $data['LogisticCode'] = input('invoice_no');
        $kdniao->getOrderTracesByJson(json_encode($data));
    }

    public function createOrderBarCode(){
		ob_end_clean();
        require_once('../vendor/barcode/class/BCGFontFile.php');
        require_once('../vendor/barcode/class/BCGColor.php');
        require_once('../vendor/barcode/class/BCGDrawing.php');
        require_once('../vendor/barcode/class/BCGcode39.barcode.php');
        $font = new \BCGFontFile('../vendor/barcode/font/Arial.ttf', 14);
        $text =  $_GET['code'] ;
        $color_black = new \BCGColor(0, 0, 0);
        $color_white = new \BCGColor(255, 255, 255);
        $drawException = null;
        try {
            $code = new \BCGcode39();
            $code->setScale(2); // Resolution
            $code->setThickness(30); // Thickness
            $code->setForegroundColor($color_black); // Color of bars
            $code->setBackgroundColor($color_white); // Color of spaces
            $code->setFont($font); // Font (or 0)
            $code->parse($text); // Text
        } catch(Exception $exception) {
            $drawException = $exception;
        }
        $drawing = new \BCGDrawing('', $color_white);
        if($drawException) {
            $drawing->drawException($drawException);
        } else {
            $drawing->setBarcode($code);
            $drawing->draw();
        }
        if(input('terminal') == 'app'){
           $drawing->finish(\BCGDrawing::IMG_FORMAT_PNG);
        }else{
            header('Content-Type: image/png');
            header('Content-Disposition: inline; filename="barcode.png"');
            $drawing->finish(\BCGDrawing::IMG_FORMAT_PNG);
        }
		exit;
    }

	/*
	 * excel导入处理
	 */
	public function excel_import($file,$images=''){
		$path = 'excel/';
		
                $excel = $savename = md5(mt_rand()).'.'.$file->extension();
                \think\facade\Filesystem::disk('public')->putFileAs($path, $file,$savename);                
            
		$arrimg=array();
		if($images){
			foreach ($images as $k => $v){
				$res=$v->move($path,'');
				$arrimg[$k]=$res->getSaveName();
			}
		}

		//导入的excel数据处理开始
		$excel="public/upload/excel/".$excel;
		$arr=$this->importExcel($excel);//

		//excel模板头数组
		$excel_model=array('订单编号','物流编号');
		$excel_title=$arr[1];//excel头部标题部分

		if($excel_title!==$excel_model){
			return ['msg'=>'excel数据格式错误,请下载并参照excel模板','status'=>0];
//            $this->error('excel数据格式错误,请下载并参照excel模板',url('Seller/Order/delivery_excel'));
		}
		unset($arr[1]);
		$order_ids = [];
		foreach ($arr as $k => $v) {
			if($v[0] ==''){
				continue;
			}
			$order_id = \app\common\model\Order::where(['order_sn'=>$v[0]])->find();
			if(!$order_id){	//判断订单是否存在
				return ['msg'=>$v[0].'订单不存在','status'=>0];
//                $this->error($v[0].'订单不存在',url('Seller/Order/delivery_excel'));
				break;
			}
			if($order_id['shipping_status'] >0){
				return ['msg'=>$v[0].'订单已经发货','status'=>0];
			}
			if($order_id['order_status'] != 1){
				return ['msg'=>$v[0].'该订单状态不满足发货条件，不是在确认状态','status'=>0];
			}
			if($order_id['pay_status'] >2){
				return ['msg'=>$v[0].'该支付状态已退款','status'=>0];
			}
			$order_ids[$k]['id'] = $order_id['order_id'];
			$order_ids[$k]['invoice_no'] = $v[1];
		}

//        return $order_ids;
		return ['result'=>$order_ids,'status'=>1];

	}

	public function importExcel($file){
		require_once '../vendor/PHPExcel/PHPExcel.php';
		require_once '../vendor/PHPExcel/PHPExcel/IOFactory.php';
		require_once '../vendor/PHPExcel/PHPExcel/Reader/Excel5.php';

		$objReader = PHPExcel_IOFactory::createReader('Excel5');//use excel2007 for 2007 format
		$objPHPExcel = $objReader->load($file);

		$sheet = $objPHPExcel->getSheet(0);
		$highestRow = $sheet->getHighestRow(); // 取得总行数
		$highestColumn = $sheet->getHighestColumn(); // 取得总列数
		$objWorksheet = $objPHPExcel->getActiveSheet();

		$highestColumnIndex = PHPExcel_Cell::columnIndexFromString($highestColumn);
		$excelData = array();
		for ($row = 1; $row <= $highestRow; $row++) {
			for ($col = 0; $col < $highestColumnIndex; $col++) {
				$getValue = (string)$objWorksheet->getCellByColumnAndRow($col, $row)->getValue();

				$getValue = str_replace(' ','',$getValue);//全角空格
				$getValue = str_replace(' ','',$getValue);//半角空格

				$excelData[$row][] = $getValue;
			}
		}
		return $excelData;
	}
}