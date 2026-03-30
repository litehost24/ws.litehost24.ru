# AWG Backhaul Checkpoint - 2026-03-25

Статус:
- этап `AWG backhaul + UDP -> relay + fail-open fallback` реализован в bootstrap-скриптах
- тестовая обкатка завершена успешно
- боевые серверы еще не менялись

Измененные локальные файлы:
- [server1_bootstrap_awg_xray_api_v04.sh](/home/ser/projects/app1/site/vpn-agent-mtls/server1_bootstrap_awg_xray_api_v04.sh)
- [server2_bootstrap_relay_backhaul_v01.sh](/home/ser/projects/app1/site/vpn-agent-mtls/server2_bootstrap_relay_backhaul_v01.sh)

Ключевые изменения:
- `front`:
  - `RELAY_BACKHAUL_TRANSPORT=awg|wg`
  - `RELAY_BACKHAUL_UDP_ENABLE=1`
  - `SKIP_XRAY=1`, `SKIP_VPN_AGENT=1`
  - `AWG` backhaul и `UDP` policy-routing с автоочисткой при смерти backhaul
  - исправлен guard runtime для `AWG_BLOCK_QUIC`
- `relay`:
  - `AWG` backhaul вместо обязательного plain WG
  - egress/NAT сервис работает поверх `AWG`
  - исправлен autodetect `WAN_IF`

Тестовые узлы:
- `85.143.219.174` как `front`
- `85.143.220.253` как `relay`

Что проверено:
- межсерверный `AWG` backhaul поднялся
- `latest handshake` есть на обеих сторонах
- `UDP -> relay` реально проходит
- при падении backhaul UDP уходит через локальный NAT front-node

Подтвержденные счетчики теста:
- режим `UDP -> relay`:
  - front `AWG_RELAY_UDP_V4`: `+3 packets / +87 bytes`
  - relay `POSTROUTING MASQUERADE 10.66.66.0/24`: `+3 / +87`
- режим fallback:
  - front локальный `POSTROUTING MASQUERADE 10.66.66.0/24`: `+3 / +87`
  - relay счетчик по `10.66.66.0/24` не вырос

Состояние после cleanup:
- временный test peer удален
- временный relay `netns` удален
- тестовые сервисы оставлены рабочими

Что делать дальше:
1. Не трогать `TCP/Xray` на проде на этом шаге.
2. Перенести патченные bootstrap-скрипты на боевые узлы.
3. На проде сначала поднять новый `AWG backhaul`.
4. Проверить handshake и сервисы.
5. Включить `UDP -> relay`.
6. Отдельно проверить fallback, временно убив backhaul.

## Обновление По Продy

Статус:
- staged rollout на прод уже выполнен частично и активен
- полный in-place swap старого `WG` не делался
- выбран безопасный режим:
  - старый `WG` оставлен для текущего IPv6/manual path
  - новый `AWG` введён для `IPv4 UDP -> relay`
  - `TCP/Xray` не менялся

Боевые узлы:
- front: `84.23.55.167`
- relay: `45.94.47.139`

Новый параллельный backhaul:
- интерфейс: `awg6backhaul`
- порт: `51822/udp`
- front addresses: `172.31.254.1/30`, `fd45:94:46::1/64`
- relay addresses: `172.31.254.2/30`, `fd45:94:46::2/64`

Что включено на проде:
- front `84.23.55.167`
  - `awg-quick@awg6backhaul.service`
  - `/usr/local/sbin/relay-udp-awg4-policy`
  - `relay-udp-awg4-policy.timer`
  - `ip rule`: `fwmark 0x66 -> table 51822`
  - `table 51822`: `default dev awg6backhaul`
  - chain `AWG_RELAY_UDP_V4`
- relay `45.94.47.139`
  - `awg-quick@awg6backhaul.service`
  - `relay-awg6backhaul-v4-route.service`
  - route `10.66.66.0/24 dev awg6backhaul`

Что осталось как было:
- front `xray`
- front `dnsmasq`
- старый `wg6backhaul`
- старый IPv6 source-policy через table `100`
- блок `udp/443` в `AWG_GUARD`

Продовый smoke-тест:
- временный peer `relay-udp-prod-smoke`
- временный relay `netns`
- временная маркировка только для одного `/32`
- подтверждение:
  - front test-chain: `+3 packets / +87 bytes`
  - relay `MASQUERADE 10.66.66.0/24` вырос
