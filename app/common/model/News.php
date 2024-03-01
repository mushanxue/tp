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
 * Author: lpy
 * Date: 2018-10-09
 */
namespace app\common\model;
use think\facade\Db;
use think\Model;

class News extends Model {

    protected $pk = 'article_id';

    //自定义初始化
    static $OPEN_TYPE = 0;
    static $OPEN_STATUS = 1;
    //一页显示数量
    public static $LIMIT = 10;
    //审核状态
    public static $CHECK_PASS = 1;
    public static $CHECK_FAIL = 2;
    public static $CHECK_WAIT = 0;

    //显示状态
    public static $STATUS_OPEN = 1;
    public static $STATUS_CLOSE  = 0;
    public function newsCat(){
        return $this->hasOne('NewsCat', 'cat_id', 'cat_id');
    }
    public function newsComment(){
        return $this->hasMany('newsComment', 'article_id', 'article_id');
    }
}
