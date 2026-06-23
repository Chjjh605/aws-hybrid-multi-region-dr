	#!/bin/bash
# Rocky Linux 9 — Libreswan (route-based/XFRMi) + FRR(BGP/ECMP) + firewalld + MSS
# 로그 저장
exec > >(tee -a /var/log/userdata.log) 2>&1
set -euo pipefail

# ───────────────────────────────────────────────────────────────────────────────
# 변수(필요 시 위만 수정)
# ───────────────────────────────────────────────────────────────────────────────
# 터널 1
T1_OUTSIDE="15.164.197.19"
T1_AWS_INSIDE="169.254.178.133"
T1_CGW_INSIDE="169.254.178.134"

# 터널 2
T2_OUTSIDE="43.202.199.135"
T2_AWS_INSIDE="169.254.154.169"
T2_CGW_INSIDE="169.254.154.170"

# BGP ASN
ASN_AWS="64512"
ASN_CGW="65010"

# PSK (운영 권장: 16바이트 이상)
PSK_T1="cloudneta"
PSK_T2="cloudneta"

# 우리측 식별자(공인 IP 또는 FQDN) #CGW 공인 IP값 넣기
LEFTID_FQDN_OR_IP="13.124.232.180"

# 온프레미스 광고 대역
ADVERTISE_CIDR="10.2.0.0/16"

# 상대(AWS VPC)
AWS_VPC_CIDR="10.1.0.0/16"

# (선택) 내부 정적 라우트 예시 — 필요 시 조정
IDC_STATIC_SUBNET="10.2.1.0/24"
IDC_LAN_GW="10.2.1.1"

# TCP MSS (필요시 1380~1420 사이 조정)
TCP_MSS="1400"

# CloudWatch 대시보드(선택)
AWS_REGION="ap-northeast-2"
AWS_VPN_ID=""

# ───────────────────────────────────────────────────────────────────────────────
# 패키지/커널 준비
# ───────────────────────────────────────────────────────────────────────────────
dnf -y update || true
dnf -y install dnf-plugins-core
dnf config-manager --set-enabled crb #CRB 저장소를 활성화하는 명령어
dnf -y install epel-release
dnf -y install libreswan frr firewalld jq dos2unix

systemctl enable --now firewalld
systemctl enable --now ipsec
systemctl enable --now frr || true

# 커널 파라미터
cat >/etc/sysctl.d/99-ipsec-frr.conf <<'EOF'
net.ipv4.ip_forward = 1
net.ipv4.conf.all.rp_filter = 2
net.ipv4.conf.default.rp_filter = 2
EOF
sysctl --system

# ───────────────────────────────────────────────────────────────────────────────
# Libreswan 구성 (route-based, XFRMi, overlap 허용)
# ───────────────────────────────────────────────────────────────────────────────
cat >/etc/ipsec.conf <<EOF
config setup
  uniqueids=no
  # plutodebug=all

# ── Tunnel 1
conn aws-tunnel1
  auto=start
  type=tunnel
  ikev2=yes
  authby=secret
  keyingtries=%forever

  left=%defaultroute
  leftid="${LEFTID_FQDN_OR_IP}"
  right="${T1_OUTSIDE}"

  # 라우트 기반: 트래픽 선택자 0/0, 라우팅/BGP로 제어
  leftsubnet=0.0.0.0/0
  rightsubnet=0.0.0.0/0

  ipsec-interface=1
  overlapip=yes

  # IKE/ESP (AWS VGW와 호환)
  ike=aes256-sha2;modp2048
  esp=aes256-sha2_256
  pfs=yes
  ikelifetime=28800s
  salifetime=3600s

  # 장애감지/조각화
  dpddelay=10
  dpdtimeout=30
  dpdaction=restart
  fragmentation=yes

# ── Tunnel 2
conn aws-tunnel2
  auto=start
  type=tunnel
  ikev2=yes
  authby=secret
  keyingtries=%forever

  left=%defaultroute
  leftid="${LEFTID_FQDN_OR_IP}"
  right="${T2_OUTSIDE}"

  leftsubnet=0.0.0.0/0
  rightsubnet=0.0.0.0/0

  ipsec-interface=2
  overlapip=yes

  ike=aes256-sha2;modp2048
  esp=aes256-sha2_256
  pfs=yes
  ikelifetime=28800s
  salifetime=3600s
  dpddelay=10
  dpdtimeout=30
  dpdaction=restart
  fragmentation=yes
EOF

dos2unix /etc/ipsec.conf >/dev/null 2>&1 || true

# PSK: 로컬ID + 원격 OutsideIP 매핑(인증 오류 방지)
cat >/etc/ipsec.secrets <<EOF
"${LEFTID_FQDN_OR_IP}" "${T1_OUTSIDE}" : PSK "${PSK_T1}"
"${LEFTID_FQDN_OR_IP}" "${T2_OUTSIDE}" : PSK "${PSK_T2}"
EOF
chmod 600 /etc/ipsec.secrets
dos2unix /etc/ipsec.secrets >/dev/null 2>&1 || true

