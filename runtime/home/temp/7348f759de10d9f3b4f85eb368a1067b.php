<?php /*a:1:{s:42:"../template/pc/rainbow/user/pop_login.html";i:1593659405;}*/ ?>
<!DOCTYPE HTML>
<html lang="en">
<head>
  <meta charset="UTF-8">
   <title>登录-<?php echo $tpshop_config['shop_info_store_title']; ?></title>
   <meta name="keywords" content="<?php echo $tpshop_config['shop_info_store_keyword']; ?>" />
   <meta name="description" content="<?php echo $tpshop_config['shop_info_store_desc']; ?>" />
  <link rel="stylesheet" href="/pc/rainbow/static/css/fn_login.css">
  <script src="/pc/rainbow/static/js/jquery-1.11.3.min.js" type="text/javascript" charset="utf-8"></script>
    <link rel="shortcut icon" type="image/x-icon" href="<?php echo (isset($tpshop_config['shop_info_store_ico']) && ($tpshop_config['shop_info_store_ico'] !== '')?$tpshop_config['shop_info_store_ico']:'/public/static/images/logo/storeico_default.png'); ?>" media="screen"/>
</head>
<body>
<div class="u-diaolog-login-content">
  <form action="#" name="" class="J-login" method="post">
    <input type="hidden" value="" id="refer">
    <div class="u-reg mb13"><a class="f12" href="<?php echo url('Home/User/reg'); ?>" target="_blank">免费注册</a></div>
    <div class="u-message-error mb10 pl30 f12 u-msg-wrap" id="u-mb-msg" ><i></i>
    	<span class="J-errorMsg">公共场所不建议自动登录，以防帐号丢失</span>
    </div>
    <div class="u-input mb20">
      <label for="loginname" class="u-label u-name"></label>
      <input id="username" type="text" class="u-txt J-txt"   value="" name="username" tabindex="1" placeholder="邮箱/用户名/手机号">
    </div>
    <div class="u-input mb10">
      <label for="loginpwd" class="u-label u-pwd"></label>
      <input id="password" type="password" class="u-txt J-txt" name="password" tabindex="2" placeholder="密码">
    </div>
    <div class="u-forget fn-clear">
      <p class="ltxt">
      	<label><input type="hidden" name="referurl" id="referurl" value="<?php echo $referurl; ?>">
      	<input type="checkbox" class="ainput" name="chkRememberMe" />自动登录</label>
      </p>
      <a class="f12" href="<?php echo url('Home/User/forget_pwd'); ?>" target="_blank">忘记密码了？</a>
    </div>
    <div class="u-input u-authcode mt10" id="logincaptcha">
        <div class="u-vcode">
          <input id="verify_code" type="text" tabindex="3" class="u-txt u-txt02 J-txt" name="verify_code" placeholder="验证码">
        </div>
        <div class="u-vcode-img ml5"><img height="40" src="/index.php/Home/User/verify.html" id="verify_code_img"></div>
        <a class="u-change f12" href="javascript:void(0)" onclick="verify(this);">看不清换一张</a>
    </div>
    <div class="u-btn mt20">
  <input id="J_sbmbtn" type="button" tabindex="4" onclick="checkSubmit()" value="登  录">
</div>
</form>
<div class="u-qq">
	<span>您可以用合作伙伴账号登录：</span>
    <?php
                                   
                                $md5_key = md5("select * from __PREFIX__plugin where type='login' AND status = 1 order by code desc");
                                $result_name = $sql_result_v = cache("sql_".$md5_key);
                                if(empty($sql_result_v))
                                {                            
                                    $result_name = $sql_result_v = \think\facade\Db::query("select * from __PREFIX__plugin where type='login' AND status = 1 order by code desc"); 
                                    cache("sql_".$md5_key,$sql_result_v,1);
                                }    
                              foreach($sql_result_v as $k=>$v): if($v['code'] == 'weixin'): ?>
            <a href="<?php echo url('LoginApi/login',array('oauth'=>'weixin')); ?>" class="weixin" title="weixin"></a>
        <?php endif; if($v['code'] == 'qq'): ?>
            <a href="<?php echo url('LoginApi/login',array('oauth'=>'qq')); ?>"  class="qq" title="QQ"></a>
        <?php endif; if($v['code'] == 'alipay'): ?>
            <a href="<?php echo url('LoginApi/login',array('oauth'=>'alipay')); ?>"  class="pay" title="支付宝"></a>
        <?php endif; if($v['code'] == 'alipaynew'): ?>
            <a href="<?php echo url('LoginApi/login',array('oauth'=>'alipaynew')); ?>" class="pay" title="支付宝"></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
</div>
</body>
<script>	
function checkSubmit()
{
	$('.u-msg-wrap').hide();
	$('.J-errorMsg').empty();
	var username = $.trim($('#username').val());
	var password = $.trim($('#password').val());
	var referurl = $('#referurl').val();
	var verify_code = $.trim($('#verify_code').val());
	if(username == ''){
		showErrorMsg('用户名不能为空!');
		return false;
	}
	if(!checkMobile(username) && !checkEmail(username)){
		showErrorMsg('账号格式不匹配!');
		return false;
	}
	if(password == ''){
		showErrorMsg('密码不能为空!');
		return false;
	}
	
	if(verify_code == ''){
		showErrorMsg('验证码不能为空!');
		return false;
	}	
	//$('#login-form').submit();
	$.ajax({
		type : 'post',
		url : '/index.php?m=Home&c=User&a=do_login&t='+Math.random(),
		data : {username:username,password:password,referurl:referurl,verify_code:verify_code},		
		dataType : 'json',
		success : function(res){
			if(res.status == 1){
				top.location.href = res.url;
			}else{
				showErrorMsg(res.msg);
				verify();
			}
		},
		error : function(XMLHttpRequest, textStatus, errorThrown) {
			showErrorMsg('网络失败，请刷新页面后重试');
		}
	})
}
   
function checkMobile(tel) {
    var reg = /^1[0-9]{10}$/;
    if (reg.test(tel)) {
        return true;
    }else{
        return false;
    };
}

function checkEmail(str){
    var reg = /^([a-zA-Z0-9]+[_|\-|\.]?)*[a-zA-Z0-9]+@([a-zA-Z0-9]+[_|\-|\.]?)*[a-zA-Z0-9]+\.[a-zA-Z]{2,3}$/;
    if(reg.test(str)){
        return true;
    }else{
        return false;
    }
}

function showErrorMsg(msg){
	$('.u-msg-wrap').show();
	$('.J-errorMsg').html(msg);
}


function verify(){
    $('#verify_code_img').attr('src','/index.php?m=Home&c=User&a=verify&r='+Math.random());
}
</script>
</html>
