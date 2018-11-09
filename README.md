# 概要
PC内臓時計の時刻をJJY信号を経由して電波時計に設定するための機械に使用しているソースコードです。
> PC -USB-> 電波時計用信号(JJY信号)発生器 -JJY信号-> 電波時計

の流れです。

# ソースコード
SendModuleSrc/DenpaDokei.c が、電波時計用信号発生器内部のプログラム  
PCSrc/working.php が、PCからUSB経由で電波時計用信号発生器に時刻を送信するプログラムです。

# 電波時計用信号発生器の外観
![SyncDenpaTokeiImg](https://github.com/torunxxx001/SyncDenpaTokei/raw/master/Cmm7pnRUIAAykgz.jpg)

以上
