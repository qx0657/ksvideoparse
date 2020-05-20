<?php
#接受所有请求源
header('Access-Control-Allow-Origin:*');
#指定返回数据为json utf-8
header('Content-type:application/json; charset=utf-8');

!empty($_REQUEST['url']) ? $originurl = $_REQUEST['url'] : retn(0,"请求参数错误");

#生成随机did,用于请求快手链接的cookie
$did = md5(time() . mt_rand(1,1000000));
#每次请求生成一个随机ip
$rip = Rand_IP();
#方便用户使用，如用户传递一个包含链接的文本，将链接正则出来
$originurl = preg_match("~[a-zA-z]+://[^\s]*~", $originurl, $originurlmatches);
if (count($originurlmatches) == 0) {
	#没有正则到要解析的地址
	retn(-4,"没有检测到要解析的地址");
}else{
	$url = $originurlmatches[0];
	#判断要解析的链接是短链接还是长链接
	preg_match("~[a-zA-z]+://live.kuaishou.com/u/[a-zA-z0-9]+/[a-zA-z0-9]+~", $url, $videoidmatches);
	if (count($videoidmatches) != 0) {
		#长链接
		#正则成功后截取最后的那个视频id
		$videoid = substr(strrchr($videoidmatches[0], "/"), 1);
		$url2 = 'https://c.kuaishou.com/fw/photo/'.$videoid.'?fid=281200681&cc=share_copylink&shareMethod=TOKEN&docId=0&kpn=KUAISHOU&subBiz=PHOTO&photoId='.$videoid.'&shareId=177551279794&shareToken=X-48680WzimADJVn_A&shareResourceType=PHOTO_OTHER&userId=3x3dzyvbvyugsem&shareType=1&et=1_i%2F0_unknown0&groupName=&appType=21&shareObjectId=26782848098&shareUrlOpened=0&timestamp=1589908450616';
	}else{
		#短链接
		#获取302重定向请求头
		$content1 = getResponseHeader($url);
		#从请求头里正则解析出重定向地址
		preg_match("~[a-zA-z]+://[^\s]*~", $content1, $matches);
		if (count($matches) == 0) {
			#没有正则到重定向地址
			retn(-5,"这可能不是一个有效的快手链接");
		}else{
			$url2 = $matches[0];#获取到302重定向地址
		}
	}
	#获取302重定向地址页面的响应体
	$content2 = getResponseBody($url2);
	#正则取出关键数据
	preg_match("~data-pagedata=\"(.*?)\"~", $content2, $matches);
	if (count($matches) <= 1) {
		#没有正则到关键数据
		retn(-6,"解析失败002");
	}else{
		$pagedata = $matches[1];
		#关键:将html实体转回字符串(如&#34;转")
		$pagedata= htmlspecialchars_decode($pagedata);
		#解析json为数组(去除pom头3空白字符 防止解析json失败)
		$pagedata_json = json_decode(trim($pagedata,chr(239).chr(187).chr(191)),true);
		if($pagedata_json == null){
			#关键数据解析为json失败
			retn(-7,"解析失败003");
		}else{
			if($pagedata_json['status']==1){
				$sharetype = $pagedata_json['share']['type'];

				$data = [];
				$data["type"] = $sharetype;
				$data["title"] = $pagedata_json['share']['title'];;
				$data["username"] = $pagedata_json['user']['name'];
				$data["poster"] = $pagedata_json['video']['poster'];
				if($sharetype=="video"){
					#视频
					$mp4url = $pagedata_json['video']['srcNoMark'];
					$data["mp4url"] = $mp4url;
					retn(1,"请求成功",$data);
				}elseif($sharetype=="images"||$sharetype=="image_long"){
					#图组或长图
					$data["images"] = $pagedata_json['video']['images'];
					$imageCdn = $pagedata_json['video']['imageCDN'];
					for ($i=0; $i < count($data["images"]); $i++) {
					    $data["images"][$i]['path'] = "http://".$imageCdn.$data["images"][$i]['path'];
					}
					$data["audio"] = "http://".$imageCdn.$pagedata_json['video']['audio'];
					retn(1,"请求成功",$data);
				}elseif($sharetype=="image"){
					#图片
					$data["image"] = $data["poster"];
					$imageCdn = $pagedata_json['video']['imageCDN'];
					$data["audio"] = "http://".$imageCdn.$pagedata_json['video']['audio'];
					retn(1,"请求成功",$data);
				}else{
					#暂时写了图片、图组、长图、视频的解析。其他作品类型可自行测试添加
					retn(-10,"该作品类型暂不支持，敬请期待");
				}
			}else{
				#如果状态码不为1，看下是否有错误并输出错误信息
				if($pagedata_json['error']==True){
					#有时会返回错误：快手验证码 经测试，使用作品最新分享链接即可正常获取
					if($pagedata_json['error_msg']=="快手验证码"){
						retn(-11,"请用作品最新分享链接重试");
					}else{
						retn(-8,$pagedata_json['error_msg']);
					}
				}else{
					retn(-9,"解析失败004");
				}
			}
		}
	}	
}
//随机IP
function Rand_IP(){
	#第一种方法，直接生成
    $ip2id= round(rand(600000, 2550000) / 10000);
    $ip3id= round(rand(600000, 2550000) / 10000);
    $ip4id= round(rand(600000, 2550000) / 10000);
	#第二种方法，随机抽取
    $arr_1 = array("218","218","66","66","218","218","60","60","202","204","66","66","66","59","61","60","222","221","66","59","60","60","66","218","218","62","63","64","66","66","122","211");
    $randarr= mt_rand(0,count($arr_1)-1);
    $ip1id = $arr_1[$randarr];
    return $ip1id.".".$ip2id.".".$ip3id.".".$ip4id;
}
#获取重定向请求头
function getResponseHeader($url) {
    $ch  = curl_init($url);
    $httpheader = [];
    $httpheader[] = 'X-FORWARDED-FOR:'.$rip;
    $httpheader[] = 'CLIENT-IP:'.$rip;
    #请求头中添加cookie
    $httpheader[] = 'cookie:did=web_'.$did.'; didv='.time().'000;clientid=3; client_key=6589'.rand(1000, 9999);
    curl_setopt($ch, CURLOPT_HTTPHEADER,$httpheader);
    #以下两句设置返回响应头不返回响应体
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    #返回数据不直接输出
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $content = curl_exec($ch);
    curl_close($ch);
    return $content;
}
#获取响应体
function getResponseBody($url) {
    $ch = curl_init();
    #5秒超时
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5000);
    #设置默认ua  这里经常测试，尽量用手机的ua,电脑的ua获取不到数据
    curl_setopt($ch, CURLOPT_USERAGENT,'User-Agent: Mozilla/5.0 (Linux; Android 5.1.1; vivo X9 Plus Build/LMY48Z) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/39.0.0.0 Mobile Safari/537.36');
    #把随机ip添加进请求头 
    $httpheader = [];
    $httpheader[] = 'X-FORWARDED-FOR:'.$rip;
    $httpheader[] = 'CLIENT-IP:'.$rip;
    #请求头中添加cookie
    $httpheader[] = 'cookie:did=web_'.$did.'; didv='.time().'000;';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
    #返回数据不直接输出
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    #设置请求地址
    curl_setopt($ch, CURLOPT_URL, $url);
    #关闭ssl验证
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    #设置默认referer
    curl_setopt($ch, CURLOPT_REFERER, 'http://m.gifshow.com');
    #get方式请求
    curl_setopt($ch, CURLOPT_POST, false);
    $contents = curl_exec($ch);
    curl_close($ch);
    return $contents;
}
#数据返回
function retn($code,$str,$data=null){
    if($data==null){
        exit(json_encode([
            "code"=>$code,
            "msg"=>$str
        ],JSON_UNESCAPED_UNICODE));
    }else{
        exit(json_encode([
            "code"=>$code,
            "msg"=>$str,
            "data"=>$data
        ],JSON_UNESCAPED_UNICODE));
    }  
}
?>