# ───────────────────────────────────────────────────────────────────────────────
# XFRMi Inside IP 자동 부여 (ipsec1/ipsec2 생성 후 주소 할당)
# ───────────────────────────────────────────────────────────────────────────────
cat >/etc/sysconfig/ipsec-ifup <<EOF
T1_CGW_INSIDE="${T1_CGW_INSIDE}"
T2_CGW_INSIDE="${T2_CGW_INSIDE}"
EOF

# 실행 시(EnvironmentFile 경유) 변수로부터 /30 CIDR을 구성하여 사용
cat >/usr/local/sbin/ipsec-ifup.sh <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
T1_IF="ipsec1"; T2_IF="ipsec2"

# EnvironmentFile(/etc/sysconfig/ipsec-ifup)에서 넘어온 값을 사용
T1_CIDR="${T1_CGW_INSIDE}/30"
T2_CIDR="${T2_CGW_INSIDE}/30"

wait_if() {
  local ifname="$1"
  for i in {1..60}; do
    ip link show "$ifname" &>/dev/null && return 0
    sleep 1
  done
  echo "WARN: interface $ifname not found" >&2
  return 1
}

if wait_if "$T1_IF"; then
  ip link set "$T1_IF" up || true
  ip addr show dev "$T1_IF" | grep -q "$T1_CGW_INSIDE" || ip addr add "$T1_CIDR" dev "$T1_IF"
  sysctl -w "net.ipv4.conf.${T1_IF}.rp_filter=0" >/dev/null || true
fi
if wait_if "$T2_IF"; then
  ip link set "$T2_IF" up || true
  ip addr show dev "$T2_IF" | grep -q "$T2_CGW_INSIDE" || ip addr add "$T2_CIDR" dev "$T2_IF"
  sysctl -w "net.ipv4.conf.${T2_IF}.rp_filter=0" >/dev/null || true
fi
exit 0
EOF
chmod +x /usr/local/sbin/ipsec-ifup.sh

cat >/etc/systemd/system/ipsec-ifup.service <<'EOF'
[Unit]
Description=Assign Inside IPs to ipsec1/ipsec2 (XFRMi)
After=network-online.target ipsec.service
Wants=network-online.target
Requires=ipsec.service
[Service]
Type=oneshot
EnvironmentFile=/etc/sysconfig/ipsec-ifup
ExecStart=/usr/local/sbin/ipsec-ifup.sh
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl restart ipsec
systemctl enable --now ipsec-ifup

# ───────────────────────────────────────────────────────────────────────────────
# FRR(BGP) 설정 — 두 터널 이웃 + ECMP
# (변수 치환 필요하므로 unquoted heredoc 사용)
# ───────────────────────────────────────────────────────────────────────────────

sed -i 's/^bgpd=no/bgpd=yes/' /etc/frr/daemons || true
sed -i 's/^zebra=no/zebra=yes/' /etc/frr/daemons || true

cat >/etc/frr/frr.conf <<EOF
frr defaults traditional
hostname vpn-cgw
service integrated-vtysh-config
!
ip route ${ADVERTISE_CIDR} Null0
!
router bgp ${ASN_CGW}
 bgp log-neighbor-changes
 timers bgp 3 9
 no bgp ebgp-requires-policy

 neighbor ${T1_AWS_INSIDE} remote-as ${ASN_AWS}
 neighbor ${T1_AWS_INSIDE} description AWS-TUN1
 neighbor ${T1_AWS_INSIDE} update-source ipsec1

 neighbor ${T2_AWS_INSIDE} remote-as ${ASN_AWS}
 neighbor ${T2_AWS_INSIDE} description AWS-TUN2
 neighbor ${T2_AWS_INSIDE} update-source ipsec2

 address-family ipv4 unicast
  network ${ADVERTISE_CIDR}
  maximum-paths 2
  neighbor ${T1_AWS_INSIDE} activate
  neighbor ${T2_AWS_INSIDE} activate
 exit-address-family
!
line vty
EOF

chown frr:frr /etc/frr/frr.conf || true
systemctl restart frr

systemctl restart NetworkManager || true
nmcli con reload || true
DEV=$(ip route show default | awk '{print $5}')
CONN=$(nmcli -t -f NAME,DEVICE con show | awk -F: -v d="$DEV" '$2==d{print $1; exit}')
# 영구 반영
nmcli con mod "$CONN" +ipv4.routes "${IDC_STATIC_SUBNET} ${IDC_LAN_GW}"
nmcli con up "$CONN" || true
# 즉시 커널 라우트도 반영(재실행 안전)
ip route replace "${IDC_STATIC_SUBNET}" via "${IDC_LAN_GW}" dev "$DEV"


# ───────────────────────────────────────────────────────────────────────────────
# 방화벽 — IPsec + ESP + BGP(인터페이스) + MSS + 양방향 포워딩(웹/DB/그외 트래픽 포함)
#  (교정 패치: eth0 하드코딩 제거, LAN_IF 자동 탐지, iptables-restore 비활성)
# ───────────────────────────────────────────────────────────────────────────────

