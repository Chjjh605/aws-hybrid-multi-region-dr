#!/bin/bash
# ==========================================
# 싱가포르 IDC Slave DB (MySQL 8.0) 자동 설정 스크립트
# ==========================================

# 1. MySQL 8.0 설치
dnf install -y https://dev.mysql.com/get/mysql80-community-release-el9-1.noarch.rpm
rpm --import https://repo.mysql.com/RPM-GPG-KEY-mysql-2023
dnf install -y mysql-community-server

# 2. my.cnf 설정 (읽기 전용 및 인코딩)
cat <<EOF > /etc/my.cnf.d/mysql-community.cnf
[mysqld]
server-id=2
read_only=1
collation-server=utf8mb4_general_ci
character-set-server=utf8mb4
default_authentication_plugin=mysql_native_password
bind-address=0.0.0.0
EOF

# 3. 서비스 시작
systemctl enable --now mysqld
systemctl restart mysqld

# 4. 임시 비밀번호 추출
TEMP_PASS=$(grep 'temporary password' /var/log/mysqld.log | awk '{print $NF}')

# 5. 비밀번호 변경 및 웹 서버 접근용 계정 생성
mysql --connect-expired-password -u root -p"$TEMP_PASS" -e "
  ALTER USER 'root'@'localhost' IDENTIFIED BY 'Temp1234!@#$';
  SET GLOBAL validate_password.policy = 0;
  SET GLOBAL validate_password.length = 4;
  ALTER USER 'root'@'localhost' IDENTIFIED BY 'p@ssw0rd';
  
  CREATE USER 'user1'@'%' IDENTIFIED BY 'p@ssw0rd';
  GRANT ALL PRIVILEGES ON *.* TO 'user1'@'%' WITH GRANT OPTION;
  
  FLUSH PRIVILEGES;
"