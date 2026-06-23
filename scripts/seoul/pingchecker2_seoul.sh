#!/bin/bash
HOST_TO_CHECK=10.2.1.100
#q : quit
#c : count
#W : 응답대기시간

if ping -qc 20 -W 1 $HOST_TO_CHECK >/dev/null; then
    echo "IDC-DB $HOST_TO_CHECK is up"
    systemctl start httpd
else
    echo "IDC-DB $HOST_TO_CHECK is down"
    systemctl stop httpd
fi