- весь временный тестовый мусор после этого удалён

Текущее состояние:
- front:
  - `wg-quick@wg6backhaul` active
  - `awg-quick@awg6backhaul` active
  - `relay-udp-awg4-policy.timer` active
  - `relay-udp-awg4-policy.service` одноразовый, штатно `inactive`
  - `xray` active
  - `dnsmasq` active
- relay:
  - `wg-quick@wg6backhaul` active
  - `awg-quick@awg6backhaul` active
  - `relay-awg6backhaul-v4-route.service` active

Финальная проверка после включения:
- front `84.23.55.167`
  - `ip rule`: `18522: from all fwmark 0x66 lookup 51822`
  - `table 51822`: `default dev awg6backhaul`
  - `AWG_RELAY_UDP_V4` живой и считает трафик:
    - `MARK`: `157 packets / 156 KiB`
  - `awg6backhaul latest handshake`: `6 seconds ago`
  - watchdog-script переписан на ping-проверку `172.31.254.2` через `awg6backhaul`, а не на `latest-handshakes`
- relay `45.94.47.139`
  - маршрут `10.66.66.0/24 dev awg6backhaul`
  - `POSTROUTING MASQUERADE 10.66.66.0/24` уже считает боевой трафик:
    - `4038 packets / 279 KiB`
  - `awg6backhaul latest handshake`: `6 seconds ago`

Следующий шаг:
1. Наблюдать боевую работу IPv4 UDP relay.
2. Если всё стабильно, отдельно мигрировать IPv6 path со старого `WG` на `AWG`.
3. Отдельно решить, снимать ли `udp/443` блок для QUIC/HTTP3.

Важно:
- пароли тестовых серверов и любые секреты в этот файл не записывались
- если продолжать работу после обрыва, сначала свериться с memory note:
  - `/home/ser/.codex/memories/app1_vpn_awg_backhaul_checkpoint_20260325.md`

## QUIC Возвращен В Блок

Статус:
- на front `84.23.55.167` `udp/443` снова блокируется
- `/etc/default/awg-guard` возвращен к `AWG_BLOCK_QUIC=1`
- локальный bootstrap тоже возвращен к дефолту `AWG_BLOCK_QUIC=1`
- `TCP/Xray`, старый `WG` и `UDP -> relay` policy не менялись

Проверка после возврата:
- в `AWG_GUARD` снова есть правило `-p udp --dport 443 -j DROP`
- `ip rule 18522 -> table 51822` остается на месте
- `AWG_RELAY_UDP_V4` продолжает маркировать клиентский IPv4 UDP

## Продовый Перевод IPv6 Path На AWG

Статус:
- старый `IPv6/manual` path переведен на новую `AWG` ветку
- старый `WG` не удален и оставлен как fallback

Что изменено на front `84.23.55.167`:
- в `/etc/amnezia/amneziawg/awg6backhaul.conf` peer `AllowedIPs` расширен:
  - было: `0.0.0.0/0`
  - стало: `0.0.0.0/0, ::/0`
- установлен runtime policy:
  - `/usr/local/sbin/relay-ipv6-backhaul-policy`
  - `relay-ipv6-backhaul-policy.service`
  - `relay-ipv6-backhaul-policy.timer`
- логика:
  - если `AWG` жив, `table 100` получает `default dev awg6backhaul`
  - если `AWG` мертв, fallback на `default dev wg6backhaul`

Что изменено на relay `45.94.47.139`:
- в `/etc/amnezia/amneziawg/awg6backhaul.conf` peer `AllowedIPs` расширен:
  - добавлен `fd66:66:66::/64`
- установлен runtime route policy:
  - `/usr/local/sbin/relay-awg6backhaul-v6-route`
  - `relay-awg6backhaul-v6-route.service`
  - `relay-awg6backhaul-v6-route.timer`
- логика:
  - если `AWG` жив, маршрут `fd66:66:66::/64 dev awg6backhaul`
  - если `AWG` мертв, fallback на `wg6backhaul`

Финальная проверка:
- front:
  - `ping -6 -I awg6backhaul fd45:94:46::2` успешен
  - `ip -6 route show table 100`:
    - `fd66:66:66::/64 dev awg0`
    - `default dev awg6backhaul`
  - `ip -6 route get 2606:4700:4700::1111 from fd66:66:66::157 iif awg0`
    - показывает `dev awg6backhaul table 100`
