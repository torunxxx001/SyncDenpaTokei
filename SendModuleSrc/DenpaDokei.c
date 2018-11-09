/*
===============================================================================
 Name        : DenpaDokei.c
 Author      : $(author)
 Version     :
 Copyright   : $(copyright)
 Description : main definition
===============================================================================
*/

#include "LPC11xx.h"                        /* LPC11xx definitions */
#include "timer32.h"
#include "timer16.h"
#include "gpio.h"

#include <cr_section_macros.h>

#define MAX_DATA 3780 //９時間分


#define SYSCLOCK 80000000
#define PULSE_TIME_MARKER (SYSCLOCK * 0.2) //0.2s
#define PULSE_TIME_0 (SYSCLOCK * 0.8) //0.8s
#define PULSE_TIME_1 (SYSCLOCK * 0.5) //0.5s

//1秒の水晶誤差
#define OSC_DIFF (-4850)

volatile int time_tbl_start_marker_flag = 0;
volatile int time_tbl_end_marker_flag = 0;
volatile int time_tbl_idx = 0;
volatile int pulse_time_tbl[] = {
		PULSE_TIME_0, PULSE_TIME_1, PULSE_TIME_MARKER
};
//9時間分
volatile unsigned char time_table[MAX_DATA];
volatile int write_idx = 0;

int main(void) {
	SystemInit();
	SystemCoreClockUpdate();

	//1秒タイマー
	LPC_SYSCON->SYSAHBCLKCTRL |= (1<<9);
	LPC_TMR32B0->TCR = 0b10;
	LPC_TMR32B0->MR0 = SYSCLOCK + OSC_DIFF;
	LPC_TMR32B0->MCR = 0b11;
	NVIC_EnableIRQ(TIMER_32_0_IRQn);
	//--END

	//パルス発生用タイマー
	LPC_SYSCON->SYSAHBCLKCTRL |= (1<<10);
	LPC_TMR32B1->TCR = 0b10;
	LPC_TMR32B1->MR0 = 0;
	LPC_TMR32B1->MCR = 0b101;
	NVIC_EnableIRQ(TIMER_32_1_IRQn);
	//--END

	//40KHz信号送出用タイマー
	LPC_SYSCON->SYSAHBCLKCTRL |= (1<<7);
	LPC_TMR16B0->TCR = 0b10;
	LPC_IOCON->PIO0_8 &= ~0x07;
	LPC_IOCON->PIO0_8 |= 0x02;
	LPC_TMR16B0->MR0 = (SYSCLOCK / 40000) / 2;
	LPC_TMR16B0->MR1 = SYSCLOCK / 40000;
	LPC_TMR16B0->MCR = 1<<4;
	LPC_TMR16B0->PWMC = 0x0001;
	//--END

	//GPIO Setting
	LPC_SYSCON->SYSAHBCLKCTRL |= (1<<6);
	LPC_GPIO0->DIR |= (0x1<<9); // PORT0_Pin9 set output
	LPC_GPIO0->DATA &= ~(1<<9); // set low

	//UART Setting
    uint32_t  baudrate = 115200;
    uint32_t  Fdiv;   // clock divisor ratio

    // P01_7, select function TXD
    LPC_IOCON->PIO1_7 &= ~0x07;         // reset FUNC=0x0
    LPC_IOCON->PIO1_7 |= 0x01;          // set   FUNC=0x1 (TXD)

    // P01_6, select function RXD
    LPC_IOCON->PIO1_6 &= ~0x07;         // reset FUNC=0x0
    LPC_IOCON->PIO1_6 |= 0x01;          // set FUNC=0x1 (RXD)

    // enable UART clock
    LPC_SYSCON->SYSAHBCLKCTRL |= (1<<12);   // UART=1
    LPC_SYSCON->UARTCLKDIV = 0x01;          // divided by 1

    // calculate Fdiv
    Fdiv = (
            SystemCoreClock             // system clock frequency
          * LPC_SYSCON->SYSAHBCLKDIV    // AHB clock divider
        ) / (
            LPC_SYSCON->UARTCLKDIV      // UART clock divider
          * 16 * baudrate               // baud rate
        );

    // set the baud rate divisor value
    LPC_UART->LCR |= 0x80;        // DLAB in LCR must be one in order to access the UART divisor latches
    LPC_UART->DLM = Fdiv / 256;   // set DLM, divisor latches
    LPC_UART->DLL = Fdiv % 256;   // set DLL, divisor latches
    LPC_UART->LCR &= ~0x80;       // disable access to divisor latches (DLAB = 0)

    LPC_UART->LCR = 0x03;   // 8 bit, 1 stop bit, no parity
    LPC_UART->FCR = 0x07;   // enable an reset TX and RX FIFO

	volatile char parity_sum;
	volatile int write_idx_tmp;
	volatile int idx;
	volatile char data_tmp;
	volatile char recv_array[8];
    while(1) {
    	//1packet 受信
    	for(idx = 0; idx < 8; idx++){
        	while (!(LPC_UART->LSR & (1<<0)));   // wait for RX buffer ready
        	recv_array[idx] = LPC_UART->RBR;
    	}

    	write_idx_tmp = write_idx;
    	parity_sum = 0;
    	for(idx = 0; idx < 8; idx++){
    	    if(idx + 1 < 8){
				parity_sum += recv_array[idx];

				if(write_idx < MAX_DATA){
					time_table[write_idx++] = recv_array[idx];
				}
    	    }
    	}

    	if(parity_sum == recv_array[7]){
    		data_tmp = 'O'; // OK

    		//Controlに応じた操作実行
    		switch(recv_array[6] & 0b11){
    		case 0b01:
    			//start
    			time_tbl_start_marker_flag = 0;
    			time_tbl_end_marker_flag = 0;
    			time_tbl_idx = 0;

        		LPC_TMR32B0->TCR = 0b01;
    			break;
    		case 0b10:
    			//stop
    			write_idx = 0;
    			LPC_TMR32B0->TCR = 0b10;
    			break;
    		}
    	}else{
    		data_tmp = 'N'; // NG

    		//write_idx戻す
    		write_idx = write_idx_tmp;
    	}
    	//ステータスコード返信
    	while (!(LPC_UART->LSR & (1 << 5)));   // wait for TX buffer ready
    	LPC_UART->THR = data_tmp;
    }

    return 0 ;
}

