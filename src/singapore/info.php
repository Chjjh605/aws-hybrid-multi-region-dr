<?php
   $con=mysql_connect("dbsrv.idcsingapore.internal", "root", "") or die("MySQL 접속 실패");
   phpinfo();
   mysql_close($con);
?>
