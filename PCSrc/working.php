<?php
require_once("lib.php");

date_default_timezone_set('Asia/Tokyo');

$port = init_serial("COM3");

//�o�C�g�̓��������
$idx = 0;
for($idx = 0; $idx < 8; $idx++){
	$ack = sendCom($port, 0x00, TRUE);
	if($ack !== NULL) break; //ack��������I��
	$ack = sendCom($port, 0xFF, TRUE);
	if($ack !== NULL) break; //ack��������I��
	
	usleep(1000 * 100);
}
if($idx == 8) die("�����Ɏ��s���܂���");


//���ݎ��Ԃ���9���ԕ����M
//BCD�����pTBL
$div_tbl = array(
	"minutes" => array(  0,  40,  20,  10,   0,   8,   4,   2,   1),
	"hours"   => array(  0,   0,  20,  10,   0,   8,   4,   2,   1),
	"days"    => array(  0,   0, 200, 100,   0,  80,  40,  20,  10,
                       8,   4,   2,   1,   0,   0,   0,   0,   0),
  "year"    => array(  0,  80,  40,  20,  10,   8,   4,   2,   1),
  "week"    => array(  4,   2,   1,   0,   0,   0,   0,   0,   0)
);

//�r�b�g�v�Z�pTBL_TEMPLATE
$val_tbl = array(
	"minutes" => 0,
	"hours"   => 0,
	"days"    => 0,
	"year"    => 0,
	"week"    => 0
);

while(1){
	echo date("r") . " : SEND TIME DATA START.\n";

	//���̐؂�ւ��܂őҋ@(2�b�O�ōs��)
	if(idate("s") >= 58) sleep(2);
	while(idate("s") < 58){
		usleep(1000 * 10);
	}

	$retry = 0;
	for($retry = 0; $retry < 5; $retry++){
		//STOP���߂𑗐M
		sendCom($port, 0x00);
		sendCom($port, 0x00);
		sendCom($port, 0x00);
		sendCom($port, 0x00);
		sendCom($port, 0x00);
		sendCom($port, 0x00);
		sendCom($port, 0x02);
		$ack = sendCom($port, 0x02, TRUE); //ACK�҂�
		if($ack == ORD("O")){
			break;
		}
	}
	if($retry == 5){
		//retry����Ȃ�I��
		die("STOP���ߑ��M���s");
	}


	$basetim = time() + 2; //���݂͂T�W�b�Ȃ̂łQ�b���Z
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

		$parity = array(0, 0); //�p���e�B�p
		$bit_list = array();
		foreach($val_tbl as $key => $cval){
			if(!isset($div_tbl[$key])){
				die("�����pTBL�ɃL�[�Ȃ�");
			}
			for($d_idx = 0; $d_idx < count($div_tbl[$key]); $d_idx++){
				if($div_tbl[$key][$d_idx] > 0){
					if($cval >= $div_tbl[$key][$d_idx]){
						$bit_list[] = 1;
						
						$cval -= $div_tbl[$key][$d_idx];
						
						//�p���e�B���Z
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
		//�p���e�B�i�[
		$bit_list[33] = $parity[0] % 2;
		$bit_list[34] = $parity[1] % 2;

		//�R���g���[�����Z�b�g
		//�ŏ��̑��M��START��ON��
		if($min_idx == 0){
			$bit_list[] = 0;
			$bit_list[] = 1;
		}else{
			$bit_list[] = 0;
			$bit_list[] = 0;
		}

		$byte_list = array(); //���M����o�C�g���X�g
		$checksum = 0; //�`�F�b�N�T���v�Z�p
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
			//�ŏI�E�F�C�g
			while((microtime(TRUE)-time()) <= 0.800){
				usleep(1000 * 1);
			}
			usleep(1000 * 200);
		}

		//ACK���s���Ȃ�đ�
		$retry = 0;
		for($retry = 0; $retry < 5; $retry++){ //5�񃊃g���C
			for($b_idx = 0; $b_idx < count($byte_list) - 1; $b_idx++){
				sendCom($port, $byte_list[$b_idx]);
			}
			//�Ō��ACK�҂�
			$ack = sendCom($port, $byte_list[count($byte_list)-1], TRUE);
			if($ack === ord('O')){
				break;
			}
		}
		//���g���C����Ȃ狭���I��
		if($retry == 5){
			die("�R�}���h���M�̃��g���C���(5)�ɒB���܂���");
		}

	}
	
	//�P���ԑ҂�
	$wait_start_time = time();
	while((time() - $wait_start_time) < 3600){
		usleep(1000 * 100);
	}
}
?>