void TIMER32_0_IRQHandler(void){
	if(LPC_TMR32B0->IR & 0x1){
		LPC_TMR32B0->IR = 1;

		LPC_GPIO0->DATA |= (1<<9);

		if(time_tbl_start_marker_flag == 0){
			LPC_TMR32B1->MR0 = pulse_time_tbl[2];
		}else if(time_tbl_end_marker_flag == 9){
			LPC_TMR32B1->MR0 = pulse_time_tbl[2];
		}else{
			LPC_TMR32B1->MR0 =
					pulse_time_tbl[
						(time_table[(time_tbl_idx>>3)] >> (7-(time_tbl_idx&0b111))) & 0b1
					];
		}
		LPC_TMR32B1->TCR = 0b01;
		LPC_TMR16B0->TCR = 0b01;

		time_tbl_idx++;

		time_tbl_end_marker_flag++;
		if(time_tbl_end_marker_flag >= 10){
			time_tbl_idx--;
			time_tbl_end_marker_flag = 0;
		}

		time_tbl_start_marker_flag++;
		if(time_tbl_start_marker_flag >= 60){
			time_tbl_idx += 2; //controlビットを飛ばす
			time_tbl_start_marker_flag = 0;

			//バッファすべて処理したら終了
			if((time_tbl_idx >> 3) >= MAX_DATA){
				write_idx = 0;
				LPC_TMR32B0->TCR = 0b10;
			}
		}
	}
}

void TIMER32_1_IRQHandler(void){
	if(LPC_TMR32B1->IR & 0x1){
		LPC_TMR32B1->IR = 1;

		LPC_GPIO0->DATA &= ~(1<<9);

		LPC_TMR32B1->TCR = 0b10;
		LPC_TMR16B0->TCR = 0b10;

	}
}
