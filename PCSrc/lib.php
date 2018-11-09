<?php
use PHPMake\SerialPort as SerialPort;

function init_serial($port_name){
	$port = new SerialPort("COM3");
	$port->setBaudRate(SerialPort::BAUD_RATE_115200);
	$port->setFlowControl(SerialPort::FLOW_CONTROL_NONE);
	$port->setNumOfStopBits(SerialPort::STOP_BITS_1_0);
	$port->setCanonical(false);
	$port->setVTime(1);
	$port->setVMin(0);

	return $port;
}

function close_serial($port){
	$port->close();
}

function sendCom($port, $data, $rec_wait = FALSE){
	$port->write(pack("C", $data));

	if($rec_wait === TRUE){
		$rd_val = $port->read(1);
		if($rd_val === ""){
			return NULL;
		}else{
			return array_shift(unpack("C", $rd_val));
		}
	}else{
		return;
	}
}

?>