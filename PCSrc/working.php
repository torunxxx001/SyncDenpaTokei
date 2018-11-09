<?php
require_once("lib.php");

date_default_timezone_set('Asia/Tokyo');

$port = init_serial("COM3");

//バイトの同期を取る
$idx = 0;
for($idx = 0; $idx < 8; $idx++){
	$ack = sendCom($port, 0x00, TRUE);
	if($ack !== NULL) break; //ackがきたら終了
	$ack = sendCom($port, 0xFF, TRUE);
	if($ack !== NULL) break; //ackがきたら終了
	
	usleep(1000 * 100);
}
if($idx == 8) die("同期に失敗しました");


//現在時間から9時間分送信
//BCD分割用TBL
$div_tbl = array(
	"minutes" => array(  0,  40,  20,  10,   0,   8,   4,   2,   1),
	"hours"   => array(  0,   0,  20,  10,   0,   8,   4,   2,   1),
	"days"    => array(  0,   0, 200, 100,   0,  80,  40,  20,  10,
                       8,   4,   2,   1,   0,   0,   0,   0,   0),
  "year"    => array(  0,  80,  40,  20,  10,   8,   4,   2,   1),
  "week"    => array(  4,   2,   1,   0,   0,   0,   0,   0,   0)
);

//ビット計算用TBL_TEMPLATE
$val_tbl = array(
	"minutes" => 0,
	"hours"   => 0,
	"days"    => 0,
	"year"    => 0,
	"week"    => 0
);

while(1){
	echo date("r") . " : SEND TIME DATA START.\n";

	//分の切り替わりまで待機(2秒前で行う)
	if(idate("s") >= 58) sleep(2);
	while(idate("s") < 58){
		usleep(1000 * 10);
	}

	$retry = 0;
	for($retry = 0; $retry < 5; $retry++){
		//STOP命令を送信
		sendCom($port, 0x00);
		sendCom($port, 0x00);
		sendCom($port, 0x00);
		sendCom($port, 0x00);
		sendCom($port, 0x00);
		sendCom($port, 0x00);
		sendCom($port, 0x02);
		$ack = sendCom($port, 0x02, TRUE); //ACK待ち
		if($ack == ORD("O")){
			break;
		}
	}
	if($retry == 5){
		//retry上限なら終了
		die("STOP命令送信失敗");
	}


	$basetim = time() + 2; //現在は５８秒なので２秒加算
	$basetim_bak = $basetim;
	for($min_idx = 0; $min_idx < 540; $min_idx++){
		$basetim = $basetim_bak + ($min_idx * 60);

		$dt   = getdate($basetim);
		$days = $basetim - mktime(0, 0, 0, 1, 1, $dt["year"]);
		$days = ((int)($days / 3600 / 24)) + 1;

		$val_tbl = array(
			"minutes" => $dt["minutes"],
			"hours"   => $dt["hours"],
			"days"    => $days,
			"year"    => $dt["year"] % 100,
			"week"    => $dt["wday"]
		);

		$parity = array(0, 0); //パリティ用
		$bit_list = array();
		foreach($val_tbl as $key => $cval){
			if(!isset($div_tbl[$key])){
				die("分割用TBLにキーなし");
			}
			for($d_idx = 0; $d_idx < count($div_tbl[$key]); $d_idx++){
				if($div_tbl[$key][$d_idx] > 0){
					if($cval >= $div_tbl[$key][$d_idx]){
						$bit_list[] = 1;
						
						$cval -= $div_tbl[$key][$d_idx];
						
						//パリティ加算
						switch($key){
						case "hours"  : $parity[0]++; break;
						case "minutes": $parity[1]++; break;
						}
					}else{
						$bit_list[] = 0;
					}
				}else{
					$bit_list[]   = 0;
				}
			}
		}
		//パリティ格納
		$bit_list[33] = $parity[0] % 2;
		$bit_list[34] = $parity[1] % 2;

		//コントロール情報セット
		//最初の送信でSTARTをONに
		if($min_idx == 0){
			$bit_list[] = 0;
			$bit_list[] = 1;
		}else{
			$bit_list[] = 0;
			$bit_list[] = 0;
		}

		$byte_list = array(); //送信するバイトリスト
		$checksum = 0; //チェックサム計算用
		$bit_str = "";
		foreach($bit_list as $bit_val){
			$bit_str .= $bit_val;
			if(strlen($bit_str) == 8){
				$data_val = bindec($bit_str);
				$checksum += $data_val;
				
				$byte_list[] = $data_val;
				
				$bit_str = "";
			}
		}
		$byte_list[] = ($checksum & 0xFF);
		
		if($min_idx == 0){
			//最終ウェイト
			while((microtime(TRUE)-time()) <= 0.800){
				usleep(1000 * 1);
			}
			usleep(1000 * 200);
		}

		//ACKが不正なら再送
		$retry = 0;
		for($retry = 0; $retry < 5; $retry++){ //5回リトライ
			for($b_idx = 0; $b_idx < count($byte_list) - 1; $b_idx++){
				sendCom($port, $byte_list[$b_idx]);
			}
			//最後にACK待ち
			$ack = sendCom($port, $byte_list[count($byte_list)-1], TRUE);
			if($ack === ord('O')){
				break;
			}
		}
		//リトライ上限なら強制終了
		if($retry == 5){
			die("コマンド送信のリトライ上限(5)に達しました");
		}

	}
	
	//１時間待つ
	$wait_start_time = time();
	while((time() - $wait_start_time) < 3600){
		usleep(1000 * 100);
	}
}
?>