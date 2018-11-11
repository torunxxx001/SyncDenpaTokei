# 概要
趣味で作成した、PCの時刻を市販の電波時計に設定する機械にて使用しているソースコードです。
> PC -USB-> 電波時計用信号(JJY信号)発生器 -JJY信号-> 電波時計

の流れで時刻信号を電波時計に伝えます。

# ソースコード
SendModuleSrc/DenpaDokei.c が、電波時計用信号発生器内部のプログラム(C言語)  
PCSrc/working.php が、PCからUSB経由で電波時計用信号発生器に時刻を送信するプログラム(PHP)です。

# 電波時計用信号発生器の外観
![SyncDenpaTokeiImg](https://github.com/torunxxx001/SyncDenpaTokei/raw/master/Cmm7pnRUIAAykgz.jpg)

以上