- relay:
  - `ping -6 -I awg6backhaul fd45:94:46::1` успешен
  - `ip -6 route show fd66:66:66::/64`
    - показывает `dev awg6backhaul`
  - `ip -6 route get 2606:4700:4700::1111 from fd66:66:66::157 iif awg6backhaul`
    - показывает выход `via 2a13:8c86:120::1 dev ens3`
  - NAT66 остается штатным:
    - `MASQUERADE fd66:66:66::/64 -> ens3`

Failover-поведение:
- если `awg6backhaul` жив, `table 100` использует `default dev awg6backhaul`
- если `AWG` мертв, но старый `WG` жив, `table 100` автоматически откатывается на `default dev wg6backhaul`
- если оба backhaul до `45.94.47.139` мертвы:
  - front теперь ставит `unreachable default` в `table 100`
  - relay теперь ставит `unreachable fd66:66:66::/64`
  - это сделано специально для fail-fast поведения, чтобы приложения быстрее скатывались на рабочий IPv4, а не висели на мертвом IPv6-path

## Временный Full-Relay Peer Для Теста WhatsApp

Статус:
- тест завершен
- оба временных peer удалены
- все временные full-relay rules и systemd units удалены
- локальные test configs удалены

Что включено на front `84.23.55.167`:
- все это уже удалено
- cleanup подтвержден:
  - rules `18523/18524` удалены
  - rules `11100/11101` удалены
  - `table 51823` очищена
  - chain `AWG_RELAY_FULLTEST_TCP` удалена
  - `relay-fulltest-peer.service` и `.timer` удалены
  - `awgctl list | grep wa-relay-fulltest` пустой

Результат теста:
- через эти два full-relay peer Telegram-звонки проходят
- через эти же full-relay peer WhatsApp-звонок не проходит
- вывод:
  - проблема уже не в split-routing `TCP/Xray + UDP/relay`
  - проблема уже не в базовой работоспособности `AWG/relay/UDP`
  - основной подозреваемый теперь не transport, а WhatsApp-specific поведение относительно текущего server egress/IP/ASN

## Локальные Bootstrap Скрипты Синхронизированы

Локальные файлы:
- `server1_bootstrap_awg_xray_api_v04.sh`
- `server2_bootstrap_relay_backhaul_v01.sh`

В них закреплено текущее боевое состояние:
- `AWG_BLOCK_QUIC=1` как дефолт
- для `transport=awg` дефолтный backhaul-интерфейс теперь `awg6backhaul`
- добавлены `RELAY_BACKHAUL_FALLBACK_IF` и `RELAY_BACKHAUL_FALLBACK_TRANSPORT`
- добавлен `RELAY_BACKHAUL_IPV6_FAIL_FAST=1` / `CLIENT_V6_FAIL_FAST=1`
- front bootstrap теперь умеет fail-fast/fallback для IPv6 внутри `relay-backhaul-policy`
- relay bootstrap теперь ставит `relay-backhaul-v6-route.service/.timer` для поддержания IPv6 route/fallback

## Эксперимент: Прямые AWG-Клиенты На Relay `45.94.47.139`

Статус:
- эксперимент включен только runtime-настройкой на relay
- bootstrap-скрипты под это пока не менялись
- текущий backhaul `awg6backhaul/wg6backhaul` не затронут

Что поднято:
- новый интерфейс: `awg0`
- порт: `51824/udp`
- серверные адреса:
  - `10.77.77.1/24`
  - `fd77:77:77::1/64`
- клиентские подсети:
  - `10.77.77.0/24`
  - `fd77:77:77::/64`
- direct egress:
  - IPv4 `MASQUERADE` через `ens3`
  - IPv6 NAT66 `MASQUERADE` через `ens3`

AWG параметры интерфейса:
- `Jc=4`
- `Jmin=8`
- `Jmax=80`
- `S1=70`
- `S2=130`
- `H1=237897241`
- `H2=237897242`
- `H3=237897243`
- `H4=237897244`

Что создано на relay:
- конфиг интерфейса:
  - `/etc/amnezia/amneziawg/awg0.conf`
- ключи интерфейса:
  - `/etc/amnezia/amneziawg/awg0.key`
  - `/etc/amnezia/amneziawg/awg0.pub`
- каталог тестовых клиентов:
  - `/root/awg-direct-clients-20260325`
