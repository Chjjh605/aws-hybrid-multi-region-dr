#!/bin/bash
# ─────────────────────────────────────────────────────────
# Rocky Linux BIND DNS Server 자동 구축 스크립트 (Seoul IDC)
# ─────────────────────────────────────────────────────────
set -euxo pipefail

# 프롬프트 설정 변경
cat <<'EOT' > /etc/profile.d/prompt.sh
export PS1="[\[\e[1;31m\]\u\[\e[m\]@\[\e[1;32m\]\h\[\e[m\]: \[\e[1;36m\]\w\[\e[m\]]#"
EOT
source /etc/profile || true

# 호스트네임 설정
HOSTNAME="Seoul-IDC-DNS"
hostnamectl --static set-hostname "$HOSTNAME"
sed -i "s/^127\.0\.0\.1.*/127.0.0.1   localhost $HOSTNAME/" /etc/hosts || true

# BIND 패키지 설치
dnf -y update
dnf -y install bind bind-utils policycoreutils-python-utils

# BIND 기본 설정 변경 (모든 대역에서 질의 수신 허용)
sed -i "s/listen-on port 53 { 127.0.0.1; };/listen-on port 53 { any; };/g" /etc/named.conf
sed -i "s/allow-query     { localhost; };/allow-query     { any; };/g" /etc/named.conf

# 사설 존(Zone) 및 싱가포르 IDC DNS 포워딩 설정 등록
cat <<'EOF' >> /etc/named.rfc1912.zones
zone "idcseoul.internal" {
    type master;
    file "db.idcseoul.internal";
};

zone "1.2.10.in-addr.arpa" {
    type master;
    file "db.10.2";
};

zone "idcsingapore.internal" {
    type forward;
    forwarders { 10.4.1.200; };
};
EOF

# 정방향 존 파일 생성
cat <<'EOF' > /var/named/db.idcseoul.internal
$TTL 30
@ IN SOA idcseoul.internal. root.idcseoul.internal. (
  2019122115 ; serial
  3600       ; refresh
  900        ; retry
  604800     ; expire
  86400      ; minimum
)

; 네임서버 정의
@       IN NS ns1.idcseoul.internal.

; 네임서버 및 호스트 A 레코드
ns1     IN A  10.2.1.200
dbsrv   IN A  10.2.1.100
dnssrv  IN A  10.2.1.200
EOF

# 역방향 존 파일 생성
cat <<'EOF' > /var/named/db.10.2
$TTL 30
@ IN SOA idcseoul.internal. root.idcseoul.internal. (
  2019122115 ; serial
  3600       ; refresh
  900        ; retry
  604800     ; expire
  86400      ; minimum
)

; 네임서버 정의
@       IN NS ns1.idcseoul.internal.

; 역방향 PTR 레코드
100   IN PTR dbsrv.idcseoul.internal.
200   IN PTR dnssrv.idcseoul.internal.
200   IN PTR ns1.idcseoul.internal.
EOF

# 권한 부여 및 SELinux 복구
chown named:named /var/named/db.idcseoul.internal /var/named/db.10.2
chmod 640 /var/named/db.idcseoul.internal /var/named/db.10.2
restorecon -Rv /var/named || true

# 서비스 활성화 및 재기동
systemctl enable --now named
systemctl restart named