# LAN 인터페이스/존 자동 탐지
LAN_IF="$(ip route show default 0.0.0.0/0 | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}')"
LAN_ZONE="$(firewall-cmd --get-zone-of-interface=${LAN_IF})"
[ -n "$LAN_IF" ] && [ -n "$LAN_ZONE" ] || { echo "LAN detect failed"; exit 1; }
echo "Detected LAN_IF=${LAN_IF}, LAN_ZONE=${LAN_ZONE}"

# ipsec 인터페이스는 trusted 존에, LAN 인터페이스는 기존 존 유지(= ${LAN_ZONE})
firewall-cmd --permanent --zone=trusted --add-interface=ipsec1
firewall-cmd --permanent --zone=trusted --add-interface=ipsec2

# IKE/NAT-T/ESP
firewall-cmd --permanent --add-service=ipsec
firewall-cmd --permanent --add-port=500/udp
firewall-cmd --permanent --add-port=4500/udp
firewall-cmd --permanent --add-rich-rule="rule family='ipv4' source address='${T1_OUTSIDE}' protocol value='50' accept"
firewall-cmd --permanent --add-rich-rule="rule family='ipv4' source address='${T2_OUTSIDE}' protocol value='50' accept"

# BGP(179/TCP) — ipsec 인터페이스에서만, 소스는 AWS Inside IP
firewall-cmd --permanent --direct \
  --add-rule ipv4 filter INPUT -300 -i ipsec1 -p tcp -s ${T1_AWS_INSIDE} --dport 179 -j ACCEPT
firewall-cmd --permanent --direct \
  --add-rule ipv4 filter INPUT -300 -i ipsec2 -p tcp -s ${T2_AWS_INSIDE} --dport 179 -j ACCEPT

# 상태기반 우선 허용(안전망)
firewall-cmd --permanent --direct \
  --add-rule ipv4 filter FORWARD -350 -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT

# 🔐 firewalld Policy로 trusted(=ipsec) ↔ LAN(${LAN_ZONE}) 포워딩 허용
# (idempotent: 동일 이름 정책 있으면 먼저 삭제)
firewall-cmd --permanent --delete-policy vpn-to-lan 2>/dev/null || true
firewall-cmd --permanent --delete-policy lan-to-vpn 2>/dev/null || true

# VPN → LAN
firewall-cmd --permanent --new-policy vpn-to-lan
firewall-cmd --permanent --policy vpn-to-lan --set-priority -100
firewall-cmd --permanent --policy vpn-to-lan --add-ingress-zone=trusted
firewall-cmd --permanent --policy vpn-to-lan --add-egress-zone=${LAN_ZONE}
firewall-cmd --permanent --policy vpn-to-lan \
  --add-rich-rule="rule family=ipv4 source address=${AWS_VPC_CIDR} destination address=${ADVERTISE_CIDR} accept" \
  --add-rich-rule="rule family=ipv4 source address=10.3.0.0/16 destination address=${ADVERTISE_CIDR} accept" \
  --add-rich-rule="rule family=ipv4 source address=10.4.0.0/16 destination address=${ADVERTISE_CIDR} accept"
firewall-cmd --permanent --policy vpn-to-lan --set-target=ACCEPT

# LAN → VPN
firewall-cmd --permanent --new-policy lan-to-vpn
firewall-cmd --permanent --policy lan-to-vpn --set-priority -100
firewall-cmd --permanent --policy lan-to-vpn --add-ingress-zone=${LAN_ZONE}
firewall-cmd --permanent --policy lan-to-vpn --add-egress-zone=trusted
firewall-cmd --permanent --policy lan-to-vpn \
  --add-rich-rule="rule family=ipv4 source address=${ADVERTISE_CIDR} destination address=${AWS_VPC_CIDR} accept" \
  --add-rich-rule="rule family=ipv4 source address=${ADVERTISE_CIDR} destination address=10.3.0.0/16 accept" \
  --add-rich-rule="rule family=ipv4 source address=${ADVERTISE_CIDR} destination address=10.4.0.0/16 accept"

# 커널 포워딩 재확인 + rp_filter(비대칭 경로 방지)
firewall-cmd --permanent --add-forward
cat >/etc/sysctl.d/98-vpn-rpf.conf <<EOF
net.ipv4.conf.${LAN_IF}.rp_filter = 0
EOF
sysctl --system

# MSS Clamping (firewalld direct)
firewall-cmd --permanent --direct \
  --add-rule ipv4 mangle FORWARD 0 -o ipsec1 -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --set-mss ${TCP_MSS}
firewall-cmd --permanent --direct \
  --add-rule ipv4 mangle FORWARD 0 -o ipsec2 -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --set-mss ${TCP_MSS}

# 적용/점검
firewall-cmd --reload
systemctl restart firewalld


# ───────────────────────────────────────────────────────────────────────────────
# 최종 점검 출력
# ───────────────────────────────────────────────────────────────────────────────
echo "== Quick status =="
ipsec status || true
ip -br addr show ipsec1 || true
ip -br addr show ipsec2 || true
(command -v vtysh >/dev/null && vtysh -c "show ip bgp summary") || true
ip -4 route
echo "== UserData completed =="