- cleanup-скрипт:
  - `/usr/local/sbin/relay-awg-direct-test-cleanup`
- временные вспомогательные скрипты:
  - `/root/relay_awg_direct_setup_20260325.sh`
  - `/root/relay_awg_direct_cleanup_20260325.sh`

Что сгенерировано:
- `5` тестовых direct-client конфигов:
  - `relay-direct-test-01.conf`
  - `relay-direct-test-02.conf`
  - `relay-direct-test-03.conf`
  - `relay-direct-test-04.conf`
  - `relay-direct-test-05.conf`
- локальная копия конфигов:
  - `/tmp/relay-awg-direct-clients-20260325`

Проверка:
- `awg-quick@awg0.service` active
- `ip -br a show awg0`:
  - `10.77.77.1/24`
  - `fd77:77:77::1/64`
- `ss -lunp` слушает `51824/udp`
- `iptables -t nat -S POSTROUTING` содержит:
  - `-s 10.77.77.0/24 -o ens3 -j MASQUERADE`
- `ip6tables -t nat -S POSTROUTING` содержит:
  - `-s fd77:77:77::/64 -o ens3 -j MASQUERADE`
- route lookup:
  - `1.1.1.1 from 10.77.77.101 iif awg0 -> via 45.94.47.1 dev ens3`
  - `2606:4700:4700::1111 from fd77:77:77::101 iif awg0 -> via 2a13:8c86:120::1 dev ens3`

Как снести эксперимент:
- на relay выполнить:
  - `/usr/local/sbin/relay-awg-direct-test-cleanup`
- это остановит и отключит `awg-quick@awg0`, удалит `awg0`, конфиг/ключи интерфейса и каталог тестовых клиентов

## Новый Front-Node `90.156.215.5`

Статус:
- fresh bootstrap выполнен на новом сервере `90.156.215.5`
- использован `server1_bootstrap_awg_xray_api_v04.sh`
- установка сделана без `RELAY_BACKHAUL_ENABLE`, чтобы не лезть в multi-front backhaul на текущем relay

Доступ:
- SSH user: `ubuntu`
- локальный ключ:
  - `/home/ser/projects/app1/site/.deploy/90.156.215.5/ubuntu-STD2-1-1-10GB-8bwSOKnz.pem`

Что установлено:
- `awg0`
- `dnsmasq`
- `xray`
- `awgctl`
- `nginx` + `vpn-agent` с mTLS

Параметры:
- `SERVER_PUBLIC_IP=90.156.215.5`
- `WAN_IF=ens3`
- `VLESS_URI`:
  - `vless://29e94739-337e-4a42-83c0-9c912c0e512d@45.94.47.139:49720?type=tcp&encryption=none&security=reality&pbk=1X1dFJ_Nt8mAZafuVzTAlm1Z1NIA07R3kkyprFybK2Y&fp=chrome&sni=www.sony.com&sid=ec5a2ad641a2&spx=%2F&flow=xtls-rprx-vision#psb_fin-vk_2`
- `AWG` server pubkey:
  - `ztZlBOEUDvUthTX6fFZTu3NIYQqBaNPkziI2rOOVNzk=`

Проверка:
- `systemctl is-active`:
  - `awg-quick@awg0` active
  - `dnsmasq` active
  - `xray` active
  - `nginx` active
  - `vpn-agent` active
- `awg0`:
  - `10.66.66.1/24`
  - `ListenPort 51820`
- listeners:
  - `udp/51820`
  - `10.66.66.1:12345` (`xray`)
  - `127.0.0.1:9000` (`vpn-agent`)
  - `0.0.0.0:443` (`nginx`)
- health:
  - `curl http://127.0.0.1:9000/v1/health` -> `{"ok": true}`
  - mTLS `https://90.156.215.5/v1/health` -> `{"ok": true}`

mTLS артефакты:
- локальная копия:
  - `/home/ser/projects/app1/site/vpn-agent-mtls/node-90.156.215.5-mtls/ca.crt`
  - `/home/ser/projects/app1/site/vpn-agent-mtls/node-90.156.215.5-mtls/laravel-client.crt`
  - `/home/ser/projects/app1/site/vpn-agent-mtls/node-90.156.215.5-mtls/laravel-client.key`

Важно:
- на этой ноде пока нет relay backhaul/UDP relay
- сейчас это безопасный front-node с локальным UDP egress и `TCP -> Xray -> 45.94.47.139:49720`

