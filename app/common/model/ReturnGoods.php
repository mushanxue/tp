<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2017/8/25
 * Time: 14:26
 */

namespace app\common\model;
use think\facade\Db;
use think\Model;

class ReturnGoods extends Model {

    protected $pk = 'id';


    public function Order(){
        return $this->hasOne('order','order_id','order_id');
    }

    public function getGoodsNameAttr($value, $data){
        return Db::name('order_goods')->where(['goods_id'=>$data['goods_id'],'order_id'=>$data['order_id']])->value('goods_name');

    }

}