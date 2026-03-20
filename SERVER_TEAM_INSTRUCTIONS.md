# RabbitMQ & Queue Consumer Setup for Magento — Server Team Instructions

This document covers everything needed to install, configure, and maintain RabbitMQ and two persistent queue consumer processes for the Magento application. All commands are copy-paste ready.

---

## 1 — Check Existing Installation

Before installing anything, check if RabbitMQ is already present:

```bash
rabbitmqctl status
rabbitmqctl list_vhosts
rabbitmqctl list_users
```

If RabbitMQ is already installed with a `/magento` vhost and a `magento` user, skip to **Section 4**.

---

## 2 — Install RabbitMQ (if not installed)

### Amazon Linux 2 / Amazon Linux 2023

```bash
# Install Erlang (RabbitMQ dependency)
sudo amazon-linux-extras install erlang -y    # AL2
# OR for AL2023:
sudo dnf install erlang -y

# Install RabbitMQ
sudo yum install rabbitmq-server -y           # AL2
# OR for AL2023:
sudo dnf install rabbitmq-server -y

# Enable and start
sudo systemctl enable rabbitmq-server
sudo systemctl start rabbitmq-server

# Enable management plugin (web UI on port 15672)
sudo rabbitmq-plugins enable rabbitmq_management

# Verify
sudo systemctl status rabbitmq-server
```

### Ubuntu 22.04 / 24.04

```bash
# Install Erlang and RabbitMQ
sudo apt-get update
sudo apt-get install -y erlang-base erlang-nox rabbitmq-server

# Enable and start
sudo systemctl enable rabbitmq-server
sudo systemctl start rabbitmq-server

# Enable management plugin
sudo rabbitmq-plugins enable rabbitmq_management

# Verify
sudo systemctl status rabbitmq-server
```

> For the latest installation method, see https://www.rabbitmq.com/docs/install-debian (Ubuntu) or https://www.rabbitmq.com/docs/install-rpm (Amazon Linux).

---

## 3 — Configure RabbitMQ for Magento

```bash
# Create virtual host for Magento
sudo rabbitmqctl add_vhost /magento

# Create dedicated user (CHANGE THE PASSWORD!)
sudo rabbitmqctl add_user magento 'CHANGE_THIS_PASSWORD'

# Set permissions on the vhost
sudo rabbitmqctl set_permissions -p /magento magento ".*" ".*" ".*"

# Grant management UI access (optional, useful for monitoring)
sudo rabbitmqctl set_user_tags magento management

# Verify setup
sudo rabbitmqctl list_users
sudo rabbitmqctl list_vhosts
sudo rabbitmqctl list_permissions -p /magento
```

**Share these values with the Magento developer:**

| Setting       | Value                                            |
|---------------|--------------------------------------------------|
| Host          | `localhost` (or the RabbitMQ server's IP if on a separate machine) |
| Port          | `5672`                                           |
| User          | `magento`                                        |
| Password      | (whatever you set above)                         |
| Virtual Host  | `/magento`                                       |

---

## 4 — Magento Configuration

The Magento developer needs to add the following to the application's configuration file (`app/etc/env.php`). Share the connection values from Section 3:

```php
'queue' => [
    'amqp' => [
        'host' => 'localhost',
        'port' => '5672',
        'user' => 'magento',
        'password' => 'CHANGE_THIS_PASSWORD',
        'virtualhost' => '/magento',
        'ssl' => ''
    ]
],
```

---

## 5 — Persistent Queue Consumers (Systemd)

The Magento application requires **two queue consumer processes running at all times**. These processes read messages from RabbitMQ and send data to an external email marketing service. If they are not running, events will queue up in RabbitMQ and not be delivered.

Create two systemd unit files:

### File: `/etc/systemd/system/magento-event-trigger.service`

```ini
[Unit]
Description=Magento Event Trigger Consumer
After=rabbitmq-server.service network.target
Requires=rabbitmq-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/magento
ExecStart=/usr/bin/php bin/magento queue:consumers:start event.trigger.consumer
Restart=always
RestartSec=10
StandardOutput=append:/var/log/magento/event-trigger-consumer.log
StandardError=append:/var/log/magento/event-trigger-consumer-error.log

[Install]
WantedBy=multi-user.target
```

### File: `/etc/systemd/system/magento-event-retry.service`

```ini
[Unit]
Description=Magento Event Retry Consumer
After=rabbitmq-server.service network.target
Requires=rabbitmq-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/magento
ExecStart=/usr/bin/php bin/magento queue:consumers:start event.retry.consumer
Restart=always
RestartSec=10
StandardOutput=append:/var/log/magento/event-retry-consumer.log
StandardError=append:/var/log/magento/event-retry-consumer-error.log

[Install]
WantedBy=multi-user.target
```

### Values to adjust for your server

| Setting            | What to check                                                        |
|--------------------|----------------------------------------------------------------------|
| `User` and `Group` | The web server user (e.g., `www-data`, `nginx`, `apache`)            |
| `WorkingDirectory` | The Magento root directory path on your server                       |
| `ExecStart`        | Verify PHP path with `which php` (may be `/usr/local/bin/php`, etc.) |

### Enable and start

```bash
# Create log directory
sudo mkdir -p /var/log/magento
sudo chown www-data:www-data /var/log/magento

# Reload systemd, enable, and start
sudo systemctl daemon-reload
sudo systemctl enable magento-event-trigger magento-event-retry
sudo systemctl start magento-event-trigger magento-event-retry

# Verify both are running
sudo systemctl status magento-event-trigger
sudo systemctl status magento-event-retry
```

---

## 6 — Firewall Rules

### If RabbitMQ runs on the SAME server as Magento

No firewall rules needed for port 5672 — traffic stays on localhost.

### If RabbitMQ runs on a SEPARATE server

```bash
# Allow AMQP from the Magento server only
sudo ufw allow from MAGENTO_SERVER_IP to any port 5672 comment "RabbitMQ AMQP"
```

### Management UI access (all setups)

```bash
# Restrict management UI to admin IPs only
sudo ufw allow from YOUR_ADMIN_IP to any port 15672 comment "RabbitMQ Management UI"
```

### If using AWS Security Groups instead of ufw

- Allow TCP `5672` from the Magento server's security group
- Allow TCP `15672` from your office IP only

---

## 7 — Health Monitoring

### Daily health check commands

```bash
# Check RabbitMQ is running
sudo rabbitmqctl status | head -5

# Check queue depths (should be 0 or low under normal operation)
sudo rabbitmqctl list_queues -p /magento name messages consumers

# Check consumer processes are running
sudo systemctl is-active magento-event-trigger
sudo systemctl is-active magento-event-retry

# View recent consumer logs
tail -20 /var/log/magento/event-trigger-consumer.log
tail -20 /var/log/magento/event-trigger-consumer-error.log
```

### Alert conditions (set up monitoring for these)

- RabbitMQ service is not running
- Either consumer service is not active (check with `systemctl is-active`)
- Queue depth exceeds 100 messages (events are backing up — consumer may have crashed)
- Consumer error log has new entries

### If consumers crash

```bash
# Restart both consumers
sudo systemctl restart magento-event-trigger magento-event-retry

# Check if they started successfully
sudo systemctl status magento-event-trigger
sudo systemctl status magento-event-retry
```

---

## 8 — Log Rotation

Prevent consumer logs from growing unbounded.

### File: `/etc/logrotate.d/magento-consumers`

```
/var/log/magento/event-*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```