## `45.94.47.139`: Managed AWG/API Поднят Параллельно

Статус:
- существующий `VLESS/x-ui`, `wg6backhaul`, `awg6backhaul` и экспериментальный direct `awg0` не тронуты
- выяснено, что экспериментальный `awg0` уже живой:
  - `6` peer в конфиге
  - минимум `5` peer с реальными handshake
  - трафик у части peer уже в гигабайтах
- поэтому сносить `awg0` первой нельзя

Что добавлено параллельно:
- новый managed direct-контур:
  - интерфейс: `awg1`
  - порт: `51820/udp`
  - IPv4: `10.78.78.1/24`
  - IPv6: `fd78:78:78::1/64`
  - клиентские сети:
    - `10.78.78.0/24`
    - `fd78:78:78::/64`
- новый API-слой:
  - `vpn-agent` на `127.0.0.1:9000`
  - `nginx` mTLS на `0.0.0.0:443`
  - `dnsmasq` слушает `10.78.78.1:53` и `fd78:78:78::1:53`

Параметры AWG `awg1`:
- `Jc=4`
- `Jmin=8`
- `Jmax=80`
- `S1=70`
- `S2=130`
- `H1=237897251`
- `H2=237897252`
- `H3=237897253`
- `H4=237897254`
- public key:
  - `ShlM4ABQBGyviTLwg12Q8rHtMgjCLL7e1hX1hVuDTFQ=`

Что проверено после развёртывания:
- сервисы:
  - `x-ui` active
  - `awg-quick@awg0` active
  - `awg-quick@awg1` active
  - `awg-quick@awg6backhaul` active
  - `wg-quick@wg6backhaul` active
  - `dnsmasq` active
  - `nginx` active
  - `vpn-agent` active
- health:
  - `curl http://127.0.0.1:9000/v1/health` -> `{"ok": true}`
  - mTLS `https://45.94.47.139/v1/health` -> `{"ok": true}`
- экспериментальный `awg0` после установки не просел:
  - свежие handshake у рабочих peer сохранились

Smoke-тест API:
- name:
  - `api-smoke-45-awg1-20260326`
- проверено:
  - `create`
  - `export-name`
  - `disable`
  - `enable`
  - финальный `disable`
- итог:
  - peer создан в `/var/lib/awgctl/db.json`
  - текущий статус peer:
    - `DISABLED`
  - выданный конфиг:
    - `10.78.78.2/32`
    - `fd78:78:78::2/128`
    - `Endpoint = 45.94.47.139:51820`

mTLS артефакты для сайта:
- локальная копия:
  - `/home/ser/projects/app1/site/vpn-agent-mtls/node-45.94.47.139-awg1-mtls/ca.crt`
  - `/home/ser/projects/app1/site/vpn-agent-mtls/node-45.94.47.139-awg1-mtls/laravel-client.crt`
  - `/home/ser/projects/app1/site/vpn-agent-mtls/node-45.94.47.139-awg1-mtls/laravel-client.key`

Вывод:
- `45.94.47.139` теперь уже можно рассматривать как managed `regular AWG/API` node
- старый экспериментальный `awg0` пока удалять нельзя, на нём живые клиенты
- следующая безопасная стадия:
  1. привязать локальный `regular` bundle сайта к этому новому managed-контуру
  2. прогнать локальный end-to-end
  3. только потом выводить это в прод для новых подписок

## Логи И Диск На Front-Nodes `2026-03-26`

На `84.23.55.167`:
- проблема была не в системе и не в данных приложения, а в логах
- до чистки:
  - `/` = `5.1G/8.7G` (`59%`)
  - `/var/log` = `~2.5G`
  - `syslog + syslog.1` = `~1.17G`
  - `journald` = `~940M`
  - `/var/log/xray` = `~141M`
- корневая причина:
  - `xray` без явного `log.access` писал access-lines в `syslog/journal`
- что сделано:
  - во всех `xray` JSON-конфигах выставлено `"access": "none"`
  - добавлен `/etc/systemd/journald.conf.d/90-codex-size.conf` с `SystemMaxUse=200M`
  - выполнены `systemctl restart xray` и `systemctl restart systemd-journald`
  - выполнены `journalctl --rotate` и `journalctl --vacuum-size=200M`
  - принудительно ротирован `rsyslog`, новый `syslog.1` сжат
