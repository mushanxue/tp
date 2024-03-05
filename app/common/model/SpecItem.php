<?php
/**
 * tpshop
 * ============================================================================
 * 版权所有 2015-2027 深圳搜豹网络科技有限公司，并保留所有权利。
 * 网站地址: http://www.tp-shop.cn
 * ----------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在不用于商业目的的前提下对程序代码进行修改和使用 .
 * 不允许对程序代码以任何形式任何目的的再发布。
 * ============================================================================
 * Author: IT宇宙人
 * Date: 2015-09-09
 */
namespace app\common\model;
use think\facade\Db;
use app\common\util\TpshopException;
use think\Model;
class SpecItem extends Model {

    protected $pk = 'id';

    public function delete(): bool
    {
        $id = $this->getAttr('id');
        $spec_goods_price = Db::name('spec_goods_price')->whereOr('key', $id)->whereOr('key', 'LIKE', '%\_' . $id)->whereOr('key', 'LIKE', $id . '\_%')->column('goods_id,key');
        if ($spec_goods_price) {
            $goods_ids = [];
            foreach($spec_goods_price as $goods_id=>$key){
                $keys = explode('_',$key);
                if(in_array($id,$keys)){
                    $goods_ids[] = $goods_id;
                }
            }
            if($goods_ids){
                $goods_names = Db::name('goods')->where('goods_id','in', $goods_ids)->column('goods_name');
                if($goods_names){
                    $count = count($goods_names);
                    $goods_name = implode(':',$goods_names);
                    throw new TpshopException('删除规格值', 0, ['status' => 0, 'msg' => $goods_name . ($count==1?'[id:'.$goods_ids[0].']': '共'.$count.'个').'商品正在使用该规格，不能删除']);
                }
            }
        }
        return parent::delete(); // TODO: Change the autogenerated stub
    }
}