- после чистки:
  - `/` = `3.3G/8.7G` (`38%`)
  - `/var/log` = `~649M`
  - `journald` = `~144M`
  - backup: `/root/logfix_20260326_095014`

На `90.156.215.5`:
- выполнена профилактика того же класса:
  - во всех `xray` JSON-конфигах выставлено `"access": "none"`
  - добавлен `/etc/systemd/journald.conf.d/90-codex-size.conf` с `SystemMaxUse=200M`
  - `xray` и `systemd-journald` перезапущены
- итог:
  - `/` = `2.7G/8.7G` (`32%`)
  - journald = `~26M`

Локальный bootstrap синхронизирован:
- в `server1_bootstrap_awg_xray_api_v04.sh` при `XRAY_ACCESS_LOG=0` теперь явно пишется `"access": "none"`
- в `server1_bootstrap_awg_xray_api_v04.sh` добавлен `JOURNALD_SYSTEM_MAX_USE` и создаётся `/etc/systemd/journald.conf.d/90-vpn-node.conf`

## Тестовый VLESS/REALITY Узел `85.143.220.175` `2026-03-26`

- на `85.143.220.175` установлен `3x-ui v2.8.11`
- `x-ui` panel:
  - port: `61943`
  - base path: `/tZNtiqQTcTmCcT1iwe/`
  - cert: `/root/cert/ip/fullchain.pem`
  - key: `/root/cert/ip/privkey.pem`
- self-signed cert для IP выпущен локально через `openssl` в `/root/cert/ip`
- добавлен один `VLESS + REALITY` inbound:
  - listen: `0.0.0.0:49720/tcp`
  - network: `tcp`
  - security: `reality`
  - target/sni: `www.sony.com:443`
  - flow: `xtls-rprx-vision`
  - tag: `inbound-49720`
- стартовый тестовый клиент в `x-ui`:
  - email: `seed-85-220-175`
- проверка после настройки:
  - `systemctl is-active x-ui` -> `active`
  - `ss -lntup` показывает `x-ui` на `61943` и `xray` на `49720`
  - `/usr/local/x-ui/bin/config.json` содержит ожидаемый `VLESS/REALITY` inbound
- стек на этом сервере сейчас отдельный и не интегрирован в сайт/панель проекта

## Тестовый Direct AWG + API Узел `85.143.220.175` `2026-03-26`

- создан отдельный bootstrap, не трогающий `server1`:
  - `/home/ser/projects/app1/site/vpn-agent-mtls/server3_bootstrap_awg_api_direct_v01.sh`
- на `85.143.220.175` этим bootstrap подняты:
  - `AmneziaWG` интерфейс `awg0`
  - `dnsmasq`
  - `iptables` NAT/firewall
  - `awgctl`
  - `vpn-agent` на `127.0.0.1:9000`
  - `nginx` mTLS API на `443/tcp`
- runtime-параметры тестовой direct-ноды:
  - `SERVER_PUBLIC_IP=85.143.220.175`
  - `AWG_ADDR=10.78.78.1/24`
  - `AWG_NET_CIDR=10.78.78.0/24`
  - `AWG_PORT=51820/udp`
  - `AWG_BLOCK_QUIC=0`
- `AWG` pubkey сервера:
  - `TeW5GNBdzg7kyfNcmRq9rnAqLZ5PWyxycz3l+qPTiXg=`
- mTLS-файлы стянуты локально:
  - `/home/ser/projects/app1/site/vpn-agent-mtls/node-85.143.220.175-mtls/ca.crt`
  - `/home/ser/projects/app1/site/vpn-agent-mtls/node-85.143.220.175-mtls/laravel-client.crt`
  - `/home/ser/projects/app1/site/vpn-agent-mtls/node-85.143.220.175-mtls/laravel-client.key`
- живые проверки после переприменения bootstrap:
  - `systemctl is-active x-ui awg-quick@awg0 dnsmasq vpn-agent nginx` -> всё `active`
  - `curl http://127.0.0.1:9000/v1/health` -> `{"ok": true}`
  - mTLS `https://85.143.220.175/v1/health` -> `{"ok": true}`
- mTLS API smoke-тест пройден:
  - `create` для `api-smoke-20260326` -> `10.78.78.2/32`
  - `export-name` вернул валидный `AWG` config с `Endpoint = 85.143.220.175:51820`
  - `disable` и `enable` отработали
- важный фикс в bootstrap:
  - при переприменении теперь делается `systemctl restart vpn-agent`, иначе оставался жить старый процесс и `health` мог врать

## Локальная Bundle-Конфигурация Сайта `2026-03-26`

Состояние локальной БД `site` на текущий момент:
- `project_settings`:
  - `vpn_bundle_white_ip_server_id = 1`
  - `vpn_bundle_regular_server_id = 3`
- строки `servers`:
  - `id=1`
    - `vpn_access_mode = white_ip`
    - legacy `ip1 = 194.5.79.26`
    - фактический `AWG/API` узел задаётся через `node1_api_url = https://84.23.55.167`
    - общий `VLESS` хвост:
      - `url2 = https://79.110.227.174:51406/6PvzVdSpu9xEmI4`
      - `username2 = dfsgw54JJijoi`
      - `webwasepath2 = 6PvzVdSpu9xEmI4`
  - `id=2`
    - тестовый `white_ip` bundle на `85.143.220.175`
    - в выдаче новых подписок сейчас не используется
  - `id=3`
    - `vpn_access_mode = regular`
    - `AWG/API` узел:
      - `node1_api_url = https://45.94.47.139`
      - `mTLS`:
        - `/var/www/html/vpn-agent-mtls/node-45.94.47.139-awg1-mtls/ca.crt`
        - `/var/www/html/vpn-agent-mtls/node-45.94.47.139-awg1-mtls/laravel-client.crt`
        - `/var/www/html/vpn-agent-mtls/node-45.94.47.139-awg1-mtls/laravel-client.key`
    - общий `VLESS` хвост:
      - `url2 = https://79.110.227.174:51406/6PvzVdSpu9xEmI4`
      - `username2 = dfsgw54JJijoi`
      - `webwasepath2 = 6PvzVdSpu9xEmI4`

Что уже работает локально:
- покупка новой подписки с чекбоксом `Нужен белый IP`
- явный выбор bundle через `ProjectSetting`, а не через “последнюю строку”
- переключение типа на карточке подписки без новой billing-записи
- старый `VLESS` fallback кнопка переключения не затрагивает

Критичный фикс для `45.94.47.139 regular`:
- проблема была в direct `AWG` конфиге:
  - `export-name` выдавал `PublicKey` не интерфейса `awg1`, а `awg6backhaul`
  - из-за этого клиент подключался “как будто”, но реального handshake на `awg1` не было и интернет не работал
- правильный `PublicKey` managed direct-ноды `45.94.47.139 awg1`:
  - `ShlM4ABQBGyviTLwg12Q8rHtMgjCLL7e1hX1hVuDTFQ=`
- неправильный ключ, который нельзя больше использовать в direct-конфигах:
  - `+2UkADMSvknaS6oMgGfwSRO0AMGMvAt5HPoLRaZOPXA=`
- на сервере runtime hotfix уже внесён:
  - `/usr/local/bin/awgctl` на `45.94.47.139` теперь берёт `public-key` именно от `awg1`
  - `export-name` переписывает `PublicKey` и `Endpoint` по текущему direct-интерфейсу
  - добавлен IPv6 NAT для `fd78:78:78::/64`

Фикс уже зафиксирован в bootstrap:
- `/home/ser/projects/app1/site/vpn-agent-mtls/server3_bootstrap_awg_api_direct_v01.sh`
  - `load_server_params()` теперь использует `awg show $AWG_IF public-key`
  - `rewrite_client_config()` теперь обновляет `PublicKey` и `Endpoint`
  - для direct IPv6 добавлен `ip6tables -t nat POSTROUTING MASQUERADE`

Быстрый старт после обрыва:
1. Проверить текущие bundle settings в локальной БД:
   - `vpn_bundle_white_ip_server_id`
   - `vpn_bundle_regular_server_id`
2. Для `regular` помнить:
   - это `server_id=3`
   - direct `AWG/API` идёт через `45.94.47.139 awg1`
   - `mTLS` папка именно `node-45.94.47.139-awg1-mtls`, не `node-45.94.47.139-mtls`
3. Если direct-конфиг снова “подключается, но без интернета”:
   - первым делом проверить `PublicKey` в клиентском `.conf`
   - он должен быть `ShlM4ABQBGyviTLwg12Q8rHtMgjCLL7e1hX1hVuDTFQ=`
